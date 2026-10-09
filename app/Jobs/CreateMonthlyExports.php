<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\Document;
use App\Models\InsuranceCompany;
use App\Models\MonthlyExportRun;
use App\Models\User;
use App\Services\CPDocumentService;
use App\Services\DZCDocumentService;
use App\Services\KilometersBatchDocumentService;
use App\Services\MonthlyExportResetService;
use App\Services\PointClaimSelectionService;
use App\Services\PointsBatchDocumentService;
use App\Services\Transport\ClaimExportService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateMonthlyExports implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;
    public int $tries = 1;

    public function __construct(public int $runId)
    {
    }

    public function handle(
        PointClaimSelectionService $pointSelection,
        PointsBatchDocumentService $pointDocuments,
        ClaimExportService $kilometerExport,
        KilometersBatchDocumentService $kilometerDocuments,
        DZCDocumentService $dzcDocuments,
        CPDocumentService $cpDocuments,
        MonthlyExportResetService $reset,
    ): void {
        $run = MonthlyExportRun::findOrFail($this->runId);
        $actor = User::findOrFail($run->user_id);
        $branch = Branch::findOrFail($run->branch_id);
        abort_unless($actor->isInBranch((int) $branch->id), 403);

        $month = CarbonImmutable::parse($run->month, 'Europe/Bratislava')->startOfMonth();
        $period = [$month->toDateString(), $month->endOfMonth()->toDateString()];
        $results = [];

        $run->update([
            'status' => 'processing',
            'current_step' => 'Pripravujem mesačné dávky',
            'results' => $results,
            'error_message' => null,
            'started_at' => now(),
            'completed_at' => null,
        ]);

        try {
            $reset->reset($actor->id, $branch->id, $month->format('Y-m'));

            $insurances = InsuranceCompany::query()->orderBy('id')->get(['id', 'name']);
            $regimes = [
                'N' => 'tuzemskí poistenci',
                'E' => 'poistenci EÚ',
                'I' => 'osobitní poistenci',
            ];

            foreach ($insurances as $insurance) {
                foreach ($regimes as $batchType => $regimeLabel) {
                    $baseData = [
                        'batchType' => ['code' => $batchType],
                        'insurance' => ['id' => (int) $insurance->id],
                        'branch' => ['id' => (int) $branch->id],
                        'period' => $period,
                    ];
                    $scopeLabel = $insurance->name . ' - ' . $regimeLabel;

                    $this->setStep($run, 'Výkonová dávka: ' . $scopeLabel, $results);
                    try {
                        $candidates = $pointSelection->candidates([
                            'batch_type' => $batchType,
                            'insurance_company_id' => (int) $insurance->id,
                            'branch_id' => (int) $branch->id,
                            'period' => $period,
                        ], $actor);

                        if ($candidates['existing_batch']) {
                            $results[] = $this->result(
                                'points',
                                $scopeLabel,
                                'existing',
                                (int) ($candidates['existing_batch']['document_id'] ?? 0) ?: null,
                                'Dávka už bola vytvorená.',
                                $insurance->name,
                                $regimeLabel,
                            );
                        } elseif (($candidates['summary']['points_count'] ?? 0) === 0) {
                            $results[] = $this->result('points', $scopeLabel, 'skipped', null, 'Bez výkonov na vykázanie.', $insurance->name, $regimeLabel);
                        } else {
                            [$document] = $pointDocuments->createPointsBatch($baseData, $actor);
                            $results[] = $this->result('points', $scopeLabel, 'created', $document->id, null, $insurance->name, $regimeLabel);
                        }
                    } catch (Throwable $error) {
                        $results[] = $this->result('points', $scopeLabel, 'failed', null, $this->errorMessage($error), $insurance->name, $regimeLabel);
                    }
                    $run->update(['results' => $results]);

                    $this->setStep($run, 'Dopravná dávka: ' . $scopeLabel, $results);
                    try {
                        $existing = Document::query()
                            ->where('type', 'kilometers_batch')
                            ->where('user_id', $actor->id)
                            ->where('branch_id', $branch->id)
                            ->where('insurance_company_id', $insurance->id)
                            ->where('period', $month->format('Y-m'))
                            ->where('subtype', $batchType)
                            ->first();

                        if ($existing) {
                            $results[] = $this->result('kilometers', $scopeLabel, 'existing', $existing->id, 'Dávka už bola vytvorená.', $insurance->name, $regimeLabel);
                        } else {
                            $prepared = $kilometerExport->prepare($baseData, $actor, true);
                            $selection = $prepared['selection'];

                            if ($selection['candidates'] === []) {
                                $results[] = $this->result('kilometers', $scopeLabel, 'skipped', null, 'Bez jázd na vykázanie.', $insurance->name, $regimeLabel);
                            } else {
                                $createData = $baseData + [
                                    'car_id' => (int) $selection['car']['id'],
                                    'journeyIds' => array_values(array_map(
                                        fn (array $candidate) => (int) $candidate['journey_id'],
                                        $selection['candidates'],
                                    )),
                                    'previewToken' => $selection['preview_token'],
                                ];
                                [$document] = $kilometerDocuments->createKilometersBatch($createData, $actor);
                                $results[] = $this->result('kilometers', $scopeLabel, 'created', $document->id, null, $insurance->name, $regimeLabel);
                            }
                        }
                    } catch (Throwable $error) {
                        $results[] = $this->result('kilometers', $scopeLabel, 'failed', null, $this->errorMessage($error), $insurance->name, $regimeLabel);
                    }
                    $run->update(['results' => $results]);
                }
            }

            $this->setStep($run, 'Denný záznam ciest', $results);
            $travelAvailable = false;
            try {
                [$document] = $dzcDocuments->createDzc([
                    'branch_id' => $branch->id,
                    'start' => $period[0],
                    'end' => $period[1],
                ], $actor);
                $travelAvailable = true;
                $results[] = $this->result('dzc', 'Denný záznam ciest', 'created', $document->id);
            } catch (ValidationException $error) {
                $results[] = $this->result('dzc', 'Denný záznam ciest', 'skipped', null, $this->errorMessage($error));
            } catch (Throwable $error) {
                $results[] = $this->result('dzc', 'Denný záznam ciest', 'failed', null, $this->errorMessage($error));
            }
            $run->update(['results' => $results]);

            $this->setStep($run, 'Cestovný príkaz', $results);
            if ($travelAvailable) {
                try {
                    [$document] = $cpDocuments->createCp([
                        'branch_id' => $branch->id,
                        'start' => $period[0],
                        'end' => $period[1],
                    ], $actor);
                    $results[] = $this->result('cp', 'Cestovný príkaz', 'created', $document->id);
                } catch (Throwable $error) {
                    $results[] = $this->result('cp', 'Cestovný príkaz', 'failed', null, $this->errorMessage($error));
                }
            } else {
                $results[] = $this->result('cp', 'Cestovný príkaz', 'skipped', null, 'Bez evidovaných ciest.');
            }

            $hasErrors = collect($results)->contains('status', 'failed');
            $run->update([
                'status' => $hasErrors ? 'completed_with_errors' : 'completed',
                'current_step' => null,
                'results' => $results,
                'completed_at' => now(),
            ]);
        } catch (Throwable $error) {
            $run->update([
                'status' => 'failed',
                'current_step' => null,
                'results' => $results,
                'error_message' => $this->errorMessage($error),
                'completed_at' => now(),
            ]);

            throw $error;
        }
    }

    private function setStep(MonthlyExportRun $run, string $step, array $results): void
    {
        $run->update(['current_step' => $step, 'results' => $results]);
    }

    private function result(
        string $type,
        string $label,
        string $status,
        ?int $documentId = null,
        ?string $message = null,
        ?string $insuranceCompany = null,
        ?string $subtype = null,
    ): array {
        return array_filter([
            'type' => $type,
            'label' => $label,
            'status' => $status,
            'document_id' => $documentId,
            'message' => $message,
            'insurance_company' => $insuranceCompany,
            'subtype' => $subtype,
        ], fn ($value) => $value !== null);
    }

    private function errorMessage(Throwable $error): string
    {
        if ($error instanceof ValidationException) {
            return collect($error->errors())->flatten()->first() ?? $error->getMessage();
        }

        return $error->getMessage();
    }

    public function failed(Throwable $error): void
    {
        MonthlyExportRun::query()->whereKey($this->runId)->update([
            'status' => 'failed',
            'current_step' => null,
            'error_message' => $this->errorMessage($error),
            'completed_at' => now(),
        ]);
    }
}
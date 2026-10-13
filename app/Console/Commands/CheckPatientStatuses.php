<?php

namespace App\Console\Commands;

use App\Models\Patient;
use App\Services\EoverenieInsuranceService;
use App\Services\PatientNotificationService;
use App\Services\UdzsDeathService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class CheckPatientStatuses extends Command
{
    protected $signature = 'patients:check-statuses {--patient= : Check only this patient ID}';

    protected $description = 'Check active patients for death and insurance status changes.';

    public function handle(
        UdzsDeathService $deathService,
        EoverenieInsuranceService $insuranceService,
        PatientNotificationService $notificationService,
    ): int {
        $checked = 0;
        $dead = 0;
        $insuranceIssues = 0;

        $query = Patient::query()
            ->whereNotNull('personal_number')
            ->with(['branch', 'latestCoverage.insuranceCompany']);

        $patientId = (int) $this->option('patient');

        if ($patientId > 0) {
            $query->whereKey($patientId);
        }

        $query->chunkById(50, function ($patients) use (
                $deathService,
                $insuranceService,
                $notificationService,
                &$checked,
                &$dead,
                &$insuranceIssues,
            ): void {
                foreach ($patients as $patient) {
                    if (! $patient instanceof Patient) {
                        continue;
                    }

                    $checked++;

                    try {
                        if (! $patient->death_date) {
                            $deathResult = $deathService->checkPersonalNumber(
                                (string) $patient->personal_number,
                            );

                            if (($deathResult['status'] ?? null) === 'dead') {
                                $deathDate = $this->deathDate($deathResult['data'] ?? []);

                                $patient->update([
                                    'death_date' => $deathDate,
                                ]);

                                $notificationService->resolveIssue(
                                    $patient,
                                    "patient:{$patient->id}:insurance",
                                );

                                $notificationService->createEvent(
                                    $patient,
                                    "patient:{$patient->id}:dead",
                                    'patient_dead',
                                    'Pacient je zosnulý',
                                    $this->patientName($patient) . ($deathDate
                                        ? ' - dátum úmrtia ' . Carbon::parse($deathDate)->format('d.m.Y')
                                        : ''),
                                    [
                                        'patient_id' => $patient->id,
                                        'death_date' => $deathDate,
                                    ],
                                );

                                $dead++;
                                continue;
                            }
                        } else {
                            $notificationService->resolveIssue(
                                $patient,
                                "patient:{$patient->id}:insurance",
                            );

                            $notificationService->createEvent(
                                $patient,
                                "patient:{$patient->id}:dead",
                                'patient_dead',
                                'Pacient je zosnulý',
                                $this->patientName($patient) . ' - dátum úmrtia '
                                    . Carbon::parse($patient->death_date)->format('d.m.Y'),
                                [
                                    'patient_id' => $patient->id,
                                    'death_date' => $patient->death_date,
                                ],
                            );

                            continue;
                        }

                        $coverage = $patient->latestCoverage;
                        $insuranceResult = $insuranceService->verify($patient, $coverage);
                        $status = $insuranceResult['status'] ?? 'unknown';
                        $issueKey = "patient:{$patient->id}:insurance";

                        if ($status === 'verified') {
                            $coverage?->update(['is_verified' => true]);
                            $notificationService->resolveIssue($patient, $issueKey);
                            continue;
                        }

                        if (! in_array($status, [
                            'mismatch',
                            'not_insured',
                            'duplicity',
                            'identity_mismatch',
                        ], true)) {
                            continue;
                        }

                        $coverage?->update(['is_verified' => false]);

                        $registeredCompany = $insuranceResult['registered_insurance_company'] ?? [];

                        if ($status === 'mismatch') {
                            $notificationService->resolveIssue($patient, $issueKey);

                            $companyIdentifier = $registeredCompany['code']
                                ?? $registeredCompany['id']
                                ?? md5(json_encode($registeredCompany));

                            $notificationService->createEvent(
                                $patient,
                                "patient:{$patient->id}:insurance-changed:{$companyIdentifier}",
                                'patient_insurance_changed',
                                'Pacient zmenil poisťovňu',
                                $this->patientName($patient) . ' - evidovaná poisťovňa: '
                                    . ($registeredCompany['name'] ?? 'neznáma'),
                                [
                                    'patient_id' => $patient->id,
                                    'registered_insurance_company' => $registeredCompany,
                                    'saved_insurance_company' => $insuranceResult['saved_insurance_company'] ?? null,
                                ],
                            );

                            $insuranceIssues++;
                            continue;
                        }

                        $notificationService->reportIssue(
                            $patient,
                            $issueKey,
                            'patient_insurance_issue',
                            'Problém s poistením pacienta',
                            $this->patientName($patient) . ' - poistný vzťah vyžaduje kontrolu',
                            [
                                'patient_id' => $patient->id,
                                'status' => $status,
                                'registered_insurance_company' => $registeredCompany,
                                'saved_insurance_company' => $insuranceResult['saved_insurance_company'] ?? null,
                            ],
                        );

                        $insuranceIssues++;
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });

        $this->info("Checked {$checked} patient(s); found {$dead} deceased and {$insuranceIssues} insurance issue(s).");

        return self::SUCCESS;
    }

    private function deathDate(array $data): ?string
    {
        $value = $data['datumUmrtia'] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function patientName(Patient $patient): string
    {
        return trim("{$patient->first_name} {$patient->last_name}");
    }
}

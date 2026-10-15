<?php

namespace App\Services;

use App\Models\DekurzAiFeedback;
use App\Models\Document;
use App\Models\Patient;
use App\Models\Branch;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Services\VisitsService;

class DekurzDocumentService
{
    public function create(array $data, $user): Document
    {
        $patient = Patient::findOrFail($data['patient_id']);

        $document = Document::create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'type' => 'dekurz',
            'mime_type' => 'application/json',
            'name' => 'dekurz_' . now()->format('d.m.Y'),
            'path' => 'dekurz/' . now()->timestamp . '.json',
            'period' => date('Y-m', strtotime($data['month'] ?? now()->format('Y-m-d'))),
            'branch_id' => $data['branch_id'] ?? null,
        ]);

        $sections = $this->normalizeSections($data['sections']);

        $month = $data['month'] ?? null;
        $dailyTexts = $this->buildDailyTexts($sections);
        $neededDates = array_keys($dailyTexts);

        if ($month) {
            $this->ensureMonthTimelineExistsOrCreate($month, (int) $data['branch_id'], (int) $user->id, (int) $patient->id, $neededDates);
        }

        $daysWithTimes = $this->attachVisitTimesForPatient($dailyTexts, (int) $patient->id, (int) $user->id, (int) $data['branch_id']);

        $branch = Branch::findOrFail((int) $data['branch_id']);
        $company = $branch->company;
        $insurance = $patient->insuranceCompany;

        $userName = trim((($user->title ?? '') . ' ' . ($user->first_name ?? '') . ' ' . ($user->last_name ?? '')));
        $companyName = $company ? $company->name : '';
        $companyAddress = $company ? $company->address : '' . ', ' . ($company->city ?? '');
        $insuranceCode = $insurance ? $insurance->branch_code : '';
        $patientAddress = trim(($patient->address ?? '') . ', ' . ($patient->city ?? '') . ', ' . ($patient->postal_code ?? ''));

        $dekurzData = [
            'schema_version' => 1,
            'document_id' => $document->id,
            'created_at' => now(),
            'user_id' => $user->id,
            'user_name' => $userName,
            'company_name' => $companyName,
            'company_address' => $companyAddress,
            'insurance_code' => $insuranceCode,
            'patient_personal_number' => $patient->personal_number,
            'patient_address' => $patientAddress,
            'patient_id' => $patient->id,
            'patient_name' => trim(($patient->first_name ?? '') . ' ' . ($patient->last_name ?? '')),
            'dekurz_number' => $data['dekurz_number'],
            'month' => $month,
            'sections' => $sections,
            'days' => $daysWithTimes,
        ];

        Storage::disk('local')->put('dekurz/' . now()->timestamp . '.json', json_encode($dekurzData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->storeAiFeedback($document, $patient, $user, $data, $sections);

        // Reserve the next dekurz number once. The printed document page will later
        // persist the last page number after pagination is known.
        DB::table('patients')
            ->where('id', $patient->id)
            ->update([
                'dekurz_number' => DB::raw("CAST(CAST(dekurz_number AS INTEGER) + 1 AS VARCHAR)"),
                'updated_at'    => now(),
            ]);
        $document->next_dekurz_number = (int) $patient->fresh()->dekurz_number;

        return $document;
    }

    private function storeAiFeedback(Document $document, Patient $patient, $user, array $data, array $finalSections): void
    {
        $feedback = $data['ai_feedback'] ?? null;
        if (!is_array($feedback)) {
            return;
        }

        $suggestedSections = collect($feedback['suggested_sections'] ?? [])
            ->map(function ($section) {
                return [
                    'text' => trim((string) ($section['text'] ?? '')),
                ];
            })
            ->filter(fn(array $section) => $section['text'] !== '')
            ->values()
            ->all();

        if (!count($suggestedSections)) {
            return;
        }

        $cleanFinalSections = collect($finalSections)
            ->map(function ($section) {
                return [
                    'text' => trim((string) ($section['text'] ?? '')),
                ];
            })
            ->filter(fn(array $section) => $section['text'] !== '')
            ->values()
            ->all();

        $suggestedText = $this->joinSectionTexts($suggestedSections);
        $finalText = $this->joinSectionTexts($cleanFinalSections);

        DekurzAiFeedback::create([
            'document_id' => $document->id,
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'branch_id' => $data['branch_id'] ?? null,
            'proposal_document_id' => $feedback['proposal_document_id'] ?? null,
            'source' => (string) ($feedback['source'] ?? 'proposal_ai_prefill'),
            'suggested_sections' => $suggestedSections,
            'final_sections' => $cleanFinalSections,
            'has_user_edits' => $this->normalizeForCompare($suggestedText) !== $this->normalizeForCompare($finalText),
            'suggestion_char_count' => mb_strlen($suggestedText),
            'final_char_count' => mb_strlen($finalText),
        ]);
    }

    private function joinSectionTexts(array $sections): string
    {
        return collect($sections)
            ->map(fn(array $section) => trim((string) ($section['text'] ?? '')))
            ->filter(fn(string $text) => $text !== '')
            ->values()
            ->implode("\n\n");
    }

    private function normalizeForCompare(string $text): string
    {
        $normalized = mb_strtolower(trim($text));

        return preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    }

    public function normalizeSections(array $sections): array
    {
        return collect($sections)->map(function ($s) {
            $dates = collect($s['dates'])
                ->map(fn($d) => date('Y-m-d', strtotime($d)))
                ->unique()
                ->sort()
                ->values()
                ->all();

            return [
                'text' => (string) $s['text'],
                'dates' => $dates,
            ];
        })->values()->all();
    }

    public function buildDailyTexts(array $sections): array
    {
        $byDate = [];
        foreach ($sections as $s) {
            $text = trim((string) ($s['text'] ?? ''));
            $dates = $s['dates'] ?? [];
            if ($text === '' || !is_array($dates) || !count($dates)) {
                continue;
            }
            foreach ($dates as $d) {
                $date = Carbon::parse($d)->toDateString();
                if (!isset($byDate[$date])) {
                    $byDate[$date] = $text;
                } else {
                    $byDate[$date] .= "\n\n" . $text;
                }
            }
        }

        foreach ($byDate as $date => $txt) {
            $byDate[$date] = preg_replace("/\n{3,}/", "\n\n", trim($txt));
        }

        ksort($byDate);
        return $byDate;
    }

    public function attachVisitTimesForPatient(array $dailyTexts, int $patientId, int $userId, int $branchId): array
    {
        if (!$branchId) {
            return array_map(fn($text, $date) => [
                'date' => $date,
                'text' => $text,
                'terrain_time' => null,
                'administrative_time' => null,
            ], $dailyTexts, array_keys($dailyTexts));
        }

        $dates = array_keys($dailyTexts);

        $rows = DB::table('visits')
            ->where('patient_id', $patientId)
            ->where('user_id', $userId)
            ->where('branch_id', $branchId)
            ->whereIn('date', $dates)
            ->select(['date', 'terrain_time', 'administrative_time'])
            ->get()
            ->keyBy('date');

        $out = [];
        foreach ($dailyTexts as $date => $text) {
            $row = $rows[$date] ?? null;
            $out[] = [
                'date' => $date,
                'text' => $text,
                'terrain_time' => $row->terrain_time ?? null,
                'administrative_time' => $row->administrative_time ?? null,
            ];
        }

        return $out;
    }

    public function ensureMonthTimelineExistsOrCreate(string $monthYmd, int $branchId, int $userId, int $patientId, array $neededDates): void
    {
        $neededDates = array_values(array_unique(array_filter($neededDates)));
        if (!count($neededDates)) {
            return;
        }

        $month = Carbon::parse($monthYmd)->setTimezone('Europe/Bratislava');

        $existingDates = DB::table('visits')
            ->where('user_id', $userId)
            ->where('branch_id', $branchId)
            ->where('patient_id', $patientId)
            ->whereIn('date', $neededDates)
            ->whereNotNull('terrain_time')
            ->whereNotNull('administrative_time')
            ->distinct()
            ->pluck('date')
            ->map(fn($d) => Carbon::parse($d)->toDateString())
            ->all();

        $missingDates = array_values(array_diff($neededDates, $existingDates));
        if (!count($missingDates)) {
            return;
        }

        // call the VisitsService directly instead of routing through controller
        $visitsService = app(VisitsService::class);
        $visitsService->requestTimeline([
            'month' => $month->toDateString(),
            'branch_id' => $branchId,
            'user_id' => $userId,
            'persist' => true,
        ]);
    }

    public function findDekurzFileForDocument(Document $document): ?array
    {
        // If document has explicit path, try that first.
        if ($document->path && Storage::disk('local')->exists($document->path)) {
            return json_decode(Storage::disk('local')->get($document->path), true);
        }

        // Search common locations where dekurz JSON files may be stored.
        $candidateDirs = ['dekurz', 'private/dekurz'];

        foreach ($candidateDirs as $dir) {
            if (!Storage::disk('local')->exists($dir)) {
                continue;
            }

            $files = Storage::disk('local')->allFiles($dir);
            foreach ($files as $file) {
                try {
                    $content = json_decode(Storage::disk('local')->get($file), true);
                } catch (\Throwable $e) {
                    continue;
                }

                if (!is_array($content)) {
                    continue;
                }

                if (($content['document_id'] ?? null) === $document->id) {
                    return $content;
                }

                // If the file doesn't contain a matching document_id, try matching by patient_id
                // This helps when the documents table row doesn't have a path or IDs are out-of-sync.
                if (($content['patient_id'] ?? null) === $document->patient_id) {
                    return $content;
                }
            }
        }

        return null;
    }

    /**
     * Find the latest dekurz JSON for a patient by scanning storage directories.
     */
    public function findLatestDekurzForPatient(int $patientId): ?array
    {
        $candidateDirs = ['dekurz', 'private/dekurz'];
        $candidates = [];

        foreach ($candidateDirs as $dir) {
            if (!Storage::disk('local')->exists($dir)) {
                continue;
            }

            $files = Storage::disk('local')->allFiles($dir);
            foreach ($files as $file) {
                try {
                    $content = json_decode(Storage::disk('local')->get($file), true);
                } catch (\Throwable $e) {
                    continue;
                }

                if (!is_array($content)) {
                    continue;
                }

                if (($content['patient_id'] ?? null) !== $patientId) {
                    continue;
                }

                $ts = null;
                if (!empty($content['created_at'])) {
                    try {
                        $ts = Carbon::parse($content['created_at'])->getTimestamp();
                    } catch (\Throwable $e) {
                        $ts = null;
                    }
                }

                if (!$ts) {
                    // fallback: use file modified time
                    try {
                        $path = Storage::disk('local')->path($file);
                        $ts = filemtime($path) ?: null;
                    } catch (\Throwable $e) {
                        $ts = null;
                    }
                }

                $candidates[] = ['file' => $file, 'content' => $content, 'ts' => $ts ?? 0];
            }
        }

        if (!count($candidates)) {
            return null;
        }

        usort($candidates, fn($a, $b) => $b['ts'] <=> $a['ts']);

        return $candidates[0]['content'] ?? null;
    }

    /**
     * Return available dates for a patient's dekurz in the given month.
     * Returns array with keys: month_from, month_to, dates (collection), days (collection)
     */
    public function getAvailableDates(int $patientId, string $month): array
    {
        $monthDt = Carbon::parse($month)->setTimezone('Europe/Bratislava');
        $from = $monthDt->copy()->startOfMonth()->toDateString();
        $to = $monthDt->copy()->endOfMonth()->toDateString();

        $dates = DB::table('patient_points')
            ->where('patient_id', $patientId)
            ->whereIn('procedure_code', ['3439', '3440'])
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->distinct()
            ->pluck('date');

        $isoDates = $dates->map(fn($d) => Carbon::parse($d)->toDateString())->unique()->values();

        $days = $isoDates->map(fn($d) => (int) Carbon::parse($d)->day)->unique()->sort()->values();

        return [
            'month_from' => $from,
            'month_to' => $to,
            'dates' => $isoDates,
            'days' => $days,
        ];
    }
}

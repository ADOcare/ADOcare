<?php

namespace App\Console\Commands;

use App\Support\ClinicalFrequency;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BuildProposalTrainingDataset extends Command
{
    protected $signature = 'ai:build-proposal-dataset
        {--documents=storage/app/private/scans/documents : Path to OCR documents directory}
        {--proposals=storage/app/private/proposals : Path to proposals directory}
        {--output=storage/app/private/ai-dataset : Output directory}
        {--min-score=10 : Minimum score to accept OCR -> proposal match}';

    protected $description = 'Build Gemini Vertex supervised-tuning dataset for OCR -> proposal generation';

    private const SYSTEM_INSTRUCTION = 'You are a nursing documentation assistant. '
        .'Given OCR output from a healthcare document, suggest likely proposal data. '
        .'The output is only a draft suggestion for a nurse to review and edit. '
        .'Use only valid enum values for diagnosis, nurse_diagnosis, mobility, expected_duration, procedure codes, and frequencies. '
        .'Return JSON only.';

    private const ALLOWED_DIAGNOSIS_CODES = [
        'A0010', 'A039', 'A4600', 'C348', 'C3490', 'C4380', 'C44', 'C443', 'C4430', 'C502', 'C508', 'C61', 'C673', 'C6730', 'C774', 'D34',
        'E030', 'E1031', 'E1072', 'E1074', 'E1081', 'E11', 'E1172', 'E1174', 'E1180', 'E1190', 'E782', 'E7880', 'F252', 'F488', 'G0600',
        'G21', 'G553', 'I10', 'I1000', 'I109', 'I1090', 'I25', 'I250', 'I2500', 'I269', 'I3210', 'I482', 'I509', 'I614', 'I6140', 'I639',
        'I69', 'I693', 'I70', 'I7010', 'I7020', 'I7023', 'I7024', 'I7025', 'I709', 'I7420', 'I8011', 'I803', 'I83', 'I8300', 'I8320',
        'J2090', 'J410', 'J4100', 'J450', 'J90', 'K30', 'K3511', 'K7680', 'K92', 'L022', 'L0220', 'L024', 'L0240', 'L028', 'L029',
        'L0302', 'L0310', 'L0311', 'L033', 'L040', 'L08', 'L088', 'L0880', 'L720', 'L8907', 'L8914', 'L8915', 'L8916', 'L8917',
        'L8918', 'L8919', 'L8924', 'L8925', 'L8927', 'L8928', 'L8929', 'L97', 'L9700', 'L984', 'L9840', 'M100', 'M130', 'M1300',
        'M1380', 'M16', 'M1600', 'M1630', 'M17', 'M175', 'M179', 'M4216', 'M5010', 'M511', 'M54', 'M5400', 'M5403', 'M5404',
        'M5405', 'M5406', 'M5408', 'M5417', 'M5419', 'M544', 'M5450', 'M546', 'M5488', 'M5490', 'M8100', 'N083', 'N183',
        'N4180', 'N648', 'N6480', 'R104', 'R26', 'R262', 'R263', 'R2630', 'R63', 'R636', 'R6360', 'S2201', 'S3203', 'S3204',
        'S325', 'S5180', 'S7201', 'S7210', 'S7211', 'S723', 'S8180', 'T813', 'T8902',
    ];

    private const ALLOWED_NURSE_DIAGNOSIS_CODES = [
        'K110',
        'D101',
        'D102',
        'A110',
    ];

    private const ALLOWED_PROCEDURE_CODES = [
        '3390', '3392A', '3393', '3394', '3395',
        '3410', '3413', '3416', '3419',
        '3422A', '3422B', '3422C',
        '3423', '3423A', '3423B', '3424',
        '3433', '3436', '3437', '3439', '3440',
    ];

    private const ALLOWED_MOBILITY = ['H', 'I', 'F'];

    private const ALLOWED_DURATION = [
        'one_month',
        'three_months',
        'six_months',
        'over_six_months',
    ];

    public function handle(): int
    {
        $documentsDir = base_path($this->option('documents'));
        $proposalsDir = base_path($this->option('proposals'));
        $outputDir = base_path($this->option('output'));
        $minScore = (float) $this->option('min-score');

        if (! File::isDirectory($documentsDir)) {
            $this->error("Documents directory not found: {$documentsDir}");

            return self::FAILURE;
        }

        if (! File::isDirectory($proposalsDir)) {
            $this->error("Proposals directory not found: {$proposalsDir}");

            return self::FAILURE;
        }

        File::ensureDirectoryExists($outputDir);

        $this->info('Loading OCR documents...');
        $ocrDocs = $this->loadJsonFiles($documentsDir, 'ocr');

        $this->info('Loading proposals...');
        $proposalDocs = $this->loadJsonFiles($proposalsDir, 'proposal');

        $this->line('OCR docs loaded: '.count($ocrDocs['items']));
        $this->line('Proposals loaded: '.count($proposalDocs['items']));

        $skipped = array_merge($ocrDocs['skipped'], $proposalDocs['skipped']);

        $proposalsByBirthNumber = [];
        foreach ($proposalDocs['items'] as $proposal) {
            $birthNumber = $this->normalizeBirthNumber(data_get($proposal, 'patient_birth_number'));

            if ($birthNumber === '') {
                $skipped[] = [
                    'type' => 'proposal',
                    'path' => data_get($proposal, '__path'),
                    'reason' => 'missing_birth_number',
                ];

                continue;
            }

            $proposalsByBirthNumber[$birthNumber] ??= [];
            $proposalsByBirthNumber[$birthNumber][] = $proposal;
        }

        $rows = [];
        $pairedReport = [];

        $bar = $this->output->createProgressBar(count($ocrDocs['items']));
        $bar->start();

        foreach ($ocrDocs['items'] as $ocrDoc) {
            $bar->advance();

            $birthNumber = $this->normalizeBirthNumber(data_get($ocrDoc, 'patient_birth_number'));

            if ($birthNumber === '') {
                $skipped[] = [
                    'type' => 'ocr',
                    'path' => data_get($ocrDoc, '__path'),
                    'reason' => 'missing_birth_number',
                ];

                continue;
            }

            $candidates = $proposalsByBirthNumber[$birthNumber] ?? [];

            [$bestProposal, $matchMeta] = $this->pickBestProposal($ocrDoc, $candidates, $minScore);

            if ($bestProposal === null) {
                $skipped[] = [
                    'type' => 'pair',
                    'ocr_path' => data_get($ocrDoc, '__path'),
                    'patient_name' => data_get($ocrDoc, 'patient_name'),
                    'patient_birth_number' => data_get($ocrDoc, 'patient_birth_number'),
                    'reason' => $matchMeta['reason'] ?? 'no_match',
                    'best_score' => $matchMeta['best_score'] ?? null,
                ];

                continue;
            }

            $cleanedOutput = $this->cleanProposalForTraining($bestProposal);

            $rejectReason = $this->shouldRejectTrainingRow($cleanedOutput, $ocrDoc);
            if ($rejectReason !== null) {
                $skipped[] = [
                    'type' => 'pair',
                    'ocr_path' => data_get($ocrDoc, '__path'),
                    'proposal_path' => data_get($bestProposal, '__path'),
                    'patient_name' => data_get($ocrDoc, 'patient_name'),
                    'patient_birth_number' => data_get($ocrDoc, 'patient_birth_number'),
                    'reason' => $rejectReason,
                ];

                continue;
            }

            $rows[] = $this->buildGeminiRow($ocrDoc, $cleanedOutput);

            $pairedReport[] = [
                'ocr_path' => data_get($ocrDoc, '__path'),
                'proposal_path' => data_get($bestProposal, '__path'),
                'patient_birth_number' => data_get($ocrDoc, 'patient_birth_number'),
                'patient_name_ocr' => data_get($ocrDoc, 'patient_name'),
                'patient_name_proposal' => data_get($bestProposal, 'patient_name'),
                'score' => $matchMeta['best_score'] ?? null,
                'details' => $matchMeta['details'] ?? [],
            ];
        }

        $bar->finish();
        $this->newLine(2);

        $trainPath = $outputDir.DIRECTORY_SEPARATOR.'train.jsonl';
        $pairedReportPath = $outputDir.DIRECTORY_SEPARATOR.'paired_report.json';
        $skippedReportPath = $outputDir.DIRECTORY_SEPARATOR.'skipped_report.json';

        $this->writeJsonl($trainPath, $rows);

        File::put(
            $pairedReportPath,
            json_encode($pairedReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        File::put(
            $skippedReportPath,
            json_encode($skipped, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $this->info('Dataset build finished.');
        $this->line('Train pairs: '.count($rows));
        $this->line('Skipped: '.count($skipped));
        $this->line("train.jsonl: {$trainPath}");
        $this->line("paired_report.json: {$pairedReportPath}");
        $this->line("skipped_report.json: {$skippedReportPath}");

        return self::SUCCESS;
    }

    protected function loadJsonFiles(string $directory, string $type): array
    {
        $items = [];
        $skipped = [];

        foreach (File::files($directory) as $file) {
            if (strtolower($file->getExtension()) !== 'json') {
                continue;
            }

            try {
                $decoded = json_decode(
                    File::get($file->getPathname()),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                if (! is_array($decoded)) {
                    $skipped[] = [
                        'type' => $type,
                        'path' => $file->getPathname(),
                        'reason' => 'invalid_json_structure',
                    ];

                    continue;
                }

                $decoded['__path'] = $file->getPathname();
                $items[] = $decoded;
            } catch (\Throwable $e) {
                $skipped[] = [
                    'type' => $type,
                    'path' => $file->getPathname(),
                    'reason' => 'json_decode_failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'items' => $items,
            'skipped' => $skipped,
        ];
    }

    protected function pickBestProposal(array $ocrDoc, array $candidates, float $minScore): array
    {
        if (empty($candidates)) {
            return [null, [
                'reason' => 'no_candidates',
                'best_score' => null,
                'details' => [],
            ]];
        }

        $ocrBirthNumber = $this->normalizeBirthNumber(data_get($ocrDoc, 'patient_birth_number'));
        $ocrName = $this->normalizePersonName(data_get($ocrDoc, 'patient_name'));
        $ocrTime = $this->extractOcrTimestamp($ocrDoc);

        $scored = [];

        foreach ($candidates as $proposal) {
            $score = 0.0;
            $details = [];

            $proposalBirthNumber = $this->normalizeBirthNumber(data_get($proposal, 'patient_birth_number'));
            $proposalName = $this->normalizePersonName(data_get($proposal, 'patient_name'));
            $proposalTime = $this->extractProposalTimestamp($proposal);

            if ($ocrBirthNumber !== '' && $proposalBirthNumber !== '' && $ocrBirthNumber === $proposalBirthNumber) {
                $score += 10.0;
                $details[] = 'birth_number_match';
            } else {
                continue;
            }

            $nameSimilarity = $this->nameSimilarity($ocrName, $proposalName);
            $score += $nameSimilarity * 3.0;
            $details[] = 'name_similarity='.number_format($nameSimilarity, 2, '.', '');

            if ($ocrTime && $proposalTime) {
                $deltaSeconds = $proposalTime->diffInSeconds($ocrTime, false);
                $hoursApart = abs($deltaSeconds) / 3600;

                if ($deltaSeconds >= 0) {
                    $score += 2.0;
                    $details[] = 'proposal_after_ocr';
                } else {
                    $score -= 1.0;
                    $details[] = 'proposal_before_ocr';
                }

                if ($hoursApart <= 24) {
                    $score += 2.0;
                    $details[] = 'within_24h';
                } elseif ($hoursApart <= 24 * 7) {
                    $score += 1.0;
                    $details[] = 'within_7d';
                } elseif ($hoursApart > 24 * 60) {
                    $score -= 2.0;
                    $details[] = 'too_far_in_time';
                }
            } else {
                $details[] = 'missing_time_data';
            }

            $scored[] = [
                'score' => $score,
                'proposal' => $proposal,
                'details' => $details,
            ];
        }

        if (empty($scored)) {
            return [null, [
                'reason' => 'no_scored_candidates',
                'best_score' => null,
                'details' => [],
            ]];
        }

        usort($scored, function (array $a, array $b) {
            return $b['score'] <=> $a['score'];
        });

        $best = $scored[0];

        if ($best['score'] < $minScore) {
            return [null, [
                'reason' => 'low_confidence_match',
                'best_score' => $best['score'],
                'details' => $best['details'],
            ]];
        }

        return [$best['proposal'], [
            'reason' => 'matched',
            'best_score' => $best['score'],
            'details' => $best['details'],
        ]];
    }

    protected function buildGeminiRow(array $ocrDoc, array $output): array
    {
        return [
            'systemInstruction' => [
                'parts' => [
                    [
                        'text' => self::SYSTEM_INSTRUCTION,
                    ],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [
                            'text' => $this->buildInputText($ocrDoc),
                        ],
                    ],
                ],
                [
                    'role' => 'model',
                    'parts' => [
                        [
                            'text' => json_encode(
                                $output,
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                            ),
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function buildInputText(array $ocrDoc): string
    {
        $compactInput = [
            'patient_name' => $this->normalizeString(data_get($ocrDoc, 'patient_name')),
            'patient_birth_number' => $this->normalizeBirthNumber(data_get($ocrDoc, 'patient_birth_number')),
            'extracted_text' => $this->normalizeExtractedText(data_get($ocrDoc, 'extracted_text')),
            'allowed_values' => [
                'diagnosis_codes' => self::ALLOWED_DIAGNOSIS_CODES,
                'nurse_diagnosis_codes' => self::ALLOWED_NURSE_DIAGNOSIS_CODES,
                'procedure_codes' => self::ALLOWED_PROCEDURE_CODES,
                'frequencies' => ClinicalFrequency::canonicalValues(),
                'mobility' => self::ALLOWED_MOBILITY,
                'expected_duration' => self::ALLOWED_DURATION,
                'fixed_frequency_rules' => [
                    '3439' => 'weekdays',
                    '3440' => 'weekends',
                ],
            ],
        ];

        return implode("\n", [
            'Suggest likely values for the target nursing proposal fields based on the OCR text.',
            'Use only valid codes and enum values from allowed_values.',
            'Return JSON only in this shape:',
            '{',
            '  "diagnosis": ["CODE"],',
            '  "nurse_diagnosis": ["CODE"],',
            '  "epicrisis": "TEXT",',
            '  "care_plan": "TEXT",',
            '  "mobility": ["H|I|F"],',
            '  "expected_duration": "one_month|three_months|six_months|over_six_months",',
            '  "procedures": [{"code":"CODE","frequency":"ENUM"}]',
            '}',
            '',
            'OCR JSON:',
            json_encode($compactInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    protected function cleanProposalForTraining(array $proposal): array
    {
        return [
            'diagnosis' => $this->normalizeDiagnosisCodes(data_get($proposal, 'diagnosis')),
            'nurse_diagnosis' => $this->normalizeNurseDiagnosisCodes(data_get($proposal, 'nurse_diagnosis')),
            'epicrisis' => $this->normalizeString(data_get($proposal, 'epicrisis')),
            'care_plan' => $this->normalizeString(data_get($proposal, 'care_plan')),
            'mobility' => $this->normalizeMobility(data_get($proposal, 'mobility')),
            'expected_duration' => $this->normalizeExpectedDuration(data_get($proposal, 'expected_duration')),
            'procedures' => $this->normalizeProcedures(data_get($proposal, 'procedures')),
        ];
    }

    protected function shouldRejectTrainingRow(array $output, array $ocrDoc): ?string
    {
        $ocrText = $this->normalizeExtractedText(data_get($ocrDoc, 'extracted_text'));

        if (mb_strlen($ocrText) < 80) {
            return 'ocr_text_too_short';
        }

        if ($this->looksLikeGarbage($output['epicrisis'])) {
            return 'invalid_epicrisis';
        }

        if ($this->looksLikeGarbage($output['care_plan'])) {
            return 'invalid_care_plan';
        }

        $hasStructuredSignal =
            ! empty($output['diagnosis']) ||
            ! empty($output['nurse_diagnosis']) ||
            ! empty($output['procedures']);

        if (! $hasStructuredSignal) {
            return 'missing_structured_signal';
        }

        return null;
    }

    protected function normalizeDiagnosisCodes(mixed $value): array
    {
        $items = $this->normalizeStringArray($value);
        $result = [];

        foreach ($items as $item) {
            $code = $this->extractLeadingCode($item);

            if ($code !== '' && in_array($code, self::ALLOWED_DIAGNOSIS_CODES, true)) {
                $result[] = $code;
            }
        }

        return array_values(array_unique($result));
    }

    protected function normalizeNurseDiagnosisCodes(mixed $value): array
    {
        $items = $this->normalizeStringArray($value);
        $result = [];

        foreach ($items as $item) {
            $code = $this->extractLeadingCode($item);

            if ($code !== '' && in_array($code, self::ALLOWED_NURSE_DIAGNOSIS_CODES, true)) {
                $result[] = $code;
            }
        }

        return array_values(array_unique($result));
    }

    protected function extractLeadingCode(string $value): string
    {
        $value = strtoupper(trim($value));

        if ($value === '') {
            return '';
        }

        if (preg_match('/^([A-Z0-9\.]+)/u', $value, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    protected function normalizeMobility(mixed $value): array
    {
        $items = $this->normalizeStringArray($value);

        $items = array_values(array_filter($items, function (string $item) {
            return in_array($item, self::ALLOWED_MOBILITY, true);
        }));

        return array_values(array_unique($items));
    }

    protected function normalizeExpectedDuration(mixed $value): string
    {
        $value = $this->normalizeString($value);

        return in_array($value, self::ALLOWED_DURATION, true) ? $value : '';
    }

    protected function normalizeProcedures(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        $seen = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $code = strtoupper(trim((string) data_get($item, 'code', '')));
            $frequency = ClinicalFrequency::normalize(trim((string) data_get($item, 'frequency', '')));

            if ($code === '' || $frequency === '') {
                continue;
            }

            if (! in_array($code, self::ALLOWED_PROCEDURE_CODES, true)) {
                continue;
            }

            if ($code === '3439') {
                $frequency = 'weekdays';
            }

            if ($code === '3440') {
                $frequency = 'weekends';
            }

            if (! in_array($frequency, ClinicalFrequency::canonicalValues(), true)) {
                continue;
            }

            $key = $code.'|'.$frequency;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $result[] = [
                'code' => $code,
                'frequency' => $frequency,
            ];
        }

        return array_values($result);
    }

    protected function normalizeExtractedText(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $value = preg_replace('/\R+/u', "\n", $value) ?? $value;
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\n{3,}/u', "\n\n", $value) ?? $value;

        return trim($value);
    }

    protected function normalizeString(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    protected function normalizeStringArray(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $item = trim(preg_replace('/\s+/u', ' ', $item) ?? $item);

            if ($item !== '') {
                $result[] = $item;
            }
        }

        return array_values(array_unique($result));
    }

    protected function looksLikeGarbage(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        $badTexts = [
            'HVHK',
            'JHJ',
            'dwdwwddw',
            'xxx',
            'test',
        ];

        $badTexts = array_map(static fn (string $v) => mb_strtolower($v), $badTexts);

        if (in_array(mb_strtolower($value), $badTexts, true)) {
            return true;
        }

        return mb_strlen($value) < 4;
    }

    protected function normalizeBirthNumber(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value) ?? '';
    }

    protected function normalizePersonName(?string $value): string
    {
        $value = trim((string) $value);
        $value = mb_strtolower($value);

        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($transliterated !== false) {
            $value = $transliterated;
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    protected function nameSimilarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        $aParts = array_values(array_filter(explode(' ', $a)));
        $bParts = array_values(array_filter(explode(' ', $b)));

        if (empty($aParts) || empty($bParts)) {
            return 0.0;
        }

        $intersection = array_intersect($aParts, $bParts);
        $union = array_unique(array_merge($aParts, $bParts));

        if (count($union) === 0) {
            return 0.0;
        }

        return count($intersection) / count($union);
    }

    protected function extractOcrTimestamp(array $ocrDoc): ?Carbon
    {
        return $this->parseDateTime(
            data_get($ocrDoc, 'ocr_at') ?: data_get($ocrDoc, 'scanned_at')
        );
    }

    protected function extractProposalTimestamp(array $proposal): ?Carbon
    {
        return $this->parseDateTime(
            data_get($proposal, 'created_at') ?: data_get($proposal, 'date')
        );
    }

    protected function parseDateTime(?string $value): ?Carbon
    {
        if (! $value || ! is_string($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function writeJsonl(string $path, array $rows): void
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new \RuntimeException("Unable to open file for writing: {$path}");
        }

        try {
            foreach ($rows as $row) {
                $json = json_encode(
                    $row,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );

                if ($json === false) {
                    continue;
                }

                fwrite($handle, $json."\n");
            }
        } finally {
            fclose($handle);
        }
    }
}

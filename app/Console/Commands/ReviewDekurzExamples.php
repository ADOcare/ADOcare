<?php

namespace App\Console\Commands;

use App\Models\DekurzAiFeedback;
use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Curate Proposal -> finalized Dekurz examples for few-shot prompting.
 *
 * This command DOES NOT call Vertex, publish patient records, or train a model.
 * Private review candidates must be checked for clinical accuracy and identifiers.
 */
class ReviewDekurzExamples extends Command
{
    protected $signature = 'ai:dekurz-examples
        {action=collect : collect or export}
        {--limit=100 : Maximum number of candidate examples to collect}
        {--scan-limit=5000 : Maximum number of feedback records to inspect}
        {--min-chars=150 : Minimum combined Dekurz text length}
        {--max-per-patient=1 : Candidate limit per patient}
        {--max-proposal-age=365 : Maximum days between Proposal and Dekurz}
        {--source=all : Feedback source (all, dekurz_v2, proposal_ai_prefill, etc.)}';

    protected $description = 'Collect private Proposal/Dekurz pairs for review and export only approved examples';

    private const PROPOSAL_FIELDS = [
        'diagnosis',
        'nurse_diagnosis',
        'epicrisis',
        'care_plan',
        'mobility',
        'expected_duration',
        'procedures',
    ];

    private const ARRAY_FIELDS = ['diagnosis', 'nurse_diagnosis', 'mobility', 'procedures'];

    public function handle(): int
    {
        try {
            return match ((string) $this->argument('action')) {
                'collect' => $this->collectCandidates(),
                'export' => $this->exportApproved(),
                default => $this->invalidAction(),
            };
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            report($exception);

            return self::FAILURE;
        }
    }

    private function invalidAction(): int
    {
        $this->error('Supported actions: collect, export.');

        return self::FAILURE;
    }

    private function rootDirectory(): string
    {
        return storage_path('app/private/ai/dekurz-examples');
    }

    private function candidatesDirectory(): string
    {
        return $this->rootDirectory().'/review/candidates';
    }

    private function collectCandidates(): int
    {
        $limit = $this->positiveOption('limit');
        $scanLimit = $this->positiveOption('scan-limit');
        $minChars = $this->positiveOption('min-chars');
        $maxPerPatient = $this->positiveOption('max-per-patient');
        $maxProposalAge = $this->positiveOption('max-proposal-age');
        $source = trim((string) $this->option('source'));

        $this->makePrivateDirectory($this->candidatesDirectory());

        $query = DekurzAiFeedback::query()
            ->whereNotNull('document_id')
            ->whereNotNull('proposal_document_id')
            ->orderByDesc('id')
            ->limit($scanLimit);

        if ($source !== '' && $source !== 'all') {
            $query->where('source', $source);
        }

        $created = 0;
        $selected = 0;
        $scanned = 0;
        $patients = [];
        $contentHashes = [];

        foreach ($query->cursor() as $feedback) {
            $scanned++;
            $patientId = (int) $feedback->patient_id;

            if ($patientId <= 0 || ($patients[$patientId] ?? 0) >= $maxPerPatient) {
                continue;
            }

            $sections = $this->cleanSections($feedback->final_sections);
            $length = mb_strlen(implode("\n\n", array_column($sections, 'text')));

            if ($sections === [] || $length < $minChars) {
                continue;
            }

            $contentHash = hash('sha256', mb_strtolower(implode("\n\n", array_column($sections, 'text'))));
            if (isset($contentHashes[$contentHash])) {
                continue;
            }

            $dekurz = Document::query()
                ->where('type', 'dekurz')
                ->where('mime_type', 'application/json')
                ->find((int) $feedback->document_id);
            $proposal = Document::query()
                ->where('type', 'proposal')
                ->find((int) $feedback->proposal_document_id);

            // Do not silently match documents from different patients or from the future.
            if (! $dekurz || ! $proposal
                || (int) $dekurz->patient_id !== $patientId
                || (int) $proposal->patient_id !== $patientId
                || ! $dekurz->created_at || ! $proposal->created_at
                || $proposal->created_at->greaterThan($dekurz->created_at)) {
                continue;
            }

            $ageDays = (int) floor(
                ($dekurz->created_at->getTimestamp() - $proposal->created_at->getTimestamp()) / 86400
            );
            if ($ageDays > $maxProposalAge) {
                continue;
            }

            $input = $this->loadProposal($proposal);
            if ($input === null || ! $this->hasUsefulProposalInformation($input)) {
                continue;
            }

            $feedbackId = (int) $feedback->id;
            $filename = sprintf('example-%08d.json', $feedbackId);
            $path = $this->candidatesDirectory().'/'.$filename;
            $contentHashes[$contentHash] = true;
            $patients[$patientId] = ($patients[$patientId] ?? 0) + 1;
            $selected++;

            // Re-running the collector never overwrites notes, edits or approval decisions.
            if (! is_file($path)) {
                $candidate = [
                    'review' => [
                        'status' => 'pending',
                        'clinical_reviewed' => false,
                        'privacy_reviewed' => false,
                        'notes' => '',
                    ],
                    'metadata' => [
                        'feedback_id' => $feedbackId,
                        'dekurz_document_id' => (int) $dekurz->id,
                        'proposal_document_id' => (int) $proposal->id,
                        'source' => (string) $feedback->source,
                        'dekurz_date' => $dekurz->created_at->toDateString(),
                        'proposal_age_days' => $ageDays,
                        'has_user_edits' => (bool) $feedback->has_user_edits,
                        'section_count' => count($sections),
                        'character_count' => $length,
                        'patient_link_verified' => true,
                    ],
                    'input' => $input,
                    'expected_output' => ['sections' => $sections],
                ];

                $this->writePrivateFile($path, $this->encode($candidate, true), false);
                $created++;
            }

            if ($selected >= $limit) {
                break;
            }
        }

        $this->writeReviewIndex();
        $this->info("Scanned feedback: {$scanned}. Selected candidates: {$selected}. Newly created: {$created}.");
        $this->line('Review index: '.$this->rootDirectory().'/review/index.csv');
        $this->line('Review files: '.$this->candidatesDirectory());
        $this->warn('These are PRIVATE medical records, NOT automatically anonymized or clinically approved.');
        $this->line('Set review.status to "approved", clinical_reviewed=true, privacy_reviewed=true only after reviewing and removing all patient identifiers from the contents.');

        return self::SUCCESS;
    }

    private function exportApproved(): int
    {
        $directory = $this->candidatesDirectory();
        if (! is_dir($directory)) {
            $this->error('No candidates found. Run: php artisan ai:dekurz-examples collect');

            return self::FAILURE;
        }

        $this->makePrivateDirectory($this->rootDirectory());
        $output = $this->rootDirectory().'/approved.jsonl';
        $lines = [];
        $approved = 0;
        $pending = 0;
        $rejected = 0;
        $blocked = 0;
        $hashes = [];

        foreach ($this->candidatePaths() as $path) {
            $candidate = $this->readJsonFile($path);
            $review = $candidate['review'] ?? [];
            $status = $review['status'] ?? 'pending';

            if ($status === 'rejected') {
                $rejected++;
                continue;
            }
            if ($status !== 'approved') {
                $pending++;
                continue;
            }
            if (($review['clinical_reviewed'] ?? false) !== true
                || ($review['privacy_reviewed'] ?? false) !== true) {
                $blocked++;
                $this->warn('Missing review checks: '.basename($path));
                continue;
            }

            $input = $this->cleanProposal($candidate['input'] ?? []);
            $sections = $this->cleanSections($candidate['expected_output']['sections'] ?? null);

            if (! $this->hasUsefulProposalInformation($input) || $sections === []) {
                $blocked++;
                $this->warn('Invalid approved example: '.basename($path));
                continue;
            }

            $row = ['input' => $input, 'expected_output' => ['sections' => $sections]];
            $encoded = $this->encode($row);
            $digest = hash('sha256', $encoded);
            if (isset($hashes[$digest])) {
                continue;
            }

            $hashes[$digest] = true;
            $lines[] = $encoded;
            $approved++;
        }

        $this->writePrivateFile($output, $lines === [] ? '' : implode("\n", $lines)."\n", true);
        $this->writeReviewIndex();
        $this->info("Exported: {$approved}. Pending: {$pending}. Rejected: {$rejected}. Blocked: {$blocked}.");
        $this->line('Approved few-shot library: '.$output);
        $this->warn('The command does not transmit data to Vertex. Privacy approval is manual; review free-text for identifiers before any external use.');

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function candidatePaths(): array
    {
        $paths = glob($this->candidatesDirectory().'/*.json') ?: [];
        sort($paths, SORT_STRING);

        return $paths;
    }

    /** @return array<string, mixed>|null */
    private function loadProposal(Document $proposal): ?array
    {
        if (! $proposal->path || ! Storage::disk('local')->exists($proposal->path)) {
            return null;
        }

        $raw = Storage::disk('local')->get($proposal->path);
        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        return $this->cleanProposal($decoded);
    }

    /** @return array<string, mixed> */
    private function cleanProposal(mixed $proposal): array
    {
        $proposal = is_array($proposal) ? $proposal : [];
        $clean = [];

        // Keep exactly the seven fields used by the existing DekurzPromptBuilder.
        foreach (self::PROPOSAL_FIELDS as $field) {
            $value = $proposal[$field] ?? null;
            $clean[$field] = in_array($field, self::ARRAY_FIELDS, true)
                ? (is_array($value) ? $value : [])
                : (is_scalar($value) ? trim((string) $value) : '');
        }

        return $clean;
    }

    private function hasUsefulProposalInformation(array $input): bool
    {
        return ($input['diagnosis'] ?? []) !== []
            || ($input['nurse_diagnosis'] ?? []) !== []
            || ($input['epicrisis'] ?? '') !== ''
            || ($input['care_plan'] ?? '') !== ''
            || ($input['procedures'] ?? []) !== [];
    }

    /** @return array<int, array{text: string}> */
    private function cleanSections(mixed $sections): array
    {
        if (! is_array($sections)) {
            return [];
        }

        $result = [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }
            $value = $section['text'] ?? '';
            if (! is_scalar($value)) {
                continue;
            }
            $text = trim((string) $value);
            if ($text !== '') {
                $result[] = ['text' => $text];
            }
        }

        return $result;
    }

    private function positiveOption(string $name): int
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_INT);
        if ($value === false || $value < 1) {
            throw new RuntimeException("--{$name} must be a positive integer.");
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function readJsonFile(string $path): array
    {
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid review JSON: '.$path, previous: $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid review object: '.$path);
        }

        return $decoded;
    }

    private function encode(array $data, bool $pretty = false): string
    {
        return json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | ($pretty ? JSON_PRETTY_PRINT : 0)
        );
    }

    private function writeReviewIndex(): void
    {
        $directory = $this->rootDirectory().'/review';
        $this->makePrivateDirectory($directory);
        $path = $directory.'/index.csv';
        $temporary = $path.'.tmp';
        $previousUmask = umask(0077);

        try {
            $handle = fopen($temporary, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Unable to create: '.$temporary);
            }
            try {
                fputcsv($handle, [
                    'file', 'feedback_id', 'source', 'dekurz_date', 'proposal_age_days',
                    'section_count', 'character_count', 'has_user_edits', 'status',
                    'clinical_reviewed', 'privacy_reviewed', 'notes',
                ], ',', '"', '');

                foreach ($this->candidatePaths() as $candidatePath) {
                    $candidate = $this->readJsonFile($candidatePath);
                    $meta = $candidate['metadata'] ?? [];
                    $review = $candidate['review'] ?? [];
                    fputcsv($handle, [
                        basename($candidatePath),
                        $meta['feedback_id'] ?? '',
                        $meta['source'] ?? '',
                        $meta['dekurz_date'] ?? '',
                        $meta['proposal_age_days'] ?? '',
                        $meta['section_count'] ?? '',
                        $meta['character_count'] ?? '',
                        empty($meta['has_user_edits']) ? 'no' : 'yes',
                        $review['status'] ?? 'pending',
                        empty($review['clinical_reviewed']) ? 'no' : 'yes',
                        empty($review['privacy_reviewed']) ? 'no' : 'yes',
                        $review['notes'] ?? '',
                    ], ',', '"', '');
                }
            } finally {
                fclose($handle);
            }

            if (! rename($temporary, $path)) {
                throw new RuntimeException('Unable to write review index: '.$path);
            }
            chmod($path, 0600);
        } finally {
            umask($previousUmask);
        }
    }

    private function makePrivateDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create directory: '.$directory);
        }
    }

    private function writePrivateFile(string $path, string $content, bool $overwrite): void
    {
        if (! $overwrite && file_exists($path)) {
            return;
        }

        $previousUmask = umask(0077);
        try {
            $handle = fopen($path, $overwrite ? 'wb' : 'xb');
            if ($handle === false) {
                throw new RuntimeException('Unable to write: '.$path);
            }
            try {
                $length = strlen($content);
                $written = 0;
                while ($written < $length) {
                    $bytes = fwrite($handle, substr($content, $written));
                    if ($bytes === false || $bytes === 0) {
                        throw new RuntimeException('Incomplete write: '.$path);
                    }
                    $written += $bytes;
                }
            } finally {
                fclose($handle);
            }
            chmod($path, 0600);
        } finally {
            umask($previousUmask);
        }
    }
}

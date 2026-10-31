<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Patient;
use Illuminate\Support\Facades\Storage;

/** Keep the patient's reusable Dekurz texts so every month starts from the latest wording. */
class PatientDekurzTextService
{
    /** @return array<int, string> */
    public function texts(Patient $patient): array
    {
        if (is_array($patient->dekurz_texts)) {
            return $this->normalize($patient->dekurz_texts);
        }

        return $this->historicalTexts($patient);
    }

    /**
     * Find texts in the patient's Dekurz history, newest first.
     *
     * Free-text sections (v1 documents and narrative drafts) win over the procedure-based
     * texts of interim v2 documents, which are used only when no section texts exist.
     *
     * @return array<int, string>
     */
    public function historicalTexts(Patient $patient): array
    {
        $procedureTexts = [];
        $documents = Document::query()
            ->where('type', 'dekurz')
            ->where('patient_id', $patient->id)
            ->whereNotNull('path')
            ->orderByDesc('id')
            ->lazy(50, 'id');

        foreach ($documents as $document) {
            $content = $this->readDocument($document);
            if (! $content) {
                continue;
            }
            $texts = $this->sectionTexts($content['narrative_sections'] ?? $content['sections'] ?? null);
            if ($texts) {
                return $texts;
            }
            if (! $procedureTexts) {
                $procedureTexts = $this->dayEntryTexts($content['days'] ?? null);
            }
        }

        return $procedureTexts;
    }

    /**
     * @param  array<int, ?string>  $texts
     * @return array<int, string>
     */
    public function save(Patient $patient, array $texts): array
    {
        $normalized = $this->normalize($texts);
        $patient->forceFill(['dekurz_texts' => $normalized])->save();

        return $normalized;
    }

    /** @return array<string, mixed>|null */
    private function readDocument(Document $document): ?array
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($document->path)) {
            return null;
        }
        $content = json_decode($disk->get($document->path), true);

        return is_array($content) ? $content : null;
    }

    /** @return array<int, string> */
    private function sectionTexts(mixed $sections): array
    {
        if (! is_array($sections)) {
            return [];
        }

        return $this->uniqueTexts(array_map(
            fn ($section): string => is_array($section) ? (string) ($section['text'] ?? '') : (string) $section,
            $sections,
        ));
    }

    /** @return array<int, string> */
    private function dayEntryTexts(mixed $days): array
    {
        if (! is_array($days)) {
            return [];
        }
        $texts = [];
        foreach ($days as $day) {
            $entries = is_array($day) && is_array($day['entries'] ?? null) ? $day['entries'] : [];
            foreach ($entries ?: [is_array($day) ? $day : []] as $entry) {
                $texts[] = (string) (is_array($entry) ? ($entry['text'] ?? '') : '');
            }
        }

        return $this->uniqueTexts($texts);
    }

    /**
     * @param  array<int, string>  $texts
     * @return array<int, string>
     */
    private function uniqueTexts(array $texts): array
    {
        $unique = [];
        foreach ($texts as $text) {
            $text = trim($text);
            if ($text !== '' && ! in_array($text, $unique, true)) {
                $unique[] = $text;
            }
        }

        return $unique;
    }

    /**
     * @param  array<int, mixed>  $texts
     * @return array<int, string>
     */
    private function normalize(array $texts): array
    {
        return array_values(array_map(fn ($text): string => (string) ($text ?? ''), $texts));
    }
}

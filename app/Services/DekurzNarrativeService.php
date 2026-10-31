<?php

namespace App\Services;

use App\Models\DekurzDraft;
use App\Models\Patient;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Save narrative sections independently of the retained scoring entries. */
class DekurzNarrativeService
{
    public function __construct(private DekurzScoringConsistencyService $consistency) {}

    /** @param array<int, array{text: ?string, dates: array<int, string>}> $sections */
    public function save(Patient $patient, DekurzDraft $draft, User $actor, array $sections): void
    {
        DB::transaction(function () use ($patient, $draft, $actor, $sections): void {
            $lockedDraft = DekurzDraft::query()->lockForUpdate()->findOrFail($draft->id);
            $this->consistency->authorizeDraftAccess($actor, $patient, $lockedDraft);
            if ($lockedDraft->status !== DekurzDraft::STATUS_DRAFT) {
                throw ValidationException::withMessages(['draft' => 'Dokončený Dekurz nie je možné upravovať.']);
            }
            $allowed = $lockedDraft->days()->get()->map(fn ($day): string => $day->date->toDateString())->all();
            $month = $lockedDraft->period->format('Y-m');
            $deathDate = $patient->death_date ? Carbon::parse($patient->death_date)->toDateString() : null;
            foreach ($sections as $index => $section) {
                foreach ($section['dates'] as $date) {
                    if (! str_starts_with($date, $month.'-') || ! in_array($date, $allowed, true) || ($deathDate && $date > $deathDate)) {
                        throw ValidationException::withMessages(["sections.{$index}.dates" => 'Vyberte platné dni Dekurzu vo zvolenom mesiaci, najneskôr v deň úmrtia.']);
                    }
                }
            }
            $lockedDraft->update([
                'narrative_sections' => array_map(fn (array $section): array => [
                    'text' => (string) ($section['text'] ?? ''),
                    'dates' => array_values(array_unique($section['dates'])),
                ], $sections),
                'updated_by' => $actor->id,
            ]);
        });
    }

    /** @param array<int, array{text: ?string, dates: array<int, string>}> $sections
     * @return array<string, string>
     */
    public static function dailyTexts(array $sections): array
    {
        $parts = [];
        foreach ($sections as $section) {
            foreach (array_unique($section['dates']) as $date) {
                $parts[$date][] = trim((string) ($section['text'] ?? ''));
            }
        }
        ksort($parts);

        return array_map(fn (array $texts): string => implode("\n\n", array_filter($texts, fn (string $text): bool => $text !== '')), $parts);
    }
}

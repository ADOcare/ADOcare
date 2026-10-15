<?php

namespace App\Services;

use App\Models\DekurzDraft;
use App\Models\DekurzEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DekurzEntryBatchService
{
    /** @return array{updated_count: int, updated_entry_ids: list<int>} */
    public function applyToUnedited(DekurzDraft $draft, DekurzEntry $source): array
    {
        $source->loadMissing('day');

        if ((int) $source->day->dekurz_draft_id !== (int) $draft->id) {
            throw ValidationException::withMessages([
                'entry' => 'Záznam nepatrí do zvoleného konceptu Dekurzu.',
            ]);
        }

        if ($draft->status !== DekurzDraft::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'draft' => 'Dokončený Dekurz nie je možné upravovať.',
            ]);
        }

        return DB::transaction(function () use ($draft, $source): array {
            $query = DekurzEntry::query()
                ->where('procedure_id', $source->procedure_id)
                ->whereKeyNot($source->id)
                ->whereNull('edited_at')
                ->whereHas('day', fn ($dayQuery) => $dayQuery->where('dekurz_draft_id', $draft->id));
            $ids = $query->pluck('id')->map(fn ($id): int => (int) $id)->all();

            if ($ids !== []) {
                DekurzEntry::query()->whereKey($ids)->update([
                    'text' => $source->text,
                    'source' => 'manual',
                    'edited_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return [
                'updated_count' => count($ids),
                'updated_entry_ids' => $ids,
            ];
        });
    }
}
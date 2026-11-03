<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('documents')
            ->select(['id', 'type', 'path'])
            ->whereIn('type', ['proposal', 'agreement', 'leave', 'record'])
            ->whereNull('reference_date')
            ->whereNotNull('path')
            ->orderBy('id')
            ->chunkById(100, function ($documents): void {
                $disk = Storage::disk('local');

                foreach ($documents as $document) {
                    if (! $disk->exists($document->path)) {
                        continue;
                    }

                    $payload = json_decode($disk->get($document->path), true);
                    if (! is_array($payload)) {
                        continue;
                    }

                    $value = $document->type === 'record'
                        ? data_get($payload, 'form_data.admissionDate', $payload['date'] ?? null)
                        : ($payload['date'] ?? null);
                    $date = $this->dateOnly($value);

                    if ($date !== null) {
                        DB::table('documents')
                            ->where('id', $document->id)
                            ->update(['reference_date' => $date]);
                    }
                }
            });
    }

    public function down(): void
    {
        //
    }

    private function dateOnly(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $match) !== 1) {
            return null;
        }

        if (! checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            return null;
        }

        return "{$match[1]}-{$match[2]}-{$match[3]}";
    }
};

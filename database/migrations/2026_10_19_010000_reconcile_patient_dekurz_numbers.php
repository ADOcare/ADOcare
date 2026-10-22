<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('dekurz_drafts')
            ->select('patient_id', DB::raw('MAX(dekurz_number) as latest_generated_number'))
            ->whereNotNull('dekurz_number')
            ->whereNotNull('final_document_id')
            ->groupBy('patient_id')
            ->orderBy('patient_id')
            ->each(function ($draft): void {
                DB::table('patients')
                    ->where('id', $draft->patient_id)
                    ->update([
                        'dekurz_number' => (string) (((int) $draft->latest_generated_number) + 1),
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        // Historical counters cannot be reconstructed safely after reconciliation.
    }
};

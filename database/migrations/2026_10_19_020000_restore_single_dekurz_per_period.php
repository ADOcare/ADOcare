<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('dekurz_drafts')
            ->select('patient_id', 'period', DB::raw('COUNT(*) as count'))
            ->groupBy('patient_id', 'period')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $details = $duplicates
                ->map(fn ($row): string => "patient_id={$row->patient_id}, period={$row->period}, count={$row->count}")
                ->implode('; ');

            throw new \RuntimeException(
                'Nie je možné obnoviť pravidlo jedného Dekurzu na mesiac, pretože už existujú duplicity: '.$details,
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS dekurz_drafts_one_open_per_period');
        }

        Schema::table('dekurz_drafts', function (Blueprint $table) {
            if (Schema::hasColumn('dekurz_drafts', 'replaces_document_id')) {
                $table->dropConstrainedForeignId('replaces_document_id');
            }

            if (Schema::hasColumn('dekurz_drafts', 'generation_mode')) {
                $table->dropColumn('generation_mode');
            }
        });

        Schema::table('dekurz_drafts', function (Blueprint $table) {
            $table->unique(['patient_id', 'period'], 'dekurz_drafts_patient_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('dekurz_drafts', function (Blueprint $table) {
            $table->dropUnique('dekurz_drafts_patient_period_unique');
            $table->string('generation_mode', 24)->default('replace')->after('status');
            $table->foreignId('replaces_document_id')
                ->nullable()
                ->after('final_document_id')
                ->constrained('documents')
                ->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX dekurz_drafts_one_open_per_period ON dekurz_drafts (patient_id, period) WHERE status = 'draft'");
        }
    }
};

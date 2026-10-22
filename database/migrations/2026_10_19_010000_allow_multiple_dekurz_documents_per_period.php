<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dekurz_drafts', function (Blueprint $table) {
            $table->dropUnique('dekurz_drafts_patient_period_unique');
            $table->string('generation_mode', 24)->default('keep_both')->after('status');
            $table->foreignId('replaces_document_id')
                ->nullable()
                ->after('final_document_id')
                ->constrained('documents')
                ->nullOnDelete();
            $table->index(['patient_id', 'period', 'status'], 'dekurz_drafts_patient_period_status_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX dekurz_drafts_one_open_per_period ON dekurz_drafts (patient_id, period) WHERE status = 'draft'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS dekurz_drafts_one_open_per_period');
        }

        Schema::table('dekurz_drafts', function (Blueprint $table) {
            $table->dropIndex('dekurz_drafts_patient_period_status_index');
            $table->dropConstrainedForeignId('replaces_document_id');
            $table->dropColumn('generation_mode');
            $table->unique(['patient_id', 'period'], 'dekurz_drafts_patient_period_unique');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_dekurz_custom_tracks', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('patient_id');
            $table->string('name', 120);
            $table->text('current_text')->nullable();
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->unique(['patient_id', 'name'], 'patient_dekurz_custom_tracks_patient_name_unique');
        });

        Schema::create('patient_dekurz_custom_track_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_track_id')->constrained('patient_dekurz_custom_tracks')->cascadeOnDelete();
            $table->date('period');
            $table->string('distribution', 32)->default('visit_days');
            $table->json('selected_dates')->nullable();
            $table->timestamps();

            $table->unique(['custom_track_id', 'period'], 'patient_dekurz_custom_track_period_unique');
            $table->index('period');
        });

        Schema::table('dekurz_drafts', function (Blueprint $table) {
            $table->unsignedInteger('dekurz_number')->nullable()->after('period');
        });

        Schema::table('dekurz_entries', function (Blueprint $table) {
            $table->dropUnique('dekurz_entries_patient_point_unique');
            $table->dropUnique('dekurz_entries_patient_point_snapshot_unique');
        });

        Schema::table('dekurz_entries', function (Blueprint $table) {
            $table->foreignId('custom_track_id')->nullable()->after('procedure_id')->constrained('patient_dekurz_custom_tracks')->nullOnDelete();
            $table->foreignId('source_entry_id')->nullable()->after('custom_track_id')->constrained('dekurz_entries')->nullOnDelete();
            $table->json('patient_point_ids')->nullable()->after('patient_point_id');
            $table->json('patient_point_snapshot_ids')->nullable()->after('patient_point_snapshot_id');
            $table->unsignedSmallInteger('track_position')->default(0)->after('quantity');
        });

        DB::table('dekurz_entries')
            ->whereNotNull('patient_point_id')
            ->orderBy('id')
            ->each(function ($entry): void {
                DB::table('dekurz_entries')
                    ->where('id', $entry->id)
                    ->update([
                        'patient_point_ids' => json_encode([(int) $entry->patient_point_id]),
                        'patient_point_snapshot_ids' => json_encode([(int) ($entry->patient_point_snapshot_id ?? $entry->patient_point_id)]),
                    ]);
            });

        // PostgreSQL supports changing the column directly. SQLite used in tests ignores this block.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dekurz_entries ALTER COLUMN procedure_id DROP NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DELETE FROM dekurz_entries WHERE procedure_id IS NULL');
            DB::statement('ALTER TABLE dekurz_entries ALTER COLUMN procedure_id SET NOT NULL');
        }

        Schema::table('dekurz_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_entry_id');
            $table->dropConstrainedForeignId('custom_track_id');
            $table->dropColumn([
                'patient_point_ids',
                'patient_point_snapshot_ids',
                'track_position',
            ]);
            $table->unique('patient_point_id', 'dekurz_entries_patient_point_unique');
            $table->unique('patient_point_snapshot_id', 'dekurz_entries_patient_point_snapshot_unique');
        });

        Schema::table('dekurz_drafts', function (Blueprint $table) {
            $table->dropColumn('dekurz_number');
        });

        Schema::dropIfExists('patient_dekurz_custom_track_periods');
        Schema::dropIfExists('patient_dekurz_custom_tracks');
    }
};

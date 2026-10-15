<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procedures', function (Blueprint $table) {
            $table->text('default_dekurz_text')->nullable();
        });

        Schema::table('dekurz_entries', function (Blueprint $table) {
            $table->unsignedInteger('patient_point_snapshot_id')->nullable();
        });

        DB::table('dekurz_entries')
            ->whereNull('patient_point_snapshot_id')
            ->whereNotNull('patient_point_id')
            ->update(['patient_point_snapshot_id' => DB::raw('patient_point_id')]);

        Schema::table('dekurz_entries', function (Blueprint $table) {
            $table->unique('patient_point_snapshot_id', 'dekurz_entries_patient_point_snapshot_unique');
        });
    }

    public function down(): void
    {
        Schema::table('dekurz_entries', function (Blueprint $table) {
            $table->dropUnique('dekurz_entries_patient_point_snapshot_unique');
            $table->dropColumn('patient_point_snapshot_id');
        });

        Schema::table('procedures', function (Blueprint $table) {
            $table->dropColumn('default_dekurz_text');
        });
    }
};

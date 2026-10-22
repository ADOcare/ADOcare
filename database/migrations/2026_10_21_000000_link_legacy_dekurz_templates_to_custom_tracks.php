<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_procedure_dekurz_templates', function (Blueprint $table) {
            $table->timestamp('legacy_imported_at')->nullable()->after('text');
        });
        DB::table('patient_procedure_dekurz_templates')
            ->whereNotNull('text')
            ->where('text', '!=', '')
            ->update(['legacy_imported_at' => now()]);

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('dekurz_entries', function (Blueprint $table) {
                $table->unsignedInteger('procedure_id')->nullable()->change();
            });
        }

        Schema::table('patient_dekurz_custom_tracks', function (Blueprint $table) {
            $table->foreignId('legacy_template_id')
                ->nullable()
                ->after('patient_id')
                ->unique()
                ->constrained('patient_procedure_dekurz_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('patient_dekurz_custom_tracks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('legacy_template_id');
        });
        Schema::table('patient_procedure_dekurz_templates', function (Blueprint $table) {
            $table->dropColumn('legacy_imported_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('patient_coverages')->update([
            'is_verified' => true,
        ]);

        DB::statement(
            'ALTER TABLE patient_coverages ALTER COLUMN is_verified SET DEFAULT TRUE'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE patient_coverages ALTER COLUMN is_verified SET DEFAULT FALSE'
        );
    }
};

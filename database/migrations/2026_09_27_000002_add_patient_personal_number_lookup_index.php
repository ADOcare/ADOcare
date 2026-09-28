<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS patients_personal_number_digits_idx
            ON patients ((regexp_replace(COALESCE(personal_number, ''), '[^0-9]', '', 'g')))
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS patients_personal_number_digits_idx');
    }
};

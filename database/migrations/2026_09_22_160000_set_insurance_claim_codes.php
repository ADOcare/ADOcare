<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('insurance_companies')
            ->where(function ($query) {
                $query->whereIn('code', ['VZP', '25'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%všeobecná%']);
            })
            ->update(['branch_code' => '25']);

        DB::table('insurance_companies')
            ->where(function ($query) {
                $query->whereIn('code', ['DOVERA', '24'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%dôvera%']);
            })
            ->update(['branch_code' => '24']);

        DB::table('insurance_companies')
            ->where(function ($query) {
                $query->whereIn('code', ['UNION', '27'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%union%']);
            })
            ->update(['branch_code' => '27']);
    }

    public function down(): void
    {
    }
};

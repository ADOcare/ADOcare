<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('country_code_phone', 5)->nullable()->after('contact');
            $table->string('phone', 30)->nullable()->after('country_code_phone');
        });

        DB::table('patients')
            ->whereNull('country_code_phone')
            ->update(['country_code_phone' => '+421']);
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn(['country_code_phone', 'phone']);
        });
    }
};

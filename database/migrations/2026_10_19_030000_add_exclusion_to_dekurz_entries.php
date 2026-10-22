<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dekurz_entries', function (Blueprint $table) {
            $table->boolean('is_excluded')
                ->default(false)
                ->after('track_position')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('dekurz_entries', function (Blueprint $table) {
            $table->dropColumn('is_excluded');
        });
    }
};

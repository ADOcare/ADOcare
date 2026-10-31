<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dekurz_drafts', function (Blueprint $table) {
            $table->jsonb('narrative_sections')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dekurz_drafts', function (Blueprint $table) {
            $table->dropColumn('narrative_sections');
        });
    }
};

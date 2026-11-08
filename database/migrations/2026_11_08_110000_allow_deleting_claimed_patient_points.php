<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_claim_lines', function (Blueprint $table): void {
            $table->dropForeign(['patient_point_id']);
            $table->unsignedInteger('patient_point_id')->nullable()->change();
            $table->foreign('patient_point_id')
                ->references('id')
                ->on('patient_points')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('point_claim_lines', function (Blueprint $table): void {
            $table->dropForeign(['patient_point_id']);
            $table->foreign('patient_point_id')
                ->references('id')
                ->on('patient_points')
                ->restrictOnDelete();
        });
    }
};

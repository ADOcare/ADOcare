<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the patient audit issue table.
     */
    public function up(): void
    {
        Schema::create('patient_audit_issues', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('patient_id');
            $table->string('code', 16);
            $table->string('severity', 16);
            $table->string('fingerprint', 100)->unique();
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->string('status', 16)->default('open');
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('patient_id')
                ->references('id')
                ->on('patients')
                ->restrictOnDelete();
            $table->index(['status', 'patient_id']);
        });
    }

    /**
     * Drop the patient audit issue table.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_audit_issues');
    }
};

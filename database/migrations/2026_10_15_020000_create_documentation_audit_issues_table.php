<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentation_audit_issues', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id')->nullable();
            $table->unsignedInteger('branch_id')->nullable();
            $table->unsignedInteger('patient_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('period', 7);
            $table->string('rule_code', 20);
            $table->string('severity', 20);
            $table->date('date')->nullable();
            $table->unsignedInteger('procedure_id')->nullable();
            $table->unsignedInteger('patient_point_id')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->char('fingerprint', 64)->unique();
            $table->string('status', 20)->default('open');
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('company')->nullOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('procedure_id')->references('id')->on('procedures')->nullOnDelete();
            $table->foreign('patient_point_id')->references('id')->on('patient_points')->nullOnDelete();
            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();
            $table->index(['status', 'period']);
            $table->index(['company_id', 'status']);
            $table->index(['patient_id', 'period']);
            $table->index(['rule_code', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentation_audit_issues');
    }
};
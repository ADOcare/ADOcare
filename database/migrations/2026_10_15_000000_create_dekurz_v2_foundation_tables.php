<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_procedure_dekurz_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('patient_id');
            $table->unsignedInteger('procedure_id');
            $table->text('text');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('procedure_id')->references('id')->on('procedures')->restrictOnDelete();
            $table->unique(['patient_id', 'procedure_id'], 'patient_procedure_dekurz_templates_unique');
            $table->index('procedure_id');
        });

        Schema::create('dekurz_drafts', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('patient_id');
            $table->unsignedInteger('branch_id')->nullable();
            $table->date('period');
            $table->enum('status', ['draft', 'completed'])->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('final_document_id')->nullable()->unique()->constrained('documents')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->unique(['patient_id', 'period'], 'dekurz_drafts_patient_period_unique');
            $table->index(['branch_id', 'period']);
            $table->index(['status', 'period']);
        });

        Schema::create('dekurz_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dekurz_draft_id')->constrained('dekurz_drafts')->cascadeOnDelete();
            $table->date('date');
            $table->text('general_note')->nullable();
            $table->timestamps();

            $table->unique(['dekurz_draft_id', 'date'], 'dekurz_days_draft_date_unique');
            $table->index('date');
        });

        Schema::create('dekurz_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dekurz_day_id')->constrained('dekurz_days')->cascadeOnDelete();
            $table->unsignedInteger('procedure_id');
            $table->unsignedInteger('patient_point_id')->nullable();
            $table->smallInteger('quantity')->nullable();
            $table->text('text');
            $table->string('source', 32)->default('manual');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->foreign('procedure_id')->references('id')->on('procedures')->restrictOnDelete();
            $table->foreign('patient_point_id')->references('id')->on('patient_points')->nullOnDelete();
            $table->unique('patient_point_id', 'dekurz_entries_patient_point_unique');
            $table->index(['dekurz_day_id', 'procedure_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dekurz_entries');
        Schema::dropIfExists('dekurz_days');
        Schema::dropIfExists('dekurz_drafts');
        Schema::dropIfExists('patient_procedure_dekurz_templates');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_ai_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained('documents')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('scan_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('source', 64)->default('proposal_store');
            $table->text('ocr_text');
            $table->jsonb('suggestion')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'created_at']);
        });

        Schema::table('vertex_training_run_examples', function (Blueprint $table) {
            $table->dropForeign(['feedback_id']);
        });
    }

    public function down(): void
    {
        Schema::table('vertex_training_run_examples', function (Blueprint $table) {
            $table->foreign('feedback_id')->references('id')->on('dekurz_ai_feedback')->cascadeOnDelete();
        });

        Schema::dropIfExists('proposal_ai_feedback');
    }
};

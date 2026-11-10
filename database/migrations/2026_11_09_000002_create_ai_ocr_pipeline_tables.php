<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_medical_products', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 255);
            $table->string('manufacturer', 255)->nullable();
            $table->string('category', 128);
            $table->string('variant', 255)->nullable();
            $table->json('alternative_names')->nullable();
            $table->boolean('active')->default(true);
            $table->string('origin', 64)->default('csv');
            $table->timestamps();
            $table->index(['active', 'name']);
        });

        Schema::create('ai_ocr_enhancements', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('document_id')->index();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->char('source_sha256', 64);
            $table->string('pipeline_version', 48);
            $table->string('status', 24)->default('processing');
            $table->string('result_path')->nullable();
            $table->string('review_path')->nullable();
            $table->json('vertex_request_ids')->nullable();
            $table->unsignedSmallInteger('page_count')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->index(['document_id', 'source_sha256', 'pipeline_version'], 'ai_ocr_dedupe_idx');
        });

        Schema::create('ai_ocr_product_mentions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('enhancement_id')->index();
            $table->unsignedInteger('page_index');
            $table->string('observed_text', 255);
            $table->string('proposed_name', 255)->nullable();
            $table->string('source_quote', 512);
            $table->string('clinical_context', 24)->default('unclear');
            $table->unsignedInteger('suggested_product_id')->nullable()->index();
            $table->string('match_type', 32)->default('unmatched');
            $table->string('review_status', 24)->default('pending');
            $table->unsignedInteger('verified_product_id')->nullable();
            $table->timestamps();
            $table->foreign('enhancement_id')->references('id')->on('ai_ocr_enhancements')->cascadeOnDelete();
            $table->foreign('suggested_product_id')->references('id')->on('ai_medical_products')->nullOnDelete();
            $table->foreign('verified_product_id')->references('id')->on('ai_medical_products')->nullOnDelete();
        });

        Schema::create('ai_ocr_review_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('enhancement_id')->index();
            $table->unsignedBigInteger('reviewer_id');
            $table->string('status', 24);
            $table->string('review_path');
            $table->json('decisions_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at');
            $table->foreign('enhancement_id')->references('id')->on('ai_ocr_enhancements')->cascadeOnDelete();
        });

        Schema::table('ai_usage_events', function (Blueprint $table): void {
            $table->string('billing_context', 24)->default('customer')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_events', function (Blueprint $table): void {
            $table->dropColumn('billing_context');
        });
        Schema::dropIfExists('ai_ocr_review_events');
        Schema::dropIfExists('ai_ocr_product_mentions');
        Schema::dropIfExists('ai_ocr_enhancements');
        Schema::dropIfExists('ai_medical_products');
    }
};

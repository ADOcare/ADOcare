<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_rule_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_company_id')
                ->constrained('insurance_companies')
                ->cascadeOnDelete();
            $table->string('care_type', 30);
            $table->string('name');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->text('source_url')->nullable();
            $table->string('source_version')->nullable();
            $table->date('source_published_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(
                ['insurance_company_id', 'care_type', 'is_active', 'valid_from'],
                'insurance_rule_sets_resolution_index',
            );
            $table->unique(
                ['insurance_company_id', 'care_type', 'valid_from'],
                'insurance_rule_sets_version_unique',
            );
        });

        Schema::create('insurance_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_set_id')
                ->constrained('insurance_rule_sets')
                ->cascadeOnDelete();
            $table->string('code');
            $table->string('type', 50);
            $table->string('severity', 20);
            $table->foreignId('procedure_id')
                ->nullable()
                ->constrained('procedures')
                ->nullOnDelete();
            $table->jsonb('parameters')->default('{}');
            $table->text('message')->nullable();
            $table->text('source_reference')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['rule_set_id', 'code']);
            $table->index(['rule_set_id', 'enabled', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_rules');
        Schema::dropIfExists('insurance_rule_sets');
    }
};
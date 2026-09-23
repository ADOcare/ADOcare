<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('documents', 'company_id')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->unsignedInteger('company_id')->nullable()->after('branch_id');
                $table->foreign('company_id')->references('id')->on('company')->nullOnDelete();
            });
        }

        Schema::create('point_claim_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id')->nullable()->unique();
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('branch_id');
            $table->unsignedBigInteger('healthcare_worker_id');
            $table->unsignedBigInteger('created_by');
            $table->unsignedInteger('insurance_company_id');
            $table->char('batch_type', 1);
            $table->date('accounting_period');
            $table->string('batch_number', 6);
            $table->string('invoice_number')->nullable();
            $table->string('special_category', 50)->nullable();
            $table->string('status', 30)->default('finalized');
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();
            $table->foreign('company_id')->references('id')->on('company')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('healthcare_worker_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('insurance_company_id')->references('id')->on('insurance_companies')->restrictOnDelete();

            $table->index(['healthcare_worker_id', 'insurance_company_id', 'accounting_period'], 'point_claim_batches_lookup');
            $table->index(['batch_type', 'status']);
        });

        Schema::create('point_claim_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('point_claim_batches')->cascadeOnDelete();
            $table->unsignedInteger('patient_point_id');
            $table->unsignedBigInteger('coverage_id')->nullable();
            $table->foreignId('previous_claim_line_id')->nullable()->constrained('point_claim_lines')->nullOnDelete();
            $table->string('selection_source', 20)->default('automatic');
            $table->string('status', 30)->default('submitted');
            $table->unsignedSmallInteger('quantity');
            $table->decimal('unit_price', 12, 4)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->jsonb('snapshot');
            $table->timestamps();

            $table->foreign('patient_point_id')->references('id')->on('patient_points')->restrictOnDelete();
            $table->foreign('coverage_id')->references('id')->on('patient_coverages')->nullOnDelete();
            $table->unique(['batch_id', 'patient_point_id']);
            $table->index(['patient_point_id', 'status']);
            $table->index(['previous_claim_line_id', 'status']);
        });

        Schema::create('point_claim_line_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_line_id')->constrained('point_claim_lines')->cascadeOnDelete();
            $table->string('status', 30);
            $table->string('error_code')->nullable();
            $table->text('message')->nullable();
            $table->unsignedSmallInteger('accepted_quantity')->nullable();
            $table->decimal('accepted_amount', 12, 2)->nullable();
            $table->timestamp('received_at');
            $table->jsonb('raw_data')->nullable();
            $table->timestamps();

            $table->index(['claim_line_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE point_claim_batches
            ADD CONSTRAINT point_claim_batches_type_check
            CHECK (batch_type IN ('N', 'O', 'A', 'E', 'F', 'G', 'I', 'J', 'K'))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX point_claim_batches_unique_new_batch
            ON point_claim_batches (
                healthcare_worker_id,
                insurance_company_id,
                accounting_period,
                batch_type
            )
            WHERE batch_type IN ('N', 'E', 'I')
              AND deleted_at IS NULL
              AND status <> 'cancelled'
        SQL);

        // The old documents index applied to every points subtype and prevented multiple
        // corrective or additional batches. Preserve only the kilometers guard; uniqueness
        // for new points batches is enforced by point_claim_batches above.
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS docs_unique_km_batch_insurance');
        DB::statement('DROP INDEX IF EXISTS docs_unique_km_batch_insurance');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX docs_unique_km_batch_insurance
            ON documents (type, subtype, user_id, branch_id, period, insurance_company_id)
            WHERE type = 'kilometers_batch' AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS docs_unique_km_batch_insurance');
        Schema::table('documents', function (Blueprint $table) {
            $table->unique(
                ['type', 'subtype', 'user_id', 'branch_id', 'period', 'insurance_company_id'],
                'docs_unique_km_batch_insurance'
            );
        });

        Schema::dropIfExists('point_claim_line_results');
        Schema::dropIfExists('point_claim_lines');
        Schema::dropIfExists('point_claim_batches');
    }
};

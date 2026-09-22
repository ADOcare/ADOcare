<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SLOVAKIA_COUNTRY_ID = 207;

    public function up(): void
    {
        Schema::create('patient_coverages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('insurance_company_id')
                ->nullable()
                ->constrained('insurance_companies')
                ->nullOnDelete();
            $table->string('regime', 20)->index();
            $table->string('member_state_code', 3)->nullable();
            $table->string('foreign_insured_id', 20)->nullable();
            $table->string('special_category', 50)->nullable();
            $table->string('entitlement_document_type', 50)->nullable();
            $table->string('entitlement_document_number', 100)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();

            $table->index(['patient_id', 'valid_from', 'valid_to']);
            $table->index(['insurance_company_id', 'regime']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE patient_coverages
            ADD CONSTRAINT patient_coverages_regime_check
            CHECK (regime IN ('domestic', 'eu', 'special', 'unclassified'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE patient_coverages
            ADD CONSTRAINT patient_coverages_validity_check
            CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE patient_coverages
            ADD CONSTRAINT patient_coverages_eu_fields_check
            CHECK (
                regime <> 'eu'
                OR (member_state_code IS NOT NULL AND foreign_insured_id IS NOT NULL)
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE patient_coverages
            ADD CONSTRAINT patient_coverages_special_category_check
            CHECK (
                (regime <> 'special' AND special_category IS NULL)
                OR (
                    regime = 'special'
                    AND special_category IN ('homeless', 'non_eu_foreigner', 'statutory_entitlement_9_3')
                )
            )
        SQL);

        DB::table('patients')
            ->select(['id', 'insurance_company_id', 'country_id', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(500, function ($patients) {
                $rows = [];

                foreach ($patients as $patient) {
                    $rows[] = [
                        'patient_id' => $patient->id,
                        'insurance_company_id' => $patient->insurance_company_id,
                        'regime' => (int) $patient->country_id === self::SLOVAKIA_COUNTRY_ID
                            ? 'domestic'
                            : 'unclassified',
                        'valid_from' => null,
                        'valid_to' => null,
                        'is_verified' => false,
                        'created_at' => $patient->created_at ?? now(),
                        'updated_at' => $patient->updated_at ?? now(),
                    ];
                }

                if ($rows !== []) {
                    DB::table('patient_coverages')->insert($rows);
                }
            });

        DB::statement('ALTER TABLE patients ALTER COLUMN country_id DROP NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_coverages');
    }
};

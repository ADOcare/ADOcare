<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE patient_coverages DROP CONSTRAINT IF EXISTS patient_coverages_other_subtype_check');
        DB::statement('ALTER TABLE patient_coverages DROP CONSTRAINT IF EXISTS patient_coverages_identification_method_check');
        DB::statement('ALTER TABLE patient_coverages DROP CONSTRAINT IF EXISTS patient_coverages_category_check');
        DB::statement('ALTER TABLE patient_coverages DROP CONSTRAINT IF EXISTS patient_coverages_regime_check');
        DB::statement('ALTER TABLE patient_coverages DROP CONSTRAINT IF EXISTS patient_coverages_validity_check');
        DB::statement('ALTER TABLE patient_coverages DROP CONSTRAINT IF EXISTS patient_coverages_eu_fields_check');
        DB::statement('ALTER TABLE patient_coverages DROP CONSTRAINT IF EXISTS patient_coverages_special_category_check');

        DB::statement(<<<'SQL'
            UPDATE patient_coverages
            SET category = CASE
                    WHEN category = 'domestic' OR regime = 'domestic' THEN 'domestic'
                    WHEN category = 'eu' OR regime = 'eu' THEN 'eu'
                    ELSE 'special'
                END,
                special_category = CASE
                    WHEN category = 'homeless' OR special_category = 'homeless' THEN 'homeless'
                    WHEN category = 'non_eu' OR special_category = 'non_eu_foreigner' THEN 'non_eu_foreigner'
                    WHEN special_category = 'statutory_entitlement_9_3'
                        OR other_subtype = 'statutory_entitlement_9_3'
                        OR other_subtype ILIKE '%9 ods. 3%'
                        THEN 'statutory_entitlement_9_3'
                    WHEN category NOT IN ('domestic', 'eu') OR regime = 'special'
                        THEN 'statutory_entitlement_9_3'
                    ELSE NULL
                END,
                identification_method = CASE
                    WHEN category = 'eu' OR regime = 'eu' THEN 'foreign_triad'
                    WHEN category NOT IN ('domestic', 'eu') OR regime = 'special' THEN
                        CASE
                            WHEN identification_method = 'foreign_triad'
                                OR (member_state_code IS NOT NULL AND foreign_insured_id IS NOT NULL)
                                THEN 'foreign_triad'
                            ELSE 'slovak_identifier'
                        END
                    ELSE 'slovak_identifier'
                END
        SQL);

        DB::statement(<<<'SQL'
            DELETE FROM patient_coverages older
            USING patient_coverages newer
            WHERE older.patient_id = newer.patient_id
              AND (older.created_at, older.id) < (newer.created_at, newer.id)
        SQL);

        Schema::table('patient_coverages', function (Blueprint $table) {
            $table->dropColumn([
                'regime',
                'other_subtype',
                'legal_basis',
                'entitlement_confirmed',
                'document_registered',
                'entitlement_document_type',
                'entitlement_document_number',
                'valid_from',
                'valid_to',
            ]);

            $table->unique('patient_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE patient_coverages
            ADD CONSTRAINT patient_coverages_category_check
            CHECK (category IN ('domestic', 'eu', 'special'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE patient_coverages
            ADD CONSTRAINT patient_coverages_identification_method_check
            CHECK (identification_method IN ('slovak_identifier', 'foreign_triad'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE patient_coverages
            ADD CONSTRAINT patient_coverages_special_category_check
            CHECK (
                (category <> 'special' AND special_category IS NULL)
                OR (
                    category = 'special'
                    AND special_category IN ('homeless', 'non_eu_foreigner', 'statutory_entitlement_9_3')
                )
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE patient_coverages DROP CONSTRAINT IF EXISTS patient_coverages_special_category_check');
        DB::statement('ALTER TABLE patient_coverages DROP CONSTRAINT IF EXISTS patient_coverages_identification_method_check');
        DB::statement('ALTER TABLE patient_coverages DROP CONSTRAINT IF EXISTS patient_coverages_category_check');

        Schema::table('patient_coverages', function (Blueprint $table) {
            $table->dropUnique(['patient_id']);
            $table->string('regime', 20)->nullable();
            $table->string('other_subtype', 100)->nullable();
            $table->string('legal_basis', 255)->nullable();
            $table->boolean('entitlement_confirmed')->default(false);
            $table->boolean('document_registered')->default(false);
            $table->string('entitlement_document_type', 50)->nullable();
            $table->string('entitlement_document_number', 100)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
        });

        DB::statement("UPDATE patient_coverages SET regime = category");
    }
};

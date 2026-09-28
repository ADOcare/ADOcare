<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_coverages', function (Blueprint $table) {
            $table->string('category', 20)->nullable()->after('regime')->index();
            $table->string('identification_method', 30)->nullable()->after('category');
            $table->string('other_subtype', 100)->nullable()->after('special_category');
            $table->string('legal_basis', 255)->nullable()->after('other_subtype');
            $table->boolean('entitlement_confirmed')->default(false)->after('legal_basis');
            $table->boolean('document_registered')->default(false)->after('entitlement_confirmed');
        });

        DB::statement(<<<'SQL'
            UPDATE patient_coverages
            SET category = CASE
                    WHEN regime = 'domestic' THEN 'domestic'
                    WHEN regime = 'eu' THEN 'eu'
                    WHEN special_category = 'homeless' THEN 'homeless'
                    WHEN special_category = 'non_eu_foreigner' THEN 'non_eu'
                    ELSE 'other'
                END,
                identification_method = CASE
                    WHEN regime = 'eu' THEN 'foreign_triad'
                    WHEN regime IN ('domestic', 'special') THEN 'slovak_identifier'
                    ELSE 'incomplete'
                END,
                other_subtype = CASE
                    WHEN regime = 'unclassified' THEN 'Migrovaný nezaradený poistný vzťah'
                    WHEN special_category = 'statutory_entitlement_9_3' THEN 'Osoba podľa § 9 ods. 3'
                    ELSE other_subtype
                END,
                entitlement_confirmed = CASE WHEN regime = 'domestic' THEN true ELSE false END
        SQL);

        DB::statement('ALTER TABLE patient_coverages ALTER COLUMN category SET NOT NULL');
        DB::statement('ALTER TABLE patient_coverages ALTER COLUMN identification_method SET NOT NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE patient_coverages
            ADD CONSTRAINT patient_coverages_category_check
            CHECK (category IN ('domestic', 'eu', 'non_eu', 'homeless', 'other'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE patient_coverages
            ADD CONSTRAINT patient_coverages_identification_method_check
            CHECK (identification_method IN ('slovak_identifier', 'foreign_triad', 'incomplete'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE patient_coverages
            ADD CONSTRAINT patient_coverages_other_subtype_check
            CHECK (category <> 'other' OR other_subtype IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE patient_coverages DROP CONSTRAINT IF EXISTS patient_coverages_other_subtype_check');
        DB::statement('ALTER TABLE patient_coverages DROP CONSTRAINT IF EXISTS patient_coverages_identification_method_check');
        DB::statement('ALTER TABLE patient_coverages DROP CONSTRAINT IF EXISTS patient_coverages_category_check');

        Schema::table('patient_coverages', function (Blueprint $table) {
            $table->dropColumn([
                'category',
                'identification_method',
                'other_subtype',
                'legal_basis',
                'entitlement_confirmed',
                'document_registered',
            ]);
        });
    }
};

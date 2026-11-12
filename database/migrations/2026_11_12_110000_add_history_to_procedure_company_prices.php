<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procedure_company_prices', function (Blueprint $table): void {
            $table->dropUnique('pcp_procedure_insurance_company_unique');
            $table->date('valid_from')->nullable()->after('price');
            $table->date('valid_to')->nullable()->after('valid_from');
            $table->unsignedInteger('changed_by')->nullable()->after('valid_to');
            $table->foreign('changed_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(
                ['procedure_id', 'insurance_company_id', 'company_id', 'valid_from'],
                'pcp_procedure_insurance_company_period_unique',
            );
            $table->index(
                ['procedure_id', 'insurance_company_id', 'company_id', 'valid_from', 'valid_to'],
                'pcp_effective_period_idx',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE procedure_company_prices
                 ADD CONSTRAINT procedure_company_prices_valid_period
                 CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from)',
            );
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Procedure price history cannot be rolled back without destroying effective-dated records.',
        );
    }
};

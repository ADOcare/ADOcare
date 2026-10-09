<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_routes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->date('service_date');
            $table->string('fingerprint', 64);
            $table->json('payload');
            $table->timestamps();
            $table->unique(['company_id', 'branch_id', 'user_id', 'service_date', 'fingerprint'], 'transport_route_version');
        });

        Schema::create('transport_journeys', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->date('service_date');
            $table->string('stop_key', 64);
            $table->timestamps();
            $table->unique(['company_id', 'branch_id', 'user_id', 'service_date', 'stop_key'], 'transport_journey_identity');
        });

        Schema::create('transport_claim_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('document_id')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('insurance_company_id');
            $table->string('period', 7);
            $table->char('character', 1);
            $table->unsignedInteger('revision')->default(1);
            $table->json('payload');
            $table->timestamps();
            $table->index(['company_id', 'branch_id', 'user_id', 'period'], 'transport_batch_scope');
        });

        Schema::create('transport_claim_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('batch_id');
            $table->unsignedInteger('revision');
            $table->unsignedBigInteger('journey_id');
            $table->unsignedBigInteger('insurance_company_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('patient_point_id');
            $table->char('character', 1);
            $table->string('fingerprint', 64);
            $table->json('payload');
            $table->timestamps();
            $table->unique(['batch_id', 'revision', 'journey_id'], 'transport_line_revision');
            $table->index(['journey_id', 'insurance_company_id', 'id'], 'transport_line_history');
        });

        Schema::create('transport_odometer_readings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('car_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('recorded_by');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('end_km', 12, 3);
            $table->string('route_fingerprint', 64);
            $table->timestamps();
            $table->unique('document_id');
            $table->index(['company_id', 'car_id', 'period_end'], 'transport_odometer_vehicle');
        });

        Schema::table('cars', function (Blueprint $table) {
            $table->string('vin', 17)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cars', fn (Blueprint $table) => $table->dropColumn('vin'));
        Schema::dropIfExists('transport_odometer_readings');
        Schema::dropIfExists('transport_claim_lines');
        Schema::dropIfExists('transport_claim_batches');
        Schema::dropIfExists('transport_journeys');
        Schema::dropIfExists('transport_routes');
    }
};

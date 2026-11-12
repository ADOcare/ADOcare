<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'patient_name_history' => [
                'first_name' => ['string', 255],
                'last_name' => ['string', 255],
                'title' => ['string', 255],
            ],
            'patient_address_history' => [
                'address' => ['string', 255],
                'city' => ['string', 255],
                'zip' => ['string', 50],
                'country_id' => ['id'],
                'latitude' => ['double'],
                'longitude' => ['double'],
            ],
            'patient_doctor_history' => [
                'doctor_id' => ['id'],
            ],
            'patient_nurse_history' => [
                'nurse_id' => ['id'],
            ],
            'patient_branch_history' => [
                'branch_id' => ['id'],
            ],
            'patient_identity_history' => [
                'personal_number' => ['string', 255],
                'sex' => ['string', 1],
            ],
            'patient_contact_history' => [
                'contact' => ['string', 255],
                'country_code_phone' => ['string', 5],
                'phone' => ['string', 30],
            ],
        ];

        foreach ($tables as $tableName => $fields) {
            Schema::create($tableName, function (Blueprint $table) use ($fields, $tableName): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('patient_id');

                foreach ($fields as $field => $definition) {
                    if ($definition[0] === 'id') {
                        $table->unsignedBigInteger($field)->nullable();
                    } elseif ($definition[0] === 'double') {
                        $table->double($field)->nullable();
                    } else {
                        $table->{$definition[0]}($field, $definition[1])->nullable();
                    }
                }

                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('change_reason', 1000)->nullable();
                $table->timestamps();
                $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
                $table->index(['patient_id', 'valid_from', 'valid_to'], $tableName.'_period_idx');
            });

            if (DB::getDriverName() === 'pgsql') {
                DB::statement(
                    "ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_validity_check "
                    .'CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from)'
                );
            }

            DB::table('patients')
                ->select(array_merge(['id'], array_keys($fields)))
                ->orderBy('id')
                ->chunkById(500, function ($patients) use ($tableName, $fields): void {
                    $rows = [];
                    $now = now();
                    $existingPatientIds = DB::table($tableName)
                        ->whereIn('patient_id', $patients->pluck('id'))
                        ->pluck('patient_id')
                        ->flip();

                    foreach ($patients as $patient) {
                        if ($existingPatientIds->has($patient->id)) {
                            continue;
                        }

                        $row = [
                            'patient_id' => $patient->id,
                            'valid_from' => null,
                            'valid_to' => null,
                            'changed_by' => null,
                            'change_reason' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        foreach (array_keys($fields) as $field) {
                            $row[$field] = $patient->{$field};
                        }

                        $rows[] = $row;
                    }

                    if ($rows !== []) {
                        DB::table($tableName)->insert($rows);
                    }
                });
        }

        Schema::table('patient_coverages', function (Blueprint $table): void {
            $table->dropUnique(['patient_id']);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_reason', 1000)->nullable();
            $table->index(['patient_id', 'valid_from', 'valid_to'], 'patient_coverages_period_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE patient_coverages
                ADD CONSTRAINT patient_coverages_history_validity_check
                CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from)
            SQL);
        }

        Schema::create('patient_history_corrections', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('patient_id');
            $table->string('category', 30);
            $table->unsignedBigInteger('record_id');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->json('before_values');
            $table->json('after_values');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 1000);
            $table->timestamps();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->index(['patient_id', 'created_at'], 'patient_history_corrections_patient_recorded_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Patient history is a forward-only migration; rolling it back would delete patient data.');
    }
};

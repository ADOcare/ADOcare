<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_history_changes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('patient_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_type', 30);
            $table->timestamp('recorded_at');
            $table->string('reason', 1000)->nullable();
            $table->json('snapshot');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->index(['patient_id', 'recorded_at', 'id'], 'patient_history_changes_timeline_idx');
        });

        Schema::create('patient_history_change_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('history_change_id')->constrained('patient_history_changes')->cascadeOnDelete();
            $table->string('category', 30);
            $table->json('previous_values')->nullable();
            $table->json('new_values');
            $table->date('effective_date')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['history_change_id', 'category'], 'patient_history_change_items_category_idx');
        });

        Schema::table('patient_history_corrections', function (Blueprint $table): void {
            $table->foreignId('history_change_id')
                ->nullable()
                ->constrained('patient_history_changes')
                ->nullOnDelete();
        });

        $now = now();
        DB::table('patients')
            ->orderBy('id')
            ->chunkById(250, function ($patients) use ($now): void {
                $coverageByPatient = DB::table('patient_coverages')
                    ->whereIn('patient_id', $patients->pluck('id'))
                    ->where(fn ($query) => $query
                        ->whereNull('valid_from')
                        ->orWhereDate('valid_from', '<=', $now->toDateString()))
                    ->where(fn ($query) => $query
                        ->whereNull('valid_to')
                        ->orWhereDate('valid_to', '>=', $now->toDateString()))
                    ->orderByDesc('valid_from')
                    ->orderByDesc('id')
                    ->get()
                    ->unique('patient_id')
                    ->keyBy('patient_id');

                foreach ($patients as $patient) {
                    $coverage = $coverageByPatient->get($patient->id);
                    $snapshot = [
                        'patient_id' => $patient->id,
                        'date' => $now->toDateString(),
                        'name' => $this->baselineCategory($patient, ['first_name', 'last_name', 'title']),
                        'identity' => $this->baselineCategory($patient, ['personal_number', 'sex']),
                        'contact' => $this->baselineCategory($patient, ['contact', 'country_code_phone', 'phone']),
                        'address' => $this->baselineCategory($patient, ['address', 'city', 'zip', 'country_id', 'latitude', 'longitude']),
                        'doctor' => $this->baselineCategory($patient, ['doctor_id']),
                        'nurse' => $this->baselineCategory($patient, ['nurse_id']),
                        'branch' => $this->baselineCategory($patient, ['branch_id']),
                        'insurance' => $coverage
                            ? $this->baselineCategory($coverage, [
                                'insurance_company_id',
                                'category',
                                'identification_method',
                                'member_state_code',
                                'foreign_insured_id',
                                'special_category',
                                'is_verified',
                                'verified_at',
                            ])
                            : $this->missingCategory(),
                    ];

                    DB::table('patient_history_changes')->insert([
                        'patient_id' => $patient->id,
                        'user_id' => null,
                        'change_type' => 'baseline',
                        'recorded_at' => $now,
                        'reason' => null,
                        'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                        'metadata' => json_encode([
                            'legacy_baseline' => true,
                            'description' => 'Počiatočný stav pri zavedení denníka zmien.',
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('patient_history_corrections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('history_change_id');
        });
        Schema::dropIfExists('patient_history_change_items');
        Schema::dropIfExists('patient_history_changes');
    }

    private function baselineCategory(object $source, array $fields): array
    {
        return [
            'status' => 'unknown',
            'values' => collect($fields)->mapWithKeys(fn (string $field) => [$field => $source->{$field} ?? null])->all(),
            'valid_from' => null,
            'valid_to' => null,
            'recorded_at' => null,
            'changed_by' => null,
            'reason' => null,
        ];
    }

    private function missingCategory(): array
    {
        return [
            'status' => 'missing',
            'values' => null,
            'valid_from' => null,
            'valid_to' => null,
        ];
    }
};

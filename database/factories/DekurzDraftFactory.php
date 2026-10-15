<?php

namespace Database\Factories;

use App\Models\DekurzDraft;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

class DekurzDraftFactory extends Factory
{
    protected $model = DekurzDraft::class;

    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'branch_id' => null,
            'period' => $this->faker->dateTimeBetween('-1 year', 'now')->format('Y-m-01'),
            'status' => DekurzDraft::STATUS_DRAFT,
            'created_by' => null,
            'updated_by' => null,
            'final_document_id' => null,
            'completed_at' => null,
        ];
    }
}
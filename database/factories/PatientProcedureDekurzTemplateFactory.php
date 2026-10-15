<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientProcedureDekurzTemplate;
use App\Models\Procedure;
use Illuminate\Database\Eloquent\Factories\Factory;

class PatientProcedureDekurzTemplateFactory extends Factory
{
    protected $model = PatientProcedureDekurzTemplate::class;

    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'procedure_id' => Procedure::factory(),
            'text' => $this->faker->sentence(),
            'created_by' => null,
            'updated_by' => null,
        ];
    }
}
<?php

namespace Database\Factories;

use App\Models\DekurzDay;
use App\Models\DekurzEntry;
use App\Models\Procedure;
use Illuminate\Database\Eloquent\Factories\Factory;

class DekurzEntryFactory extends Factory
{
    protected $model = DekurzEntry::class;

    public function definition(): array
    {
        return [
            'dekurz_day_id' => DekurzDay::factory(),
            'procedure_id' => Procedure::factory(),
            'patient_point_id' => null,
            'patient_point_snapshot_id' => null,
            'quantity' => 1,
            'text' => $this->faker->sentence(),
            'source' => 'manual',
            'edited_at' => null,
        ];
    }
}
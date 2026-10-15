<?php

namespace Database\Factories;

use App\Models\DekurzDay;
use App\Models\DekurzDraft;
use Illuminate\Database\Eloquent\Factories\Factory;

class DekurzDayFactory extends Factory
{
    protected $model = DekurzDay::class;

    public function definition(): array
    {
        return [
            'dekurz_draft_id' => DekurzDraft::factory(),
            'date' => $this->faker->date(),
            'general_note' => null,
        ];
    }
}
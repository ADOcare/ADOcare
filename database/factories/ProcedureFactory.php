<?php

namespace Database\Factories;
use App\Models\Procedure;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProcedureFactory extends Factory
{
    protected $model = Procedure::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->bothify('??#')), // e.g. AB1
            'description' => $this->faker->sentence(6),
            'default_dekurz_text' => null,
        ];
    }
}

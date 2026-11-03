<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        $positions = ['manager', 'nurse', 'physiotherapist'];

        return [
            'position' => $this->faker->randomElement($positions),
        ];
    }
}

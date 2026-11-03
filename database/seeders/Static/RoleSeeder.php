<?php

namespace Database\Seeders\Static;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure defined roles exist (idempotent)
        $roles = [
            ['id' => 0, 'position' => 'superadmin', 'scope' => 'global'],
            ['id' => 1, 'position' => 'manager', 'scope' => 'company'],
            ['id' => 2, 'position' => 'nurse', 'scope' => 'branch'],
        ];
        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['id' => $role['id']],
                ['position' => $role['position'], 'scope' => $role['scope']]
            );
        }

        Role::updateOrCreate(
            ['position' => 'physiotherapist'],
            ['scope' => 'branch'],
        );
    }
}

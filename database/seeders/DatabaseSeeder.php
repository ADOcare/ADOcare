<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Run static seeders
        $this->call([
            Static\RoleSeeder::class,
            Static\CountrySeeder::class,
            Static\InsuranceCompanySeeder::class,
            Static\UserSeeder::class,
            AdosEpisodeConfigurationSeeder::class,
        ]);

    }
}

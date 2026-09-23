<?php

namespace Database\Seeders\Static;

use App\Models\InsuranceCompany;
use App\Models\Patient;
use Illuminate\Database\Seeder;

class InsuranceCompanySeeder extends Seeder
{
    public function run(): void
    {
        // always seed a few default insurance companies (idempotent)

        $defaults = [
            [
                'code' => 'VZP',
                'name' => 'Všeobecná zdravotná poisťovňa, a. s.',
                'address' => 'Panónska cesta 2',
                'city' => 'Bratislava',
                'psc' => '85104',
                'ico' => '35937874',
                'dic' => '2022027040',
                'ic_dph' => 'SK2022027040',
                'register' => 'Obchodný register Mestského súdu Bratislava III, oddiel: Sa, vložka č. 3602/B',
                'branch_code' => '25',
            ],
            [
                'code' => 'DOVERA',
                'name' => 'DÔVERA zdravotná poisťovňa, a. s.',
                'address' => 'Einsteinova 25 ',
                'city' => 'Bratislava',
                'psc' => '85101',
                'ico' => '35942436',
                'dic' => '2022051130',
                'ic_dph' => 'SK2022051130',
                'register' => 'Obchodný register Mestského súdu Bratislava III, oddiel: Sa, vložka č. 3627/B',
                'branch_code' => '24',
            ],
            [
                'code' => 'UNION',
                'name' => 'Union zdravotná poisťovňa, a.s.',
                'address' => 'Karadžičova 10',
                'city' => 'Bratislava',
                'psc' => '81453',
                'ico' => '36284831',
                'dic' => '2022152517',
                'ic_dph' => 'SK712000136',
                'branch_code' => '27',
            ]
        ];


        foreach ($defaults as $attrs) {
            InsuranceCompany::firstOrCreate(['code' => $attrs['code']], $attrs);
        }

        if (!env('SEED_SAMPLE_DATA', false)) {
            // do not touch patient assignments unless demo seeding requested
            return;
        }

        $companies = InsuranceCompany::all();
        // Assign some patients randomly to insurance companies (demo only)
        $patients = Patient::all();
        if ($patients->isEmpty()) {
            return;
        }

        foreach ($patients as $patient) {
            if (rand(0, 1) === 1) {
                $patient->insurance_company_id = $companies->random()->id;
                $patient->save();
            }
        }
    }
}

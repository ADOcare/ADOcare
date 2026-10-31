<?php

namespace Database\Seeders;

use App\Models\AdosEpisodeType;
use App\Models\Company;
use Illuminate\Database\Seeder;

/**
 * Initializes editable episode type defaults for existing companies.
 */
class AdosEpisodeConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->chunkById(100, function ($companies): void {
            foreach ($companies as $company) {
                $types = [];
                foreach ([
                    ['name' => 'Dlhodobá starostlivosť', 'code' => 'long_term', 'sort_order' => 1],
                    ['name' => 'Krátkodobá starostlivosť', 'code' => 'short_term', 'sort_order' => 2],
                    ['name' => 'Odbery', 'code' => 'collection', 'sort_order' => 3],
                ] as $defaults) {
                    $types[$defaults['code']] = AdosEpisodeType::query()->firstOrCreate(
                        ['company_id' => $company->id, 'code' => $defaults['code']],
                        $defaults + ['is_active' => true],
                    );
                }

                foreach ([
                    ['collection', 'short_term'],
                    ['collection', 'long_term'],
                    ['short_term', 'long_term'],
                ] as [$fromCode, $toCode]) {
                    $company->episodeTypeTransitions()->firstOrCreate([
                        'from_episode_type_id' => $types[$fromCode]->id,
                        'to_episode_type_id' => $types[$toCode]->id,
                    ]);
                }
            }
        });
    }
}

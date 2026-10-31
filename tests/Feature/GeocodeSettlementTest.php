<?php

namespace Tests\Feature;

use App\Services\GeocodeService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

class GeocodeSettlementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository(['app.name' => 'ADOcare']));
        $app->instance(Factory::class, new Factory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_numbered_addresses_without_streets_accept_small_settlements(): void
    {
        foreach (['village', 'hamlet', 'locality', 'municipality'] as $settlementType) {
            Http::fake([
                'maps.googleapis.com/*' => Http::response(['predictions' => []]),
                'nominatim.openstreetmap.org/*' => Http::response([[
                    'place_id' => 123,
                    'display_name' => '23, Čakanovce, okres Lučenec, Banskobystrický kraj, 985 58, Slovensko',
                    'lat' => '48.2',
                    'lon' => '19.8',
                    'address' => [
                        'city' => '',
                        'house_number' => '23',
                        $settlementType => 'Čakanovce',
                        'postcode' => '985 58',
                    ],
                ]]),
            ]);

            $result = (new GeocodeService)->autocomplete('test address');
            $prediction = $result['body']['predictions'][0];

            self::assertSame('23', $prediction['street']);
            self::assertSame('Čakanovce', $prediction['city']);
            self::assertSame('985 58', $prediction['zip']);
            self::assertSame(48.2, $prediction['lat']);
            self::assertSame(19.8, $prediction['lng']);
        }
    }

    public function test_cakanovce_house_number_uses_administrative_municipality_from_real_response(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response(['predictions' => []]),
            'nominatim.openstreetmap.org/*' => Http::response([[
                'place_id' => 188881880,
                'osm_type' => 'way',
                'osm_id' => 61275304,
                'lat' => '48.2155700',
                'lon' => '19.8171693',
                'display_name' => '23, Čakanovce, okres Lučenec, Banskobystrický kraj, 985 58, Slovensko',
                'address' => [
                    'house_number' => '23',
                    'administrative' => 'Čakanovce',
                    'district' => 'okres Lučenec',
                    'region' => 'Banskobystrický kraj',
                    'postcode' => '985 58',
                    'country' => 'Slovensko',
                    'country_code' => 'sk',
                ],
            ]]),
        ]);

        $result = (new GeocodeService)->autocomplete('Čakanovce 23');
        $prediction = $result['body']['predictions'][0];

        self::assertSame('nominatim', $prediction['source']);
        self::assertSame('23', $prediction['street']);
        self::assertSame('Čakanovce', $prediction['city']);
        self::assertSame('985 58', $prediction['zip']);
        self::assertSame(48.21557, $prediction['lat']);
        self::assertSame(19.8171693, $prediction['lng']);
    }
}

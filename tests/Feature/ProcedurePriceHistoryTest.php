<?php

namespace Tests\Feature;

use App\Models\Procedure;
use App\Services\ProcedurePriceHistoryService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class ProcedurePriceHistoryTest extends TestCase
{
    private ProcedurePriceHistoryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository);
        $database = new Manager($app);
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $database->setAsGlobal();
        $database->bootEloquent();
        $app->instance('db', $database->getDatabaseManager());
        $app->bind('db.schema', fn () => $database->schema());
        $translator = new Translator(new ArrayLoader, 'sk');
        $app->instance('translator', $translator);
        $app->instance('validator', new Factory($translator, $app));

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        Schema::create('procedures', function ($table): void {
            $table->increments('id');
            $table->string('code');
            $table->string('description');
            $table->timestamps();
        });
        Schema::create('insurance_companies', function ($table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function ($table): void {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestamps();
        });
        Schema::create('procedure_company_prices', function ($table): void {
            $table->increments('id');
            $table->decimal('price', 10, 2);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->unsignedInteger('changed_by')->nullable();
            $table->unsignedInteger('procedure_id');
            $table->unsignedInteger('insurance_company_id');
            $table->unsignedInteger('company_id');
            $table->timestamps();
        });

        DB::table('procedures')->insert([
            'id' => 1,
            'code' => 'X1',
            'description' => 'Test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('insurance_companies')->insert([
            [
                'id' => 1,
                'name' => 'Poisťovňa A',
                'code' => 'A',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'name' => 'Poisťovňa B',
                'code' => 'B',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('users')->insert([
            'id' => 7,
            'title' => 'Mgr.',
            'first_name' => 'Jana',
            'last_name' => 'Nováková',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service = new ProcedurePriceHistoryService;
    }

    public function test_it_splits_unknown_and_dated_periods_inclusively(): void
    {
        $this->insertPrice(1, 10);

        $this->service->applyPrices(1, [[
            'insurance_company_id' => 1,
            'price' => 12,
            'effective_date' => '2026-03-01',
        ]], 10, 7);
        $this->service->applyPrices(1, [[
            'insurance_company_id' => 1,
            'price' => 15,
            'effective_date' => '2026-04-01',
        ]], 10, 7);

        $periods = DB::table('procedure_company_prices')
            ->where('insurance_company_id', 1)
            ->orderByRaw('valid_from IS NOT NULL')
            ->orderBy('valid_from')
            ->get();

        self::assertCount(3, $periods);
        self::assertNull($periods[0]->valid_from);
        self::assertSame('2026-02-28', $periods[0]->valid_to);
        self::assertSame('2026-03-01', $periods[1]->valid_from);
        self::assertSame('2026-03-31', $periods[1]->valid_to);
        self::assertSame('2026-04-01', $periods[2]->valid_from);
        self::assertNull($periods[2]->valid_to);
    }

    public function test_state_resolves_each_insurer_for_the_requested_date_and_company(): void
    {
        $this->insertPrice(1, 10);
        $this->insertPrice(2, 20);
        $this->insertPrice(1, 99, companyId: 20);
        $this->service->applyPrices(1, [[
            'insurance_company_id' => 1,
            'price' => 12,
            'effective_date' => '2026-03-01',
        ]], 10, 7);

        $procedure = Procedure::query()->findOrFail(1);
        $before = collect($this->service->state($procedure, 10, '2026-02-28')['prices'])
            ->keyBy('insurance_company_id');
        $after = collect($this->service->state($procedure, 10, '2026-03-01')['prices'])
            ->keyBy('insurance_company_id');

        self::assertEquals(10, $before[1]['price']);
        self::assertEquals(12, $after[1]['price']);
        self::assertEquals(20, $after[2]['price']);
        self::assertNotEquals(99, $after[1]['price']);

        $history = $this->service->history($procedure, 10);
        self::assertSame('2026-03-01', $history->first()['effective_date']);
        self::assertSame('Jana', $history->first()['changed_by_name']);
        self::assertCount(2, $history->last()['items']);
    }

    public function test_changed_price_requires_an_effective_date_but_unchanged_price_does_not(): void
    {
        $this->insertPrice(1, 10);

        $this->service->applyPrices(1, [[
            'insurance_company_id' => 1,
            'price' => 10,
        ]], 10, 7);
        self::assertSame(1, DB::table('procedure_company_prices')->count());

        $this->expectException(ValidationException::class);
        $this->service->applyPrices(1, [[
            'insurance_company_id' => 1,
            'price' => 11,
        ]], 10, 7);
    }

    public function test_same_date_can_be_corrected_without_creating_an_overlapping_period(): void
    {
        $this->insertPrice(1, 10);
        $this->service->applyPrices(1, [[
            'insurance_company_id' => 1,
            'price' => 12,
            'effective_date' => '2026-03-01',
        ]], 10, 7);
        $this->service->applyPrices(1, [[
            'insurance_company_id' => 1,
            'price' => 13,
            'effective_date' => '2026-03-01',
        ]], 10, 7);

        $datedPeriods = DB::table('procedure_company_prices')
            ->where('insurance_company_id', 1)
            ->whereNotNull('valid_from')
            ->get();

        self::assertCount(1, $datedPeriods);
        self::assertEquals(13, $datedPeriods->first()->price);
    }

    private function insertPrice(
        int $insuranceCompanyId,
        int $price,
        int $companyId = 10,
    ): void {
        DB::table('procedure_company_prices')->insert([
            'procedure_id' => 1,
            'insurance_company_id' => $insuranceCompanyId,
            'company_id' => $companyId,
            'price' => $price,
            'valid_from' => null,
            'valid_to' => null,
            'changed_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

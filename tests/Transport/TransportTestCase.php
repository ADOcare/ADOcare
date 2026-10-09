<?php

namespace Tests\Transport;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

abstract class TransportTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container();
        Container::setInstance($app);
        $app->instance('config', new Repository(['transport' => require __DIR__ . '/../../config/transport.php']));
        $app->instance('validator', new Factory(new Translator(new ArrayLoader(), 'sk'), $app));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    protected function database(): void
    {
        $app = Container::getInstance();
        $db = new Manager($app);
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $db->setAsGlobal();
        $db->bootEloquent();
        $app->instance('db', $db->getDatabaseManager());
        $app->bind('db.schema', fn () => $db->schema());
        Schema::create('cars', function ($table) {
            $table->id();
        });
        Schema::create('documents', function ($table) {
            $table->id();
            foreach (['company_id', 'branch_id', 'user_id', 'insurance_company_id'] as $column) {
                $table->integer($column);
            }
            $table->string('period');
            $table->string('type');
            $table->softDeletes();
        });
        $migration = require __DIR__ . '/../../database/migrations/2026_10_06_120000_create_transport_records.php';
        $migration->up();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }
}

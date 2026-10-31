<?php

namespace Tests\Feature;

use App\Http\Requests\BulkUpdatePatientPointRequest;
use App\Models\PatientPoint;
use App\Models\Role;
use App\Models\User;
use App\Services\DocumentationAudit\ImmediateDocumentationAuditService;
use App\Services\PatientPointBulkEditService;
use App\Services\VisitTimelineFreshnessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class PatientPointBulkEditTest extends TestCase
{
    private User $user;

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
        $app->instance('db.schema', $database->schema());
        $translator = new Translator(new ArrayLoader, 'en');
        $validator = new Factory($translator, $app);
        $validator->setPresenceVerifier(new DatabasePresenceVerifier($database->getDatabaseManager()));
        $app->instance('validator', $validator);
        $gate = $this->createMock(GateContract::class);
        $gate->method('forUser')->willReturnSelf();
        $app->instance(GateContract::class, $gate);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->integer('company_id');
        });
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->integer('branch_id');
            $table->softDeletes();
        });
        foreach (['diagnoses', 'procedures'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('code');
            });
        }
        Schema::create('patient_points', function (Blueprint $table) {
            $table->id();
            foreach (['patient_id', 'branch_id', 'user_id', 'diagnosis_id', 'procedure_id', 'quantity', 'doctor_id'] as $field) {
                $table->integer($field)->nullable();
            }
            foreach (['diagnosis_code', 'procedure_code', 'doctor_pzs', 'doctor_zpr'] as $field) {
                $table->string($field)->nullable();
            }
            $table->date('date');
            $table->date('reference_date');
            $table->timestamps();
        });
        DB::table('branches')->insert(['id' => 1, 'company_id' => 7]);
        DB::table('patients')->insert(['id' => 1, 'branch_id' => 1]);
        DB::table('diagnoses')->insert(['id' => 2, 'code' => 'L029']);
        DB::table('procedures')->insert(['id' => 2, 'code' => '3419']);
        $this->user = new User(['company_id' => 7]);
        $this->user->setRelation('role', new Role(['position' => 'nurse']));
        foreach (['2026-09-01', '2026-10-03', '2026-11-05'] as $date) {
            PatientPoint::create([
                'patient_id' => 1, 'branch_id' => 1, 'user_id' => 1,
                'diagnosis_id' => 1, 'diagnosis_code' => 'L029',
                'procedure_id' => 1, 'procedure_code' => '3413',
                'quantity' => 1, 'reference_date' => '2026-08-25',
                'doctor_id' => 9, 'doctor_pzs' => 'PZS', 'doctor_zpr' => 'ZPR',
                'date' => $date,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_matching_only_includes_same_month_and_year_with_matching_shared_values(): void
    {
        PatientPoint::findOrFail(2)->update(['date' => '2026-09-30']);
        $baseline = PatientPoint::findOrFail(1);
        foreach (['2026-09-05', '2026-08-31', '2026-10-01', '2025-09-01'] as $date) {
            $point = $baseline->replicate();
            $point->date = $date;
            if ($date === '2026-09-05') {
                $point->doctor_zpr = 'OTHER';
            }
            $point->save();
        }
        $service = $this->service(false);

        self::assertSame([1, 2], $service->matching($this->user, PatientPoint::findOrFail(1))->pluck('id')->all());
    }

    public function test_bulk_edit_preserves_dates_and_only_changes_token_selected_records(): void
    {
        $service = $this->service(true);
        $points = $service->update($this->user, $this->payload());
        self::assertSame([1, 3], $points->modelKeys());
        self::assertSame('2026-09-01', $points[0]->date->toDateString());
        self::assertSame('2026-11-05', $points[1]->date->toDateString());
        self::assertSame(2, $points[0]->procedure_id);
        self::assertSame('3419', $points[0]->procedure_code);
        self::assertSame(2, $points[0]->diagnosis_id);
        self::assertSame('2026-09-25', $points[0]->reference_date->toDateString());
        self::assertSame(1, PatientPoint::findOrFail(2)->procedure_id);
        self::assertSame(9, $points[0]->doctor_id);
    }

    public function test_incompatible_records_are_rejected_without_partial_updates(): void
    {
        PatientPoint::findOrFail(3)->update(['quantity' => 2]);
        try {
            $this->service(false)->update($this->user, $this->payload());
            self::fail('Incompatible records must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('ids', $exception->errors());
        }
        self::assertSame(1, PatientPoint::findOrFail(1)->procedure_id);
    }

    public function test_matching_and_update_reject_other_company(): void
    {
        $this->user->company_id = 8;
        foreach (['matching', 'update'] as $method) {
            try {
                $service = $this->service(false);
                if ($method === 'matching') {
                    $service->matching($this->user, PatientPoint::findOrFail(1));
                } else {
                    $service->update($this->user, $this->payload());
                }
                self::fail('Cross-company access must be rejected.');
            } catch (AuthorizationException $exception) {
                self::assertSame('Nemáte oprávnenie upraviť vybrané záznamy.', $exception->getMessage());
            }
        }
    }

    public function test_single_record_can_be_updated_without_changing_its_date(): void
    {
        $payload = array_replace($this->payload(), ['ids' => [1]]);
        $factory = Container::getInstance()->make('validator');
        self::assertTrue($factory->make($payload, (new BulkUpdatePatientPointRequest)->rules())->passes());

        $points = $this->service(true)->update($this->user, $payload);
        self::assertSame([1], $points->modelKeys());
        self::assertSame('2026-09-01', $points[0]->date->toDateString());
        self::assertSame(2, $points[0]->procedure_id);
        self::assertSame(1, PatientPoint::findOrFail(2)->procedure_id);
    }

    public function test_empty_or_deleted_selection_is_rejected(): void
    {
        foreach ([[], [1, 99]] as $ids) {
            try {
                $this->service(false)->update($this->user, array_replace($this->payload(), ['ids' => $ids]));
                self::fail('A bulk selection must include at least one existing record.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('ids', $exception->errors());
            }
        }
    }

    public function test_matching_respects_point_policy(): void
    {
        $gate = $this->createMock(GateContract::class);
        $gate->method('forUser')->willReturnSelf();
        $gate->method('authorize')->willThrowException(new AuthorizationException);
        Container::getInstance()->instance(GateContract::class, $gate);
        Facade::clearResolvedInstances();

        $this->expectException(AuthorizationException::class);
        $this->service(false)->matching($this->user, PatientPoint::findOrFail(1));
    }

    public function test_request_rejects_invalid_token_ids_and_execution_date_changes(): void
    {
        $factory = Container::getInstance()->make('validator');
        $rules = (new BulkUpdatePatientPointRequest)->rules();
        self::assertTrue($factory->make($this->payload(), $rules)->passes());

        foreach ([
            [['ids' => []], 'ids'],
            [['ids' => [1, 1]], 'ids.0'],
            [['ids' => [1, 99]], 'ids.1'],
            [['date' => '2026-12-01'], 'date'],
        ] as [$changes, $field]) {
            $validator = $factory->make(array_replace($this->payload(), $changes), $rules);
            self::assertTrue($validator->fails());
            self::assertTrue($validator->errors()->has($field));
        }
    }

    public function test_matching_supports_nullable_shared_fields(): void
    {
        PatientPoint::findOrFail(2)->update(['date' => '2026-09-03']);
        PatientPoint::query()->update(['doctor_id' => null, 'doctor_pzs' => null, 'doctor_zpr' => null]);
        PatientPoint::findOrFail(3)->update(['doctor_zpr' => 'OTHER']);

        self::assertSame([1, 2], $this->service(false)
            ->matching($this->user, PatientPoint::findOrFail(1))->pluck('id')->all());
    }

    private function service(bool $expectsUpdate): PatientPointBulkEditService
    {
        $timeline = $this->createMock(VisitTimelineFreshnessService::class);
        $audit = $this->createMock(ImmediateDocumentationAuditService::class);
        $timeline->expects($expectsUpdate ? self::once() : self::never())->method('invalidatePoints');
        $audit->expects($expectsUpdate ? self::once() : self::never())->method('patientPointsChanged');

        return new PatientPointBulkEditService($timeline, $audit);
    }

    private function payload(): array
    {
        return [
            'ids' => [1, 3], 'diagnosis_id' => 2, 'procedure_id' => 2,
            'quantity' => 2, 'reference_date' => '2026-09-25',
        ];
    }
}

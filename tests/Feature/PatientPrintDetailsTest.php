<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PatientController;
use App\Http\Resources\PatientResource;
use App\Models\Patient;
use App\Services\PatientService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use PHPUnit\Framework\TestCase;

class PatientPrintDetailsTest extends TestCase
{
    public function test_patient_detail_loads_all_print_relations_after_authorization(): void
    {
        $patient = $this->createMock(Patient::class);
        $patient->expects(self::once())->method('loadMissing')->with([
            'nurse', 'branch', 'doctor', 'visits', 'insuranceCompany', 'latestCoverage.insuranceCompany',
        ])->willReturnSelf();

        $controller = $this->getMockBuilder(PatientController::class)
            ->setConstructorArgs([$this->createMock(PatientService::class)])
            ->onlyMethods(['authorize', 'success'])
            ->getMock();
        $controller->expects(self::once())->method('authorize')->with('view', $patient);
        $response = new JsonResponse(['data' => ['id' => 1]]);
        $controller->expects(self::once())->method('success')
            ->with(self::isInstanceOf(PatientResource::class), 'Pacient bol uspesne nacitany.')
            ->willReturn($response);

        self::assertSame($response, $controller->show($patient));
    }

    public function test_unauthorized_patient_details_are_not_loaded(): void
    {
        $patient = $this->createMock(Patient::class);
        $patient->expects(self::never())->method('loadMissing');

        $controller = $this->getMockBuilder(PatientController::class)
            ->setConstructorArgs([$this->createMock(PatientService::class)])
            ->onlyMethods(['authorize', 'success'])
            ->getMock();
        $controller->method('authorize')->willThrowException(new AuthorizationException);
        $controller->expects(self::never())->method('success');

        $this->expectException(AuthorizationException::class);
        $controller->show($patient);
    }
}

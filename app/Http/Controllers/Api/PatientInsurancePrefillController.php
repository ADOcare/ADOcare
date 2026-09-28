<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EoverenieInsuranceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientInsurancePrefillController extends Controller
{
    public function __construct(private EoverenieInsuranceService $service)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'personal_number' => ['required', 'string', 'max:20'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->service->lookup(
            $data['personal_number'],
            $data['first_name'] ?? null,
            $data['last_name'] ?? null,
        );

        return $this->success($result, 'Údaje o poistnom vzťahu boli načítané.');
    }
}

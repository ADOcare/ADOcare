<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PointClaimCandidatesRequest;
use App\Services\PointClaimSelectionService;

class PointClaimCandidateController extends Controller
{
    public function __construct(private PointClaimSelectionService $selectionService)
    {
    }

    public function __invoke(PointClaimCandidatesRequest $request)
    {
        return $this->success(
            $this->selectionService->candidates($request->validated(), $request->user()),
            'Výkony pre dávku boli načítané'
        );
    }
}

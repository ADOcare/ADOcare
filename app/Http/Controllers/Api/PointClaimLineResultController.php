<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PointClaimLine;
use App\Models\PointClaimLineResult;
use Illuminate\Http\Request;

class PointClaimLineResultController extends Controller
{
    public function store(Request $request, PointClaimLine $pointClaimLine)
    {
        $pointClaimLine->loadMissing('batch');
        abort_unless(
            $request->user()?->isInBranch((int) $pointClaimLine->batch->branch_id),
            403
        );

        $data = $request->validate([
            'status' => ['required', 'string', 'in:accepted,rejected'],
            'error_code' => ['nullable', 'string', 'max:100'],
            'message' => ['nullable', 'string', 'max:2000'],
            'accepted_quantity' => ['nullable', 'integer', 'min:0'],
            'accepted_amount' => ['nullable', 'numeric', 'min:0'],
            'received_at' => ['nullable', 'date'],
            'raw_data' => ['nullable', 'array'],
        ]);

        $result = PointClaimLineResult::create([
            ...$data,
            'claim_line_id' => $pointClaimLine->id,
            'received_at' => $data['received_at'] ?? now(),
        ]);

        $pointClaimLine->update(['status' => $data['status']]);

        return $this->success($result, 'Výsledok spracovania výkonu bol uložený', 201);
    }
}

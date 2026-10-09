<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CreateMonthlyExports;
use App\Models\Branch;
use App\Models\Document;
use App\Models\MonthlyExportRun;
use App\Services\MonthlyExportArchiveService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonthlyExportController extends Controller
{
    public function __construct(private MonthlyExportArchiveService $archives)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        $actor = $request->user();
        $branch = Branch::findOrFail((int) $data['branch_id']);
        abort_unless(
            $actor
            && $actor->isInBranch((int) $branch->id)
            && ($actor->hasGlobalRole('nurse') || $actor->hasBranchRole((int) $branch->id, 'nurse')),
            403,
        );

        $month = CarbonImmutable::createFromFormat('!Y-m', $data['month'])->startOfMonth();
        abort_if($month->isFuture() && !$month->isCurrentMonth(), 422, 'Budúci mesiac nie je možné uzavrieť.');

        $activeRun = MonthlyExportRun::query()
            ->where('user_id', $actor->id)
            ->where('branch_id', $branch->id)
            ->whereDate('month', $month->toDateString())
            ->whereIn('status', ['pending', 'processing'])
            ->latest('id')
            ->first();

        if ($activeRun) {
            return $this->runResponse($activeRun, 200);
        }

        $run = MonthlyExportRun::create([
            'user_id' => $actor->id,
            'branch_id' => $branch->id,
            'month' => $month->toDateString(),
            'status' => 'pending',
            'results' => [],
        ]);

        CreateMonthlyExports::dispatch($run->id);

        return $this->runResponse($run, 202);
    }

    public function show(Request $request, MonthlyExportRun $monthlyExportRun): JsonResponse
    {
        abort_unless((int) $monthlyExportRun->user_id === (int) $request->user()?->id, 403);

        return $this->runResponse($monthlyExportRun->fresh(), 200);
    }

    public function download(Request $request, MonthlyExportRun $monthlyExportRun)
    {
        return $this->archives->download($monthlyExportRun, $request->user());
    }

    private function runResponse(MonthlyExportRun $run, int $status): JsonResponse
    {
        $results = collect($run->results ?? []);
        $documentIds = $results
            ->pluck('document_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();
        $existingDocumentIds = Document::query()
            ->whereIn('id', $documentIds)
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => true]);

        $visibleResults = $results
            ->filter(function (array $result) use ($existingDocumentIds): bool {
                $documentId = (int) ($result['document_id'] ?? 0);

                if ($documentId === 0) {
                    return in_array($result['status'] ?? null, ['failed', 'skipped'], true);
                }

                return $existingDocumentIds->has($documentId);
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $run->id,
                'month' => substr((string) $run->getRawOriginal('month'), 0, 7),
                'branch_id' => $run->branch_id,
                'status' => $run->status,
                'current_step' => $run->current_step,
                'results' => $visibleResults,
                'error_message' => $run->error_message,
                'started_at' => $run->started_at,
                'completed_at' => $run->completed_at,
            ],
        ], $status);
    }
}
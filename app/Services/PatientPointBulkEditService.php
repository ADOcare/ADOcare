<?php

namespace App\Services;

use App\Models\PatientPoint;
use App\Models\User;
use App\Services\DocumentationAudit\ImmediateDocumentationAuditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Atomically updates compatible points while retaining their individual dates. */
class PatientPointBulkEditService
{
    private const SHARED_FIELDS = [
        'patient_id', 'branch_id', 'user_id',
        'diagnosis_id', 'diagnosis_code', 'procedure_id', 'procedure_code',
        'quantity', 'reference_date', 'doctor_id', 'doctor_pzs', 'doctor_zpr',
    ];

    public function __construct(
        private VisitTimelineFreshnessService $timeline,
        private ImmediateDocumentationAuditService $audit,
    ) {}

    /**
     * Find matching records within the selected baseline's calendar month.
     *
     * @return Builder<PatientPoint>
     */
    public function matching(User $user, PatientPoint $baseline): Builder
    {
        $this->authorizePoint($user, $baseline);
        $query = PatientPoint::query()
            ->whereDate('date', '>=', $baseline->date->copy()->startOfMonth()->toDateString())
            ->whereDate('date', '<', $baseline->date->copy()->startOfMonth()->addMonth()->toDateString());
        foreach (self::SHARED_FIELDS as $field) {
            $query->where($field, $baseline->getRawOriginal($field));
        }

        return $query->orderBy('date')->orderBy('id');
    }

    /**
     * Apply shared scoring properties after checking every selected point.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, PatientPoint>
     */
    public function update(User $user, array $data): Collection
    {
        $points = DB::transaction(function () use ($user, $data) {
            $points = PatientPoint::query()->whereIn('id', $data['ids'])->orderBy('id')->lockForUpdate()->get();
            if ($points->isEmpty() || $points->count() !== count($data['ids'])) {
                throw ValidationException::withMessages(['ids' => 'Vyberte aspoň jeden existujúci záznam.']);
            }

            foreach ($points as $point) {
                $this->authorizePoint($user, $point);
            }

            $first = $points->first();
            foreach ($points as $point) {
                foreach (self::SHARED_FIELDS as $field) {
                    if ($point->getRawOriginal($field) !== $first->getRawOriginal($field)) {
                        throw ValidationException::withMessages([
                            'ids' => 'Hromadná úprava je možná iba pre záznamy s rovnakými spoločnými hodnotami.',
                        ]);
                    }
                }
            }

            $diagnosis = DB::table('diagnoses')->where('id', $data['diagnosis_id'])->first();
            $procedure = DB::table('procedures')->where('id', $data['procedure_id'])->first();
            if (! $diagnosis || ! $procedure) {
                throw ValidationException::withMessages(['procedure_id' => 'Vyberte existujúcu diagnózu a výkon.']);
            }
            $changes = [
                'diagnosis_id' => $diagnosis->id,
                'diagnosis_code' => $diagnosis->code,
                'procedure_id' => $procedure->id,
                'procedure_code' => $procedure->code,
                'quantity' => $data['quantity'],
                'reference_date' => $data['reference_date'],
            ];
            foreach ($points as $point) {
                $point->update($changes);
            }

            return $points;
        });

        $this->timeline->invalidatePoints($points);
        $this->audit->patientPointsChanged($points);

        return $points;
    }

    private function authorizePoint(User $user, PatientPoint $point): void
    {
        $companyId = $point->patient?->branch?->company_id;
        if (! $companyId || (! $user->hasGlobalRole('superadmin') && (int) $user->company_id !== (int) $companyId)) {
            throw new AuthorizationException('Nemáte oprávnenie upraviť vybrané záznamy.');
        }
        Gate::forUser($user)->authorize('update', $point);
    }
}

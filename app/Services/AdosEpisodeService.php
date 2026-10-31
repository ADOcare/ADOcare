<?php

namespace App\Services;

use App\Models\AdosEpisode;
use App\Models\AdosEpisodeType;
use App\Models\Patient;
use App\Models\User;
use App\Policies\PatientPolicy;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns episode lifecycle rules and keeps the type timeline synchronized.
 */
class AdosEpisodeService
{
    public function __construct(private PatientPolicy $patientPolicy) {}

    /**
     * Create an active episode and its initial type-history row.
     */
    public function startEpisode(
        Patient $patient,
        AdosEpisodeType $type,
        CarbonInterface $startedAt,
        User $user,
        ?CarbonInterface $expectedEndAt = null,
        bool $expectedEndWasProvided = false,
    ): AdosEpisode {
        $this->authorizePatient($patient, $user);
        $companyId = $this->patientCompanyId($patient);

        if ((int) $type->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'episode_type_id' => 'Typ epizódy nepatrí spoločnosti pacienta.',
            ]);
        }

        if (! $type->is_active) {
            throw ValidationException::withMessages([
                'episode_type_id' => 'Neaktívny typ epizódy nie je možné použiť.',
            ]);
        }

        if ($patient->adosEpisodes()->where('status', 'active')->exists()) {
            throw ValidationException::withMessages([
                'patient' => 'Pacient už má aktívnu ADOS epizódu.',
            ]);
        }

        if ($expectedEndWasProvided) {
            if ($expectedEndAt && $expectedEndAt->toDateString() < $startedAt->toDateString()) {
                throw ValidationException::withMessages([
                    'expected_end_at' => 'Predpokladaný koniec nemôže byť pred začiatkom epizódy.',
                ]);
            }
        } elseif ($type->default_duration_days !== null) {
            $expectedEndAt = $startedAt->copy()->addDays($type->default_duration_days);
        }

        return DB::transaction(function () use ($patient, $type, $startedAt, $user, $expectedEndAt, $companyId) {
            $episode = AdosEpisode::query()->create([
                'patient_id' => $patient->id,
                'branch_id' => $patient->branch_id,
                'company_id' => $companyId,
                'episode_type_id' => $type->id,
                'status' => 'active',
                'started_at' => $startedAt->toDateString(),
                'expected_end_at' => $expectedEndAt?->toDateString(),
                'created_by' => $user->id,
            ]);

            $episode->typeHistory()->create([
                'episode_type_id' => $type->id,
                'valid_from' => $startedAt->toDateString(),
                'changed_by' => $user->id,
            ]);

            return $episode->load(['type', 'typeHistory.type', 'creator', 'endedBy']);
        });
    }

    /**
     * Change the active episode type from the effective date onward.
     */
    public function changeType(
        AdosEpisode $episode,
        AdosEpisodeType $newType,
        CarbonInterface $effectiveFrom,
        User $user,
    ): AdosEpisode {
        $episode->loadMissing('patient');
        $this->authorizePatient($episode->patient, $user);
        $this->assertEpisodeCompany($episode);
        $this->assertActive($episode);

        if ((int) $newType->company_id !== (int) $episode->company_id) {
            throw ValidationException::withMessages([
                'episode_type_id' => 'Typ epizódy nepatrí spoločnosti pacienta.',
            ]);
        }

        if (! $newType->is_active) {
            throw ValidationException::withMessages([
                'episode_type_id' => 'Neaktívny typ epizódy nie je možné použiť.',
            ]);
        }

        if ((int) $newType->id === (int) $episode->episode_type_id) {
            throw ValidationException::withMessages([
                'episode_type_id' => 'Nový typ musí byť odlišný od aktuálneho typu.',
            ]);
        }

        $transitionAllowed = $episode->type
            ->outgoingTransitions()
            ->where('company_id', $episode->company_id)
            ->where('to_episode_type_id', $newType->id)
            ->exists();
        if (! $transitionAllowed) {
            throw ValidationException::withMessages([
                'episode_type_id' => 'Zmena na zvolený typ epizódy nie je povolená.',
            ]);
        }

        $currentHistory = $episode->typeHistory()->whereNull('valid_to')->latest('valid_from')->first();
        if (! $currentHistory) {
            throw ValidationException::withMessages([
                'episode' => 'Aktuálna história typu epizódy sa nenašla.',
            ]);
        }

        if ($effectiveFrom->toDateString() < $currentHistory->valid_from->toDateString()) {
            throw ValidationException::withMessages([
                'effective_from' => 'Dátum zmeny nemôže byť pred začiatkom aktuálneho typu.',
            ]);
        }

        if ($effectiveFrom->toDateString() < $episode->started_at->toDateString()) {
            throw ValidationException::withMessages([
                'effective_from' => 'Dátum zmeny nemôže byť pred začiatkom epizódy.',
            ]);
        }

        return DB::transaction(function () use ($episode, $newType, $effectiveFrom, $user, $currentHistory) {
            $currentHistory->valid_to = $effectiveFrom->toDateString();
            $currentHistory->save();

            $episode->typeHistory()->create([
                'episode_type_id' => $newType->id,
                'valid_from' => $effectiveFrom->toDateString(),
                'changed_by' => $user->id,
            ]);

            $episode->episode_type_id = $newType->id;
            $episode->save();

            return $episode->fresh()->load(['type', 'typeHistory.type', 'creator', 'endedBy']);
        });
    }

    /**
     * Close an active episode and its current type-history range.
     */
    public function closeEpisode(AdosEpisode $episode, CarbonInterface $endedAt, User $user): AdosEpisode
    {
        $episode->loadMissing('patient');
        $this->authorizePatient($episode->patient, $user);
        $this->assertEpisodeCompany($episode);
        $this->assertActive($episode);

        if ($endedAt->toDateString() < $episode->started_at->toDateString()) {
            throw ValidationException::withMessages([
                'ended_at' => 'Dátum ukončenia nemôže byť pred začiatkom epizódy.',
            ]);
        }

        return DB::transaction(function () use ($episode, $endedAt, $user) {
            $currentHistory = $episode->typeHistory()->whereNull('valid_to')->latest('valid_from')->first();
            if (! $currentHistory || $endedAt->toDateString() < $currentHistory->valid_from->toDateString()) {
                throw ValidationException::withMessages([
                    'ended_at' => 'Dátum ukončenia nemôže byť pred začiatkom aktuálneho typu.',
                ]);
            }

            $currentHistory->valid_to = $endedAt->copy()->addDay()->toDateString();
            $currentHistory->save();

            $episode->status = 'closed';
            $episode->ended_at = $endedAt->toDateString();
            $episode->ended_by = $user->id;
            $episode->save();

            return $episode->fresh()->load(['type', 'typeHistory.type', 'creator', 'endedBy']);
        });
    }

    private function authorizePatient(Patient $patient, User $user): void
    {
        if (! $this->patientPolicy->view($user, $patient)) {
            throw new AuthorizationException('Nemáte oprávnenie pracovať s pacientom.');
        }
    }

    private function patientCompanyId(Patient $patient): int
    {
        $companyId = $patient->branch?->company_id;
        if (! $companyId) {
            throw ValidationException::withMessages([
                'patient' => 'Pacient nemá priradenú spoločnosť.',
            ]);
        }

        return (int) $companyId;
    }

    private function assertEpisodeCompany(AdosEpisode $episode): void
    {
        if ((int) $episode->company_id !== $this->patientCompanyId($episode->patient)) {
            throw (new ModelNotFoundException)->setModel(AdosEpisode::class, [$episode->id]);
        }
    }

    private function assertActive(AdosEpisode $episode): void
    {
        if ($episode->status !== 'active') {
            throw ValidationException::withMessages([
                'episode' => 'Ukončenú ADOS epizódu už nie je možné meniť.',
            ]);
        }
    }
}

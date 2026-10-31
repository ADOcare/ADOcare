<?php

namespace App\Services;

use App\Models\AdosEpisode;
use App\Models\Patient;
use App\Models\User;
use App\Policies\PatientPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Provides reusable patient access and active-episode checks for future workflows.
 */
class AdosEpisodeAccessService
{
    public function __construct(private PatientPolicy $patientPolicy) {}

    /**
     * Return the patient's active episode, if one exists.
     */
    public function activeEpisodeFor(Patient $patient): ?AdosEpisode
    {
        return $patient->activeAdosEpisode()->with(['type', 'typeHistory.type'])->first();
    }

    /**
     * Ensure the user can access the patient and that the patient has an active episode.
     */
    public function requiresActiveEpisode(Patient $patient, User $user): AdosEpisode
    {
        if (! $this->patientPolicy->view($user, $patient)) {
            throw new AuthorizationException('Nemáte oprávnenie pracovať s pacientom.');
        }

        $episode = $this->activeEpisodeFor($patient);
        if (! $episode) {
            throw ValidationException::withMessages([
                'ados_episode' => 'Pacient nemá aktívnu ADOS epizódu.',
            ]);
        }

        return $episode;
    }
}

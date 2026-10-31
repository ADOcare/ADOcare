<?php

namespace App\Services;

use App\Models\AdosEpisodeType;
use App\Models\AdosEpisodeTypeTransition;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Applies company ownership and configuration rules to ADOS episode settings.
 */
class AdosEpisodeConfigurationService
{
    /**
     * Ensure a manager can configure their own company, or a superadmin the selected company.
     */
    public function authorizeCompany(User $user, Company $company): void
    {
        $this->authorizeCompanyAccess($user, $company);

        if ($user->hasGlobalRole('superadmin') || $user->hasGlobalRole('manager')) {
            return;
        }

        throw new AuthorizationException('Nemáte oprávnenie spravovať nastavenia spoločnosti.');
    }

    /**
     * Ensure a user can read configuration for their own company.
     */
    public function authorizeCompanyAccess(User $user, Company $company): void
    {
        if (! $user->hasGlobalRole('superadmin') && (int) $user->company_id !== (int) $company->id) {
            throw new AuthorizationException('Nemáte oprávnenie pristupovať k nastaveniam spoločnosti.');
        }
    }

    /**
     * Return company types, optionally including inactive records for managers.
     *
     * @return Collection<int, AdosEpisodeType>
     */
    public function typesFor(Company $company, bool $includeInactive): Collection
    {
        return AdosEpisodeType::query()
            ->where('company_id', $company->id)
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Create a type within the authorized company.
     *
     * @param  array<string, mixed>  $data
     */
    public function createType(Company $company, array $data): AdosEpisodeType
    {
        return $company->episodeTypes()->create($data);
    }

    /**
     * Update a type only when it belongs to the selected company.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateType(Company $company, AdosEpisodeType $type, array $data): AdosEpisodeType
    {
        $this->assertTypeOwnedByCompany($type, $company);
        $type->update($data);

        return $type->refresh();
    }

    /**
     * Return configured transitions for the selected company.
     *
     * @return Collection<int, AdosEpisodeTypeTransition>
     */
    public function transitionsFor(Company $company): Collection
    {
        return AdosEpisodeTypeTransition::query()
            ->where('company_id', $company->id)
            ->with(['fromType', 'toType'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Create a valid transition between two types from the selected company.
     */
    public function createTransition(
        Company $company,
        AdosEpisodeType $fromType,
        AdosEpisodeType $toType,
    ): AdosEpisodeTypeTransition {
        $this->assertTypeOwnedByCompany($fromType, $company);
        $this->assertTypeOwnedByCompany($toType, $company);

        if ((int) $fromType->id === (int) $toType->id) {
            throw ValidationException::withMessages([
                'to_episode_type_id' => 'Zdrojový a cieľový typ musia byť odlišné.',
            ]);
        }

        if (AdosEpisodeTypeTransition::query()
            ->where('company_id', $company->id)
            ->where('from_episode_type_id', $fromType->id)
            ->where('to_episode_type_id', $toType->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'to_episode_type_id' => 'Tento prechod je už nakonfigurovaný.',
            ]);
        }

        return $company->episodeTypeTransitions()->create([
            'from_episode_type_id' => $fromType->id,
            'to_episode_type_id' => $toType->id,
        ]);
    }

    /**
     * Delete a transition only when it belongs to the selected company.
     */
    public function deleteTransition(Company $company, AdosEpisodeTypeTransition $transition): void
    {
        if ((int) $transition->company_id !== (int) $company->id) {
            throw (new ModelNotFoundException)->setModel(AdosEpisodeTypeTransition::class, [$transition->id]);
        }

        $transition->delete();
    }

    private function assertTypeOwnedByCompany(AdosEpisodeType $type, Company $company): void
    {
        if ((int) $type->company_id !== (int) $company->id) {
            throw ValidationException::withMessages([
                'episode_type_id' => 'Typ epizódy nepatrí zvolenej spoločnosti.',
            ]);
        }
    }
}

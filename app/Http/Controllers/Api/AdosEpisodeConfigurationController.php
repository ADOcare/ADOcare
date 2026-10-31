<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdosEpisodeTransitionRequest;
use App\Http\Requests\StoreAdosEpisodeTypeRequest;
use App\Http\Requests\UpdateAdosEpisodeTypeRequest;
use App\Http\Resources\AdosEpisodeTypeResource;
use App\Http\Resources\AdosEpisodeTypeTransitionResource;
use App\Models\AdosEpisodeType;
use App\Models\AdosEpisodeTypeTransition;
use App\Models\Company;
use App\Services\AdosEpisodeConfigurationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Manages company-scoped ADOS episode types and allowed transitions.
 */
class AdosEpisodeConfigurationController extends Controller
{
    public function __construct(private AdosEpisodeConfigurationService $configuration) {}

    /**
     * List episode types available in the authenticated user's company.
     *
     * @group ADOS episodes
     *
     * @response 200 {"message":"Typy ADOS epizód boli načítané.","data":[]}
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $company = $user?->company;
        if (! $company) {
            return $this->forbidden('Nie je určená spoločnosť používateľa.');
        }

        $this->configuration->authorizeCompanyAccess($user, $company);
        $includeInactive = $user->hasGlobalRole('manager') || $user->hasGlobalRole('superadmin');
        $types = $this->configuration->typesFor($company, $includeInactive);

        return $this->success(AdosEpisodeTypeResource::collection($types), 'Typy ADOS epizód boli načítané.');
    }

    /**
     * List episode types for an explicitly selected company.
     *
     * @group ADOS episodes
     *
     * @urlParam company int required ID spoločnosti.
     *
     * @response 200 {"message":"Typy ADOS epizód boli načítané.","data":[]}
     */
    public function indexForCompany(Request $request, Company $company)
    {
        $this->configuration->authorizeCompany($request->user(), $company);
        $types = $this->configuration->typesFor($company, true);

        return $this->success(AdosEpisodeTypeResource::collection($types), 'Typy ADOS epizód boli načítané.');
    }

    /**
     * Create an episode type in the selected company.
     *
     * @group ADOS episodes
     *
     * @urlParam company int required ID spoločnosti.
     *
     * @bodyParam name string required Názov typu.
     * @bodyParam code string required Jedinečný technický kód.
     *
     * @response 201 {"message":"Typ ADOS epizódy bol vytvorený.","data":{"id":1}}
     */
    public function store(StoreAdosEpisodeTypeRequest $request, Company $company)
    {
        $type = $this->configuration->createType($company, $request->validated());

        return $this->success(new AdosEpisodeTypeResource($type), 'Typ ADOS epizódy bol vytvorený.', Response::HTTP_CREATED);
    }

    /**
     * Create an episode type in the authenticated manager's company.
     *
     * @group ADOS episodes
     *
     * @bodyParam name string required Názov typu.
     * @bodyParam code string required Jedinečný technický kód.
     *
     * @response 201 {"message":"Typ ADOS epizódy bol vytvorený.","data":{"id":1}}
     */
    public function storeForCurrentCompany(StoreAdosEpisodeTypeRequest $request)
    {
        $company = $this->currentCompany($request);
        $type = $this->configuration->createType($company, $request->validated());

        return $this->success(new AdosEpisodeTypeResource($type), 'Typ ADOS epizódy bol vytvorený.', Response::HTTP_CREATED);
    }

    /**
     * Update editable settings for a company episode type.
     *
     * @group ADOS episodes
     *
     * @urlParam company int required ID spoločnosti.
     * @urlParam episodeType int required ID typu epizódy.
     *
     * @bodyParam name string Názov typu.
     *
     * @response 200 {"message":"Typ ADOS epizódy bol aktualizovaný.","data":{"id":1}}
     */
    public function update(UpdateAdosEpisodeTypeRequest $request, Company $company, AdosEpisodeType $episodeType)
    {
        $type = $this->configuration->updateType($company, $episodeType, $request->validated());

        return $this->success(new AdosEpisodeTypeResource($type), 'Typ ADOS epizódy bol aktualizovaný.');
    }

    /**
     * Update a type in the authenticated manager's company.
     *
     * @group ADOS episodes
     *
     * @urlParam episodeType int required ID typu epizódy.
     *
     * @bodyParam name string Názov typu.
     *
     * @response 200 {"message":"Typ ADOS epizódy bol aktualizovaný.","data":{"id":1}}
     */
    public function updateForCurrentCompany(UpdateAdosEpisodeTypeRequest $request, AdosEpisodeType $episodeType)
    {
        $company = $this->currentCompany($request);
        $type = $this->configuration->updateType($company, $episodeType, $request->validated());

        return $this->success(new AdosEpisodeTypeResource($type), 'Typ ADOS epizódy bol aktualizovaný.');
    }

    /**
     * List episode-type transitions for the authenticated user's company.
     *
     * @group ADOS episodes
     *
     * @response 200 {"message":"Prechody ADOS epizód boli načítané.","data":[]}
     */
    public function transitionsIndex(Request $request)
    {
        $user = $request->user();
        $company = $user?->company;
        if (! $company) {
            return $this->forbidden('Nie je určená spoločnosť používateľa.');
        }

        $this->configuration->authorizeCompanyAccess($user, $company);

        return $this->success(
            AdosEpisodeTypeTransitionResource::collection($this->configuration->transitionsFor($company)),
            'Prechody ADOS epizód boli načítané.'
        );
    }

    /**
     * List transitions for an explicitly selected company.
     *
     * @group ADOS episodes
     *
     * @urlParam company int required ID spoločnosti.
     *
     * @response 200 {"message":"Prechody ADOS epizód boli načítané.","data":[]}
     */
    public function transitionsIndexForCompany(Request $request, Company $company)
    {
        $this->configuration->authorizeCompany($request->user(), $company);

        return $this->success(
            AdosEpisodeTypeTransitionResource::collection($this->configuration->transitionsFor($company)),
            'Prechody ADOS epizód boli načítané.'
        );
    }

    /**
     * Create a configured transition between two episode types.
     *
     * @group ADOS episodes
     *
     * @urlParam company int required ID spoločnosti.
     *
     * @bodyParam from_episode_type_id int required Zdrojový typ.
     * @bodyParam to_episode_type_id int required Cieľový typ.
     *
     * @response 201 {"message":"Prechod ADOS epizód bol vytvorený.","data":{"id":1}}
     */
    public function storeTransition(StoreAdosEpisodeTransitionRequest $request, Company $company)
    {
        $data = $request->validated();
        $fromType = AdosEpisodeType::query()->findOrFail($data['from_episode_type_id']);
        $toType = AdosEpisodeType::query()->findOrFail($data['to_episode_type_id']);
        $transition = $this->configuration->createTransition($company, $fromType, $toType);

        return $this->success(
            new AdosEpisodeTypeTransitionResource($transition->load(['fromType', 'toType'])),
            'Prechod ADOS epizód bol vytvorený.',
            Response::HTTP_CREATED
        );
    }

    /**
     * Add an allowed transition within the authenticated manager's company.
     *
     * @group ADOS episodes
     *
     * @bodyParam from_episode_type_id int required Zdrojový typ.
     * @bodyParam to_episode_type_id int required Cieľový typ.
     *
     * @response 201 {"message":"Prechod ADOS epizód bol vytvorený.","data":{"id":1}}
     */
    public function storeTransitionForCurrentCompany(StoreAdosEpisodeTransitionRequest $request)
    {
        $company = $this->currentCompany($request);
        $data = $request->validated();
        $fromType = AdosEpisodeType::query()->findOrFail($data['from_episode_type_id']);
        $toType = AdosEpisodeType::query()->findOrFail($data['to_episode_type_id']);
        $transition = $this->configuration->createTransition($company, $fromType, $toType);

        return $this->success(
            new AdosEpisodeTypeTransitionResource($transition->load(['fromType', 'toType'])),
            'Prechod ADOS epizód bol vytvorený.',
            Response::HTTP_CREATED
        );
    }

    /**
     * Remove an allowed transition from the selected company.
     *
     * @group ADOS episodes
     *
     * @urlParam company int required ID spoločnosti.
     * @urlParam transition int required ID prechodu.
     *
     * @response 200 {"message":"Prechod ADOS epizód bol odstránený.","data":null}
     */
    public function destroyTransition(Request $request, Company $company, AdosEpisodeTypeTransition $transition)
    {
        $this->configuration->authorizeCompany($request->user(), $company);
        $this->configuration->deleteTransition($company, $transition);

        return $this->success(null, 'Prechod ADOS epizód bol odstránený.');
    }

    /**
     * Remove a transition in the authenticated manager's company.
     *
     * @group ADOS episodes
     *
     * @urlParam transition int required ID prechodu.
     *
     * @response 200 {"message":"Prechod ADOS epizód bol odstránený.","data":null}
     */
    public function destroyTransitionForCurrentCompany(Request $request, AdosEpisodeTypeTransition $transition)
    {
        $company = $this->currentCompany($request);
        $this->configuration->deleteTransition($company, $transition);

        return $this->success(null, 'Prechod ADOS epizód bol odstránený.');
    }

    private function currentCompany(Request $request): Company
    {
        $user = $request->user();
        $company = $user?->company;
        if (! $user || ! $company) {
            throw new AuthorizationException('Nie je určená spoločnosť používateľa.');
        }

        $this->configuration->authorizeCompany($user, $company);

        return $company;
    }
}

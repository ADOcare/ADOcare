<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeAdosEpisodeTypeRequest;
use App\Http\Requests\CloseAdosEpisodeRequest;
use App\Http\Requests\StartAdosEpisodeRequest;
use App\Http\Resources\AdosEpisodeResource;
use App\Models\AdosEpisode;
use App\Models\AdosEpisodeType;
use App\Models\Patient;
use App\Services\AdosEpisodeService;
use Carbon\Carbon;
use Illuminate\Http\Response;

/**
 * Provides patient-scoped episode queries and nurse lifecycle operations.
 */
class AdosEpisodeController extends Controller
{
    public function __construct(private AdosEpisodeService $episodes) {}

    /**
     * List the patient's episodes, newest first.
     *
     * @group ADOS episodes
     *
     * @urlParam patient int required ID pacienta.
     *
     * @response 200 {"message":"ADOS epizódy pacienta boli načítané.","data":[]}
     */
    public function index(Patient $patient)
    {
        $episodes = $patient->adosEpisodes()
            ->with(['type.outgoingTransitions.toType', 'typeHistory.type', 'creator', 'endedBy'])
            ->get();

        return $this->success(AdosEpisodeResource::collection($episodes), 'ADOS epizódy pacienta boli načítané.');
    }

    /**
     * Return the patient's active episode, or null when none exists.
     *
     * @group ADOS episodes
     *
     * @urlParam patient int required ID pacienta.
     *
     * @response 200 {"message":"Aktuálna ADOS epizóda bola načítaná.","data":null}
     */
    public function current(Patient $patient)
    {
        $episode = $patient->activeAdosEpisode()
            ->with(['type.outgoingTransitions.toType', 'typeHistory.type', 'creator', 'endedBy'])
            ->first();

        return $this->success(
            $episode ? new AdosEpisodeResource($episode) : null,
            'Aktuálna ADOS epizóda bola načítaná.'
        );
    }

    /**
     * Start a patient episode and record its initial type history.
     *
     * @group ADOS episodes
     *
     * @urlParam patient int required ID pacienta.
     *
     * @bodyParam episode_type_id int required ID aktívneho typu.
     * @bodyParam started_at string required Dátum začiatku vo formáte YYYY-MM-DD.
     * @bodyParam expected_end_at string Predpokladaný koniec vo formáte YYYY-MM-DD.
     *
     * @response 201 {"message":"ADOS epizóda bola vytvorená.","data":{"id":1,"status":"active"}}
     */
    public function store(StartAdosEpisodeRequest $request, Patient $patient)
    {
        $data = $request->validated();
        $type = AdosEpisodeType::query()->findOrFail($data['episode_type_id']);
        $episode = $this->episodes->startEpisode(
            $patient,
            $type,
            Carbon::parse($data['started_at']),
            $request->user(),
            isset($data['expected_end_at']) ? Carbon::parse($data['expected_end_at']) : null,
            $request->exists('expected_end_at'),
        );
        $episode->load(['type.outgoingTransitions.toType', 'typeHistory.type']);

        return $this->success(new AdosEpisodeResource($episode), 'ADOS epizóda bola vytvorená.', Response::HTTP_CREATED);
    }

    /**
     * Change the active episode type using a configured transition.
     *
     * @group ADOS episodes
     *
     * @urlParam episode int required ID epizódy.
     *
     * @bodyParam episode_type_id int required ID nového typu.
     * @bodyParam effective_from string required Dátum účinnosti vo formáte YYYY-MM-DD.
     *
     * @response 200 {"message":"Typ ADOS epizódy bol zmenený.","data":{"id":1}}
     */
    public function changeType(ChangeAdosEpisodeTypeRequest $request, AdosEpisode $episode)
    {
        $data = $request->validated();
        $type = AdosEpisodeType::query()->findOrFail($data['episode_type_id']);
        $episode = $this->episodes->changeType(
            $episode,
            $type,
            Carbon::parse($data['effective_from']),
            $request->user()
        );
        $episode->load(['type.outgoingTransitions.toType', 'typeHistory.type']);

        return $this->success(new AdosEpisodeResource($episode), 'Typ ADOS epizódy bol zmenený.');
    }

    /**
     * Close an active episode and its current type-history range.
     *
     * @group ADOS episodes
     *
     * @urlParam episode int required ID epizódy.
     *
     * @bodyParam ended_at string required Dátum ukončenia vo formáte YYYY-MM-DD.
     *
     * @response 200 {"message":"ADOS epizóda bola ukončená.","data":{"id":1,"status":"closed"}}
     */
    public function close(CloseAdosEpisodeRequest $request, AdosEpisode $episode)
    {
        $data = $request->validated();
        $episode = $this->episodes->closeEpisode(
            $episode,
            Carbon::parse($data['ended_at']),
            $request->user()
        );
        $episode->load(['type.outgoingTransitions.toType', 'typeHistory.type']);

        return $this->success(new AdosEpisodeResource($episode), 'ADOS epizóda bola ukončená.');
    }
}

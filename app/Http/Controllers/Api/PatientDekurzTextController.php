<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePatientDekurzTextsRequest;
use App\Models\Patient;
use App\Services\PatientDekurzTextService;

class PatientDekurzTextController extends Controller
{
    public function __construct(private PatientDekurzTextService $texts) {}

    /**
     * Get the patient's reusable Dekurz texts.
     *
     * Falls back to the texts of the latest Dekurz document when nothing was saved yet.
     *
     * @group Dekurz
     *
     * @urlParam patient int required ID pacienta. Example: 1
     *
     * @response 200 {"message":"OK","data":{"texts":["Pacient je stabilizovaný."]}}
     */
    public function show(Patient $patient)
    {
        $this->authorize('view', $patient);

        return $this->success(['texts' => $this->texts->texts($patient)]);
    }

    /**
     * Save the patient's reusable Dekurz texts.
     *
     * @group Dekurz
     *
     * @urlParam patient int required ID pacienta. Example: 1
     *
     * @bodyParam texts string[] required Texty sekcií v poradí, v akom sa zobrazujú. Example: ["Pacient je stabilizovaný."]
     *
     * @response 200 {"message":"Texty Dekurzu boli uložené.","data":{"texts":["Pacient je stabilizovaný."]}}
     */
    public function update(UpdatePatientDekurzTextsRequest $request, Patient $patient)
    {
        return $this->success(
            ['texts' => $this->texts->save($patient, $request->validated('texts'))],
            'Texty Dekurzu boli uložené.',
        );
    }
}

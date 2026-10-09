<?php

namespace App\Services\Transport;

use App\Models\Branch;
use App\Models\User;
use App\Services\Claims\InsuredClaimResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClaimSelectionService
{
    public function __construct(private RouteService $routes, private InsuredClaimResolver $insuredResolver)
    {
    }

    public function select(array $context, User $actor, Branch $branch, bool $previewOnly = false): array
    {
        $comparison = new TransportClaimComparison();
        $character = $context['type'];
        $operation = $this->insuredResolver->operationForCharacter($character);
        $period = substr($context['from'], 0, 7);
        $scope = [
            'company_id' => $branch->company_id, 'branch_id' => $branch->id,
            'user_id' => $actor->id, 'insurance_company_id' => $context['insuranceId'], 'period' => $period,
        ];
        $newCharacter = match ($character) {
            'E', 'F', 'G' => 'E', 'I', 'J', 'K' => 'I', default => 'N',
        };
        $legacyExists = DB::table('documents as d')
            ->where('d.type', 'kilometers_batch')->where('d.company_id', $branch->company_id)
            ->where('d.branch_id', $branch->id)->where('d.user_id', $actor->id)
            ->where('d.insurance_company_id', $context['insuranceId'])->where('d.period', $period)
            ->whereNull('d.deleted_at')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('transport_claim_batches as b')
                ->whereColumn('b.document_id', 'd.id'))->exists();
        if ($legacyExists) {
            throw ValidationException::withMessages(['transport' => [
                'Pre toto obdobie už existuje stará dopravná dávka bez histórie riadkov. Najprv je potrebné zosúladiť pôvodné podania; automatické doplnenie by mohlo vykázať dopravu opakovane.',
            ]]);
        }
        $carId = isset($context['carId']) ? (int) $context['carId'] : null;
        $car = $this->routes->car($actor, $branch, $carId);
        $days = $this->routes->days($actor, $branch, $context['from'], $context['to'], (int) $car->id);
        $journeyIds = collect($days)->flatMap(fn ($day) => $day['legs'])->pluck('journey_id')->filter()->all();
        $history = $this->history($scope);
        $removedJourneys = array_diff(array_keys($history), $journeyIds);
        $contract = array_replace(
            config('transport.default_contract', []),
            config('transport.contracts.' . $branch->company_id . '.' . $context['insuranceCode'], []),
        );
        if (!($contract['enabled'] ?? true)) {
            throw ValidationException::withMessages(['transport' => ['Doprava je pre túto spoločnosť a poisťovňu vypnutá.']]);
        }
        $existingNew = $operation === 'N'
            ? DB::table('transport_claim_batches as b')
                ->join('documents as d', 'd.id', '=', 'b.document_id')
                ->whereNull('d.deleted_at')
                ->where($this->batchScope($scope))
                ->where('b.character', $newCharacter)
                ->select('b.*')
                ->first()
            : null;
        $candidates = [];
        $blocked = $existingNew ? [['date' => '', 'address' => '', 'reason' => 'Nová dávka už je uložená ako dokument ' . $existingNew->document_id . '. Použite jej pôvodný súbor alebo zvoľte opravnú či aditívnu dávku.']] : [];
        $routeChanges = array_map(function ($id) use ($history) {
            $saved = json_decode($history[$id]->payload, true, 512, JSON_THROW_ON_ERROR);
            return ['journey_id' => (int) $id, 'date' => $saved['date'] ?? '',
                'before' => $saved['route_data'] ?? null, 'after' => null,
                'reason' => 'Pôvodne vykázaná návšteva už nie je v aktuálnej trase.'];
        }, array_values($removedJourneys));
        // Index once; a monthly preview must not scan every point for every leg.
        $rowsByDay = $context['rows']->groupBy(fn ($row) => substr((string) $row->date, 0, 10));
        $patientFilter = array_map('intval', $context['patientIds']);
        foreach ($days as $day) {
            foreach ($day['legs'] as $leg) {
                if ($leg['is_return']) {
                    continue;
                }
                $previous = $history[$leg['journey_id']] ?? null;
                $previousPayload = $previous ? json_decode($previous->payload, true, 512, JSON_THROW_ON_ERROR) : null;
                $reportedKm = $this->reportedKilometers((int) $leg['distance_m'], $contract);
                $routeData = [$leg['origin'], $leg['destination'], $reportedKm, $car->evc];
                if ($previousPayload && ($previousPayload['route_data'] ?? null) !== $routeData) {
                    $routeChanges[] = ['journey_id' => $leg['journey_id'], 'date' => $day['date'],
                        'before' => $previousPayload['route_data'] ?? null, 'after' => $routeData,
                        'reason' => 'Zmenil sa už vykázaný úsek alebo vozidlo.'];
                }
                $matches = ($rowsByDay[$day['date']] ?? collect())->filter(fn ($row) => in_array((int) $row->patient_id, $leg['patient_ids'], true))->sortBy('id')->values();
                // Address changes must not make a previously billed visit look like a new journey.
                $moved = !$previous && collect($history)->contains(function ($line) use ($matches, $day) {
                    $saved = json_decode($line->payload, true, 512, JSON_THROW_ON_ERROR);
                    return ($saved['date'] ?? null) === $day['date']
                        && $matches->contains(fn ($match) => (int) $match->patient_id === (int) $line->patient_id
                            || (int) $match->id === (int) $line->patient_point_id);
                });
                if ($moved) {
                    $blocked[] = ['date' => $day['date'], 'address' => $this->address($leg['destination']),
                        'reason' => 'Návšteva už bola vykázaná na inej adrese. Nie je novou dopravou; pôvodné podanie vyžaduje preverenie.'];
                    continue;
                }
                // Keep the previously billed patient/point even when another patient is added at the same stop.
                $row = $previous
                    ? ($matches->firstWhere('id', $previous->patient_point_id)
                        ?? $matches->firstWhere('patient_id', $previous->patient_id))
                    : $matches->first(fn ($candidate) => $this->insuredResolver->resolve($candidate, $operation)->isValid());
                if (!$row) {
                    if ($matches->isNotEmpty() || $previous) {
                        $blocked[] = ['date' => $day['date'], 'address' => $this->address($leg['destination']),
                            'reason' => $previous ? 'Chýba pôvodný poistenec alebo výkon 3439/3440. Zmena vykázanej identity vyžaduje samostatné preverenie.' : 'Žiadny poistenec na zastávke nemá úplné údaje na vykázanie.'];
                    }
                    continue;
                }
                $resolved = $this->insuredResolver->resolve($row, $operation);
                if ($resolved->character !== $character) {
                    // The representative is chosen across all regimes BEFORE filtering the requested character.
                    continue;
                }
                if ($previous && $this->newCharacter($previous->character) !== $newCharacter) {
                    $blocked[] = ['date' => $day['date'], 'address' => $this->address($leg['destination']),
                        'reason' => 'Táto návšteva už bola vykázaná v inom poistnom režime. Nebude sa účtovať druhýkrát.'];
                    continue;
                }
                if ((in_array($operation, ['N', 'A'], true) && $previous) || ($operation === 'O' && !$previous)) {
                    continue;
                }
                if ($patientFilter !== [] && !in_array((int) $row->patient_id, $patientFilter, true)) {
                    continue;
                }
                $reasons = $resolved->errors;
                if (!is_numeric($row->price) || (float) $row->price < 0) {
                    $reasons[] = 'Chýba cena dopravy 0000 pre túto spoločnosť a poisťovňu.';
                }
                if (isset($contract['max_leg_km']) && $leg['distance_m'] / 1000 > (float) $contract['max_leg_km']) {
                    $reasons[] = 'Úsek prekračuje nastavený zmluvný limit kilometrov; kilometre neboli automaticky skrátené.';
                }
                if ($reasons !== []) {
                    $blocked[] = ['date' => $day['date'], 'address' => $this->address($leg['destination']), 'reason' => implode(' ', $reasons)];
                    continue;
                }
                $source = (array) $row;
                $source['journey_id'] = $leg['journey_id'];
                $source['branch_city'] = $leg['origin']['city'];
                $source['branch_address'] = $leg['origin']['address'];
                $source['patient_city'] = $leg['destination']['city'];
                $source['patient_address'] = $leg['destination']['address'];
                $source['reported_km'] = $reportedKm;
                $fingerprint = $this->fingerprint($source, $routeData);
                $exportFields = $comparison->snapshot($source, (string) $car->evc);
                $previousFields = $previousPayload
                    ? ($previousPayload['export_fields'] ?? $comparison->snapshot(
                        $previousPayload['row'] ?? [], (string) ($previousPayload['route_data'][3] ?? '')
                    )) : null;
                $changes = $previousFields === null ? [] : $comparison->changes($previousFields, $exportFields);
                $changed = $changes !== [];
                $sourceChanges = [];
                foreach (['procedure_code' => 'Súvisiaci výkon', 'price' => 'Cena za km'] as $field => $label) {
                    if ($previousPayload && (string) ($previousPayload['row'][$field] ?? '') !== (string) ($source[$field] ?? '')) {
                        $sourceChanges[] = ['field' => $field, 'label' => $label,
                            'before' => (string) ($previousPayload['row'][$field] ?? ''),
                            'after' => (string) ($source[$field] ?? '')];
                    }
                }
                $candidates[] = [
                    'journey_id' => $leg['journey_id'], 'point_id' => (int) $row->id,
                    'patient_id' => (int) $row->patient_id,
                    'patient_name' => trim($row->first_name . ' ' . $row->last_name),
                    'date' => $day['date'], 'origin' => $this->address($leg['origin']),
                    'destination' => $this->address($leg['destination']),
                    'kilometers' => $reportedKm, 'calculated_km' => $leg['distance_m'] / 1000,
                    'amount' => round($reportedKm * (float) $row->price, 2),
                    'procedure_code' => $row->procedure_code,
                    'updated_at' => $row->updated_at, 'created_at' => $row->created_at,
                    'suggested' => $operation !== 'O' || $changed,
                    'edited' => $changed, 'previous_line_id' => $previous?->id,
                    'previous_document_id' => $previous?->document_id,
                    'previous_character' => $previous?->character,
                    'previous_kilometers' => $previousPayload['kilometers'] ?? null,
                    'diagnosis_code' => $row->diagnosis_code ?? '',
                    'changes' => $changes, 'source_changes' => $sourceChanges,
                    'export_fields' => $exportFields,
                    'status' => $previous ? ($changed ? 'changed' : 'unchanged') : 'new',
                    'fingerprint' => $fingerprint, 'row' => $source,
                    'route_data' => $routeData, 'route_fingerprint' => $day['fingerprint'],
                ];
            }
        }
        if ($operation === 'A' && $routeChanges !== []) {
            $conflictDates = array_unique(array_column($routeChanges, 'date'));
            foreach ($routeChanges as $change) {
                $blocked[] = ['date' => $change['date'], 'address' => '',
                    'journey_id' => $change['journey_id'], 'reason' => $change['reason']
                        . ' Aditívne doplnenie tohto dňa vyžaduje zosúladenie pôvodného podania.'];
            }
            // Only affected days are blocked; unrelated days can still be supplemented.
            $candidates = array_values(array_filter($candidates,
                fn ($item) => !in_array('', $conflictDates, true) && !in_array($item['date'], $conflictDates, true)));
        }
        $selectedIds = data_get($context, 'data.journeyIds');
        // The original form explicitly selects patients for corrections, including unchanged
        // rejected records. An explicit journey selection, if supplied, remains more specific.
        $selected = array_values(array_filter($candidates, fn ($item) => is_array($selectedIds)
            ? in_array($item['journey_id'], array_map('intval', $selectedIds), true)
            : ($item['suggested'] || ($operation === 'O' && $patientFilter !== []))));
        if (!$previewOnly && is_array($selectedIds)
            && count($selected) !== count(array_unique(array_map('intval', $selectedIds)))) {
            throw ValidationException::withMessages(['journeyIds' => ['Výber sa zmenil alebo obsahuje nedostupné jazdy. Obnovte náhľad.']]);
        }
        // Include all history and routing conflicts, including a newly saved N batch.
        $token = hash('sha256', json_encode([
            $scope, $character, $car->id, $existingNew?->id,
            array_map(fn ($line) => [(int) $line->id, $line->fingerprint], $history),
            $routeChanges, $blocked,
            array_map(fn ($item) => [$item['journey_id'], $item['fingerprint'], $item['export_fields'],
                $item['previous_line_id'], $item['route_fingerprint']], $candidates),
        ], JSON_THROW_ON_ERROR));
        $expected = data_get($context, 'data.previewToken');
        if (!$previewOnly && $expected && !hash_equals($token, $expected)) {
            throw ValidationException::withMessages(['previewToken' => ['Údaje sa od náhľadu zmenili. Obnovte náhľad.']]);
        }
        if (!$previewOnly && $operation !== 'N' && $patientFilter === []) {
            if (!is_array($selectedIds) || $selectedIds === [] || !$expected) {
                throw ValidationException::withMessages(['journeyIds' => ['Najprv otvorte náhľad a vyberte konkrétne jazdy do dávky.']]);
            }
            if ($operation === 'O' && (!filter_var(data_get($context, 'data.correction.confirmed'), FILTER_VALIDATE_BOOL)
                || trim((string) data_get($context, 'data.correction.reason', '')) === '')) {
                throw ValidationException::withMessages(['correction' => [
                    'Potvrďte, že vybrané riadky boli predložené a neuznané poisťovňou, a uveďte odôvodnenie reklamácie.',
                ]]);
            }
        }
        return ['candidates' => $candidates, 'selected' => $selected, 'blocked' => $blocked,
            'car' => $car->only(['id', 'evc', 'model', 'vin']), 'preview_token' => $token,
            'can_create' => !$existingNew && $candidates !== [], 'route_changes' => $routeChanges];
    }

    public function history(array $scope): array
    {
        $query = DB::table('transport_claim_lines as l')
            ->join('transport_claim_batches as b', 'b.id', '=', 'l.batch_id')
            ->join('documents as d', 'd.id', '=', 'b.document_id')
            ->whereNull('d.deleted_at')
            ->whereColumn('l.revision', 'b.revision');
        foreach ($scope as $field => $value) {
            $query->where('b.' . $field, $value);
        }
        return $query->select('l.*', 'b.document_id')->orderByDesc('l.id')->get()->unique('journey_id')->keyBy('journey_id')->all();
    }

    private function batchScope(array $scope): array
    {
        return collect($scope)->mapWithKeys(fn ($value, $field) => ['b.' . $field => $value])->all();
    }

    private function fingerprint(array $row, array $routeData): string
    {
        $fields = ['id', 'patient_id', 'date', 'diagnosis_code', 'procedure_code', 'personal_number',
            'first_name', 'last_name', 'sex', 'doctor_pzs', 'doctor_zpr', 'category', 'identification_method',
            'member_state_code', 'foreign_insured_id', 'special_category', 'price'];
        $values = [];
        foreach ($fields as $key) {
            $values[$key] = (string) ($row[$key] ?? '');
        }
        return hash('sha256', json_encode([$values, $routeData], JSON_THROW_ON_ERROR));
    }

    private function reportedKilometers(int $meters, array $contract): int
    {
        $km = $meters / 1000;
        return match ($contract['rounding'] ?? 'nearest') {
            'ceil' => (int) ceil($km), 'floor' => (int) floor($km),
            'nearest' => (int) round($km, 0, PHP_ROUND_HALF_UP),
            default => throw ValidationException::withMessages(['transport' => ['Neplatné nastavenie zaokrúhľovania kilometrov.']]),
        };
    }

    private function newCharacter(string $character): string
    {
        return match ($character) { 'E', 'F', 'G' => 'E', 'I', 'J', 'K' => 'I', default => 'N' };
    }

    private function address(array $address): string
    {
        return trim($address['address'] . ', ' . $address['city'], ', ');
    }
}

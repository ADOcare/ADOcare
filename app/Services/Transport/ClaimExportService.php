<?php

namespace App\Services\Transport;

use App\Models\Branch;
use App\Models\User;
use App\Services\PointsBatchNumberService;
use App\Services\Claims\ClaimInterfaceVersionRegistry;
use App\Services\Claims\InsuredClaimResolver;
use App\Services\Claims\ClaimFileGenerator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClaimExportService
{
    private const DATA_TYPE = '793n';
    private const TRANSPORT_TYPE_ADOS = 'ADOS';
    private const ADDRESS_CITY_MAX_LENGTH = 50;

    public function __construct(
        private PointsBatchNumberService $batchNumberService,
        private InsuredClaimResolver $insuredResolver,
        private ClaimInterfaceVersionRegistry $interfaceVersions,
        private ClaimFileGenerator $claimFileGenerator,
        private ClaimSelectionService $selection,
    ) {
    }

    public function prepare(array $data, User $actor, bool $previewOnly = false): array
    {
        $branch = Branch::query()->findOrFail((int) data_get($data, 'branch.id'));
        abort_unless($actor->isInBranch((int) $branch->id), 403);
        data_set($data, 'user.id', $actor->id);
        data_set($data, 'company.id', $branch->company_id);
        $context = $this->buildExportContext($data);
        if ($context['from'] < '2026-07-01') {
            throw ValidationException::withMessages(['period' => ['Nový generátor 793n podporuje obdobia od 1. 7. 2026. Staršie podania vyžadujú pôvodné rozhranie.']]);
        }
        $selection = $this->selection->select($context, $actor, $branch, $previewOnly);
        $context['rows'] = collect($selection['selected'])->map(fn ($item) => (object) $item['row']);
        $context['userCar'] = $selection['car']['evc'];
        $context['carId'] = $selection['car']['id'];
        $context['patientIds'] = $context['rows']->pluck('patient_id')->unique()->values()->all();
        $sheet = [
            'batchNumber' => $context['batchNumber'],
            'fileName' => 'davka.' . $context['batchNumber'],
            'amount' => round(array_sum(array_column($selection['selected'], 'amount')), 2),
            'kilometers' => $context['rows']->sum('reported_km'),
            'periodFrom' => $context['from'], 'periodTo' => $context['to'],
            'performedBy' => $context['performedBy'],
            'performedDate' => now()->timezone('Europe/Bratislava')->toDateString(),
            'companyName' => $context['companyName'], 'branchName' => $context['branchName'],
            'insuranceName' => $context['insuranceName'], 'patients' => $context['patientIds'],
            'fileType' => 'vykázané kilometre',
        ];
        $content = null;
        if (!$previewOnly) {
            // Validate the same shortened values that will be written to TXT.
            // Keep the original rows for route data, selection and saved history.
            $exportContext = $context;
            $exportContext['rows'] = $this->normalizeAddressAndCityFields($context['rows']);
            $this->validate793nAdosExportContext($exportContext);
            $content = $this->build793nAdosContent($exportContext);
        }
        return ['context' => $context, 'sheet' => $sheet, 'selection' => $selection, 'content' => $content];
    }

    private function buildExportContext(array $data): array
    {
        $from = $this->parseDateOnly($data['period'][0]);
        $to = $this->parseDateOnly($data['period'][1]);

        $type = (string) data_get($data, 'batchType.code');
        $batchNumber = $this->batchNumberService->make(
            (int) data_get($data, 'user.id'),
            (int) data_get($data, 'insurance.id'),
            $from
        );

        $userId = (int) data_get($data, 'user.id');
        $branchId = (int) data_get($data, 'branch.id');
        $companyId = (int) data_get($data, 'company.id');
        $insuranceId = (int) data_get($data, 'insurance.id');

        $patientIds = collect(data_get($data, 'patients', []))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        $company = DB::table('company')
            ->where('id', $companyId)
            ->select('id', 'ico', 'name')
            ->first();

        $branch = DB::table('branches')
            ->where('id', $branchId)
            ->select('id', 'code', 'identificator', 'city', 'address')
            ->first();

        $user = DB::table('users')
            ->where('id', $userId)
            ->select('id', 'code', 'first_name', 'last_name')
            ->first();

        $workingTime = DB::table('user_branches')
            ->where('user_id', $userId)
            ->where('branch_id', $branchId)
            ->value('working_time');

        $insurance = DB::table('insurance_companies')
            ->where('id', $insuranceId)
            ->select('id', 'name', 'code', 'branch_code')
            ->first();

        $userCar = DB::table('cars')
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->value('evc');

        $rows = DB::table('patient_points as pp')
            ->join('patients as p', 'p.id', '=', 'pp.patient_id')
            ->leftJoin('doctors as d', 'd.id', '=', 'p.doctor_id')
            ->join('branches as b', 'b.id', '=', 'pp.branch_id')
            ->join('patient_coverages as pc', 'pc.patient_id', '=', 'p.id')
            ->leftJoin('procedures as proc', function ($join) {
                $join->where('proc.code', '=', '0000');
            })
            ->leftJoin('procedure_company_prices as pcp', function ($join) use ($companyId) {
                $join->on('pcp.procedure_id', '=', 'proc.id')
                    ->on('pcp.insurance_company_id', '=', 'pc.insurance_company_id')
                    ->where('pcp.company_id', '=', $companyId);
            })
            ->where('pp.user_id', $userId)
            ->where('pp.branch_id', $branchId)
            ->whereBetween('pp.date', [$from, $to])
            ->whereIn('pp.procedure_code', ['3439', '3440'])
            ->where('pp.quantity', '>', 0)
            ->orderBy('pp.date')
            ->orderBy('pp.patient_id')
            ->orderBy('pp.id')
            ->select([
                'pp.id',
                'pp.created_at',
                'pp.updated_at',
                'pc.id as coverage_id',
                'pc.insurance_company_id',
                'pp.date',
                'pp.patient_id',
                'pp.diagnosis_code',
                'pp.procedure_code',

                'p.personal_number',
                'p.last_name',
                'p.first_name',
                'p.sex',
                'p.city as patient_city',
                'p.address as patient_address',
                'p.latitude as patient_lat',
                'p.longitude as patient_lng',

                DB::raw('COALESCE(pp.doctor_pzs, d.pzs) as doctor_pzs'),
                DB::raw('COALESCE(pp.doctor_zpr, d.zpr) as doctor_zpr'),

                'b.city as branch_city',
                'b.address as branch_address',
                'b.latitude as branch_lat',
                'b.longitude as branch_lng',

                'pcp.price',
                'pc.category',
                'pc.identification_method',
                'pc.member_state_code',
                'pc.foreign_insured_id',
                'pc.special_category',
            ])
            ->get()
            ->groupBy('id')
            ->map(fn ($matches) => $matches->first())
            ->filter(fn ($row) => (int) $row->insurance_company_id === $insuranceId)
            ->values();

        $companyName = $company?->name;

        $branchName = trim(($branch?->city ?? '') . ', ' . ($branch?->address ?? ''));
        $branchName = trim($branchName, " \t\n\r\0\x0B,");

        $performedBy = trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? ''));

        return [
            'data' => $data,
            'type' => $type,
            'batchNumber' => $batchNumber,
            'from' => $from,
            'to' => $to,

            'userId' => $userId,
            'branchId' => $branchId,
            'companyId' => $companyId,
            'insuranceId' => $insuranceId,
            'patientIds' => $patientIds,

            'company' => $company,
            'branch' => $branch,
            'user' => $user,
            'workingTime' => $workingTime,
            'insurance' => $insurance,
            'insuranceCode' => $insurance?->code,
            'insuranceBranchCode' => $insurance?->branch_code,
            'userCar' => $userCar,
            'carId' => data_get($data, 'car_id'),

            'rows' => $rows,

            'companyName' => $companyName,
            'branchName' => $branchName,
            'performedBy' => $performedBy !== '' ? $performedBy : "User #{$userId}",
            'insuranceName' => $insurance?->name,
            'interfaceVersion' => $this->interfaceVersions->forDate(self::DATA_TYPE, $from),
        ];
    }

    private function build793nAdosContent(array $context): string
    {
        $type = $context['type'];
        $batchNumber = $context['batchNumber'];
        $rows = $context['rows'];
        $calculatedRows = $this->calculateKilometersForRows($rows);

        $company = $context['company'];
        $branch = $context['branch'];
        $user = $context['user'];
        $workingTime = $context['workingTime'];
        $userId = (int) $context['userId'];
        $insuranceCode = (string) $context['insuranceCode'];
        $insuranceBranchCode = $context['insuranceBranchCode'];
        $userCar = $context['userCar'];

        $generatedYmd = now()->setTimezone('Europe/Bratislava')->format('Ymd');
        $termYYYYMM = Carbon::parse($context['from'])->format('Ym');

        $line1Fields = [
            $this->normalizeCode($type),
            self::DATA_TYPE,
            $this->toAsciiString($company->ico ?? ''),
            $generatedYmd,
            $batchNumber,
            $rows->count(),
            '1',
            '1',
            $this->toAsciiString($insuranceBranchCode ?? ''),
        ];

        $line2Fields = [
            $this->normalizeCode($branch->identificator ?? ''),
            $this->normalizeCode($branch->code ?? ''),
            $this->normalizeCode($user->code ?? ''),
            number_format((float) $workingTime, 2, '.', ''),
            $termYYYYMM,
            '',
            'EUR',
        ];

        $records = [
            ['fields' => $line1Fields, 'count' => 9],
            ['fields' => $line2Fields, 'count' => 7],
        ];

        foreach ($calculatedRows as $index => $calculatedRow) {
            $bodyFields = $this->build793nAdosBodyFields(
                row: $calculatedRow['source'],
                rowNumber: $index + 1,
                kilometers: (float) $calculatedRow['kilometers'],
                userCar: (string) $userCar,
                userId: $userId,
                insuranceCode: $insuranceCode,
                type: $type
            );

            $records[] = ['fields' => $bodyFields, 'count' => 23];
        }

        return $this->claimFileGenerator->generate($records);
    }

    private function build793nAdosBodyFields(
        object $row,
        int $rowNumber,
        float $kilometers,
        string $userCar,
        int $userId,
        string $insuranceCode,
        string $type
    ): array {
        $resolved = $this->insuredResolver->resolve(
            $row,
            $this->insuredResolver->operationForCharacter($type)
        );
        $dayDD = Carbon::parse($row->date)->format('d');

        $patientName = $this->toAsciiString(
            trim(($row->last_name ?? '') . ' ' . ($row->first_name ?? '')),
            60
        );

        $transportRecordCode = str_pad((string) $row->journey_id, 8, '0', STR_PAD_LEFT);

        return [
            $rowNumber,
            $dayDD,
            $this->toAsciiString($resolved->personalNumber ?? ''),
            $patientName,
            $this->toAsciiString($row->diagnosis_code ?? ''),
            '',
            '',
            self::TRANSPORT_TYPE_ADOS,
            (int) $row->reported_km,
            $this->normalizeAddressOrCity($row->branch_city ?? null),
            $this->normalizeAddressOrCity($row->branch_address ?? null),
            $this->normalizeAddressOrCity($row->patient_city ?? null),
            $this->normalizeAddressOrCity($row->patient_address ?? null),
            $transportRecordCode,
            $this->normalizeCode($userCar),
            '0',
            '',
            'N',
            $this->normalizeCode($row->doctor_pzs ?? ''),
            $this->normalizeCode($row->doctor_zpr ?? ''),
            $this->normalizeCode($resolved->memberStateCode ?? ''),
            $this->toAsciiString($resolved->foreignInsuredId ?? ''),
            $this->normalizeCode($resolved->sex ?? ''),
        ];
    }

    private function validate793nAdosExportContext(array $context): void
    {
        $errors = [];

        $type = $context['type'];
        $batchNumber = $context['batchNumber'];
        $from = $context['from'];
        $to = $context['to'];

        $company = $context['company'];
        $branch = $context['branch'];
        $user = $context['user'];
        $workingTime = $context['workingTime'];
        $insuranceCode = $context['insuranceCode'];
        $insuranceBranchCode = $context['insuranceBranchCode'];
        $userCar = $context['userCar'];
        $rows = $context['rows'];

        if (!in_array($type, ['N', 'O', 'A', 'E', 'F', 'G', 'I', 'J', 'K'], true)) {
            $this->addValidationError(
                $errors,
                'Neplatný charakter dávky 793n.',
                'batch:invalid_type'
            );
        }

        if (!preg_match('/^\d{6}$/', (string) $batchNumber)) {
            $this->addValidationError(
                $errors,
                'Číslo dávky musí mať presne 6 číslic vo formáte UUMMPP.',
                'batch:invalid_number'
            );
        }

        if (Carbon::parse($from)->format('Ym') !== Carbon::parse($to)->format('Ym')) {
            $this->addValidationError(
                $errors,
                'Dávka musí byť vytvorená iba za jedno zúčtovacie obdobie v rámci jedného mesiaca.',
                'batch:period_not_one_month'
            );
        }

        if (Carbon::parse($from)->gt(Carbon::parse($to))) {
            $this->addValidationError(
                $errors,
                'Dátum začiatku obdobia nemôže byť neskôr ako dátum konca obdobia.',
                'batch:invalid_period_order'
            );
        }

        if ($rows->isEmpty()) {
            $this->addValidationError(
                $errors,
                'Nenašli sa žiadne kilometrové výkony 3439 alebo 3440 pre zadané filtre.',
                'batch:no_rows'
            );
        }

        $this->addMissingError($errors, $company, 'Spoločnosť neexistuje.', 'company:missing');
        $this->addMissingError($errors, $company?->ico ?? null, 'Chýba IČO spoločnosti.', 'company:missing_ico');
        $this->addPatternError($errors, $company?->ico ?? null, '/^\d{8}$/', 'IČO spoločnosti musí mať presne 8 číslic.', 'company:invalid_ico');
        $this->addMissingError($errors, $company?->name ?? null, 'Chýba názov spoločnosti.', 'company:missing_name');

        $this->addMissingError($errors, $branch, 'Prevádzka neexistuje.', 'branch:missing');
        $this->addMissingError($errors, $branch?->identificator ?? null, 'Chýba identifikátor PZS.', 'branch:missing_identificator');
        $this->addPatternError(
            $errors,
            $branch?->identificator ?? null,
            '/^[A-Z]\d{5}$/',
            'Identifikátor PZS musí mať 6 znakov: písmeno A-Z a za ním 5 číslic.',
            'branch:invalid_identificator'
        );

        $this->addMissingError($errors, $branch?->code ?? null, 'Chýba kód PZS.', 'branch:missing_code');
        $this->addPatternError(
            $errors,
            $branch?->code ?? null,
            '/^[A-Z]\d{11}$/',
            'Kód PZS musí mať 12 znakov: písmeno A-Z a za ním 11 číslic.',
            'branch:invalid_code'
        );

        $this->addMissingError($errors, $user, 'Používateľ neexistuje.', 'user:missing');
        $this->addMissingError($errors, $user?->code ?? null, 'Chýba kód zdravotníckeho pracovníka.', 'user:missing_code');
        $this->addPatternError(
            $errors,
            $user?->code ?? null,
            '/^[A-Z][A-Z0-9]{8}$/',
            'Kód zdravotníckeho pracovníka musí mať 9 znakov: prvý znak veľké písmeno a za ním 8 alfanumerických znakov.',
            'user:invalid_code'
        );

        $this->addMissingError($errors, $workingTime, 'Chýba úväzok zdravotníckeho pracovníka na prevádzke.', 'user_branch:missing_working_time');

        if ($this->isFilledValue($workingTime) && !is_numeric($workingTime)) {
            $this->addValidationError(
                $errors,
                'Úväzok zdravotníckeho pracovníka musí byť číslo.',
                'user_branch:invalid_working_time'
            );
        }

        $this->addMissingError(
            $errors,
            $insuranceCode,
            'Chýba dvojmiestny kód poisťovne.',
            'insurance:missing_code'
        );

        $this->addPatternError(
            $errors,
            $insuranceCode,
            '/^\d{2}$/',
            'Kód poisťovne musí obsahovať presne 2 číslice.',
            'insurance:invalid_code'
        );

        if (
            $this->isFilledValue($insuranceCode)
            && !in_array((string) $insuranceCode, ['24', '25', '27'], true)
        ) {
            $this->addValidationError(
                $errors,
                'Kód poisťovne musí byť 24, 25 alebo 27.',
                'insurance:unsupported_code'
            );
        }

        $this->addMissingError($errors, $insuranceBranchCode, 'Chýba kód pobočky poisťovne.', 'insurance:missing_branch_code');
        $this->addPatternError(
            $errors,
            $insuranceBranchCode,
            '/^\d{3,4}$/',
            'Kód pobočky poisťovne musí mať 3 až 4 číslice podľa zmluvného kódu poisťovne/pobočky.',
            'insurance:invalid_branch_code'
        );

        $this->addMissingError($errors, $userCar, 'Chýba EČV vozidla používateľa.', 'car:missing_evc');
        $this->addLengthBetweenError(
            $errors,
            $userCar,
            6,
            7,
            'EČV vozidla musí mať 6 až 7 znakov.',
            'car:invalid_evc_length'
        );

        foreach ($rows as $index => $row) {
            $this->validate793nAdosRow(
                errors: $errors,
                row: $row,
                rowNumber: $index + 1,
                from: $from,
                to: $to,
                userCar: (string) $userCar,
                userId: (int) $context['userId'],
                insuranceCode: (string) $insuranceCode,
                type: $type
            );
        }

        if (in_array($type, ['I', 'J', 'K'], true)) {
            $specialCategories = $rows->pluck('special_category')->filter()->unique();

            if ($specialCategories->count() > 1) {
                $this->addValidationError(
                    $errors,
                    'Osobitná dávka nesmie miešať rozdielne právne kategórie poistencov.',
                    'batch:mixed_special_categories'
                );
            }
        }

        $this->throwKilometersValidationErrors($errors);
    }

    private function validate793nAdosRow(
        array &$errors,
        object $row,
        int $rowNumber,
        string $from,
        string $to,
        string $userCar,
        int $userId,
        string $insuranceCode,
        string $type
    ): void {
        $patientName = $this->formatPatientName($row);
        $label = "{$patientName} / riadok {$rowNumber}";
        $resolved = $this->insuredResolver->resolve(
            $row,
            $this->insuredResolver->operationForCharacter($type)
        );

        if ($resolved->character !== $type) {
            $this->addValidationError(
                $errors,
                "Poistný režim pacienta {$patientName} patrí do dávky {$resolved->character}, nie {$type}.",
                $this->patientErrorKey($row, 'incompatible_character')
            );
        }

        foreach ($resolved->errors as $resolverError) {
            $this->addValidationError(
                $errors,
                "{$resolverError} Pacient: {$patientName}.",
                $this->patientErrorKey($row, 'insured_identification')
            );
        }

        $this->addMissingError(
            $errors,
            $row->date ?? null,
            "Chýba dátum prepravy: {$label}.",
            $this->rowErrorKey($row, $rowNumber, 'missing_date')
        );

        if ($this->isFilledValue($row->date ?? null)) {
            $date = Carbon::parse($row->date)->toDateString();

            if ($date < $from || $date > $to) {
                $this->addValidationError(
                    $errors,
                    "Dátum prepravy nie je v zadanom období: {$label}.",
                    $this->rowErrorKey($row, $rowNumber, 'date_out_of_period')
                );
            }
        }

        $this->addMissingError(
            $errors,
            $row->last_name ?? null,
            "Chýba priezvisko pacienta: {$patientName}.",
            $this->patientErrorKey($row, 'missing_last_name')
        );

        $this->addMissingError(
            $errors,
            $row->first_name ?? null,
            "Chýba meno pacienta: {$patientName}.",
            $this->patientErrorKey($row, 'missing_first_name')
        );

        $fullName = $this->toAsciiString(trim(($row->last_name ?? '') . ' ' . ($row->first_name ?? '')));

        if ($this->isFilledValue($fullName) && mb_strlen($fullName) > 60) {
            $this->addValidationError(
                $errors,
                "Meno poistenca môže mať maximálne 60 znakov: {$patientName}.",
                $this->patientErrorKey($row, 'invalid_full_name_length')
            );
        }

        $this->addMissingError(
            $errors,
            $row->diagnosis_code ?? null,
            "Chýba diagnóza: {$patientName}.",
            $this->patientErrorKey($row, 'missing_diagnosis_code')
        );

        $this->addLengthBetweenError(
            $errors,
            $row->diagnosis_code ?? null,
            3,
            5,
            "Kód diagnózy musí mať 3 až 5 znakov: {$patientName}.",
            $this->patientErrorKey($row, 'invalid_diagnosis_length')
        );

        $this->addPatternError(
            $errors,
            $row->diagnosis_code ?? null,
            '/^[A-Z][0-9A-Z]{2,4}$/i',
            "Kód diagnózy musí byť bez bodky a bez špeciálnych znakov: {$patientName}.",
            $this->patientErrorKey($row, 'invalid_diagnosis_format')
        );

        $this->addMissingError(
            $errors,
            $row->procedure_code ?? null,
            "Chýba kód výkonu: {$label}.",
            $this->rowErrorKey($row, $rowNumber, 'missing_procedure_code')
        );

        if ($this->isFilledValue($row->procedure_code ?? null) && !in_array((string) $row->procedure_code, ['3439', '3440'], true)) {
            $this->addValidationError(
                $errors,
                "Kilometrová dávka môže obsahovať iba výkony 3439 alebo 3440: {$label}.",
                $this->rowErrorKey($row, $rowNumber, 'invalid_procedure_code')
            );
        }

        $this->addMissingError(
            $errors,
            $row->branch_city ?? null,
            "Chýba obec východiskovej stanice.",
            'branch:missing_city'
        );

        $this->addLengthBetweenError(
            $errors,
            $row->branch_city ?? null,
            1,
            self::ADDRESS_CITY_MAX_LENGTH,
            'Obec východiskovej stanice môže mať 1 až 50 znakov.',
            'branch:invalid_city_length'
        );

        $this->addMissingError(
            $errors,
            $row->branch_address ?? null,
            'Chýba ulica východiskovej stanice.',
            'branch:missing_address'
        );

        $this->addLengthBetweenError(
            $errors,
            $row->branch_address ?? null,
            1,
            self::ADDRESS_CITY_MAX_LENGTH,
            'Ulica východiskovej stanice môže mať 1 až 50 znakov.',
            'branch:invalid_address_length'
        );

        $this->addMissingError(
            $errors,
            $row->patient_city ?? null,
            "Chýba obec cieľovej stanice: {$patientName}.",
            $this->patientErrorKey($row, 'missing_patient_city')
        );

        $this->addLengthBetweenError(
            $errors,
            $row->patient_city ?? null,
            1,
            self::ADDRESS_CITY_MAX_LENGTH,
            "Obec cieľovej stanice môže mať 1 až 50 znakov: {$patientName}.",
            $this->patientErrorKey($row, 'invalid_patient_city_length')
        );

        $this->addMissingError(
            $errors,
            $row->patient_address ?? null,
            "Chýba ulica cieľovej stanice: {$patientName}.",
            $this->patientErrorKey($row, 'missing_patient_address')
        );

        $this->addLengthBetweenError(
            $errors,
            $row->patient_address ?? null,
            1,
            self::ADDRESS_CITY_MAX_LENGTH,
            "Ulica cieľovej stanice môže mať 1 až 50 znakov: {$patientName}.",
            $this->patientErrorKey($row, 'invalid_patient_address_length')
        );

        $this->addMissingError(
            $errors,
            $row->branch_lat ?? null,
            'Chýba GPS latitude prevádzky.',
            'branch:missing_lat'
        );

        $this->addMissingError(
            $errors,
            $row->branch_lng ?? null,
            'Chýba GPS longitude prevádzky.',
            'branch:missing_lng'
        );

        $this->addCoordinateError(
            $errors,
            $row->branch_lat ?? null,
            -90,
            90,
            'GPS latitude prevádzky je neplatná.',
            'branch:invalid_lat'
        );

        $this->addCoordinateError(
            $errors,
            $row->branch_lng ?? null,
            -180,
            180,
            'GPS longitude prevádzky je neplatná.',
            'branch:invalid_lng'
        );

        $this->addMissingError(
            $errors,
            $row->patient_lat ?? null,
            "Chýba GPS latitude pacienta: {$patientName}.",
            $this->patientErrorKey($row, 'missing_patient_lat')
        );

        $this->addMissingError(
            $errors,
            $row->patient_lng ?? null,
            "Chýba GPS longitude pacienta: {$patientName}.",
            $this->patientErrorKey($row, 'missing_patient_lng')
        );

        $this->addCoordinateError(
            $errors,
            $row->patient_lat ?? null,
            -90,
            90,
            "GPS latitude pacienta je neplatná: {$patientName}.",
            $this->patientErrorKey($row, 'invalid_patient_lat')
        );

        $this->addCoordinateError(
            $errors,
            $row->patient_lng ?? null,
            -180,
            180,
            "GPS longitude pacienta je neplatná: {$patientName}.",
            $this->patientErrorKey($row, 'invalid_patient_lng')
        );

        $this->addMissingError(
            $errors,
            $row->price ?? null,
            "Chýba cena výkonu 0000 v cenníku poisťovne: {$patientName}.",
            $this->patientErrorKey($row, 'missing_price_0000')
        );

        if ($this->isFilledValue($row->price ?? null) && !is_numeric($row->price)) {
            $this->addValidationError(
                $errors,
                "Cena výkonu 0000 musí byť číslo: {$patientName}.",
                $this->patientErrorKey($row, 'invalid_price_0000')
            );
        }

        $this->addMissingError(
            $errors,
            $userCar,
            'Chýba EČV vozidla.',
            'car:missing_evc'
        );

        $this->addMissingError(
            $errors,
            $row->doctor_pzs ?? null,
            "Chýba kód PZS odosielateľa: {$patientName}.",
            $this->patientErrorKey($row, 'missing_doctor_pzs')
        );

        $this->addPatternError(
            $errors,
            $row->doctor_pzs ?? null,
            '/^[A-Z]\d{11}$/',
            "Kód PZS odosielateľa musí mať 12 znakov: písmeno A-Z a za ním 11 číslic: {$patientName}.",
            $this->patientErrorKey($row, 'invalid_doctor_pzs')
        );

        $this->addMissingError(
            $errors,
            $row->doctor_zpr ?? null,
            "Chýba kód ZPR odosielateľa: {$patientName}.",
            $this->patientErrorKey($row, 'missing_doctor_zpr')
        );

        $this->addPatternError(
            $errors,
            $row->doctor_zpr ?? null,
            '/^[A-Z][A-Z0-9]{8}$/',
            "Kód ZPR odosielateľa musí mať 9 znakov: prvý znak veľké písmeno a za ním 8 alfanumerických znakov: {$patientName}.",
            $this->patientErrorKey($row, 'invalid_doctor_zpr')
        );

        if ($rowNumber > 999999 || !isset($row->reported_km) || $row->reported_km < 0 || $row->reported_km > 99999) {
            $this->addValidationError($errors, 'Prekročený rozsah počtu riadkov alebo kilometrov dávky 793n.', 'transport:range');
        }
        if (!preg_match('/^\d{1,8}$/', (string) ($row->journey_id ?? ''))) {
            $this->addValidationError($errors, 'Chýba platné stabilné číslo jazdy.', 'journey:invalid');
        }

        $fields = $this->build793nAdosBodyFields(
            row: $row,
            rowNumber: $rowNumber,
            kilometers: 0.0,
            userCar: $userCar,
            userId: $userId,
            insuranceCode: $insuranceCode,
            type: $type
        );

        if (count($fields) !== 23) {
            $this->addValidationError(
                $errors,
                "Interná chyba: veta tela dávky 793n musí mať presne 23 polí: {$label}.",
                $this->rowErrorKey($row, $rowNumber, 'invalid_field_count')
            );
        }
    }

    private function calculateKilometersForRows($rows): array
    {
        return $rows->values()->map(fn ($row, $index) => [
            'index' => $index,
            'source' => $row,
            'kilometers' => (int) $row->reported_km,
        ])->all();
    }

    private function normalizedInsuredRows(array $context): array
    {
        $operation = $this->insuredResolver->operationForCharacter($context['type']);

        return $context['rows']->values()->map(function (object $row, int $index) use ($operation) {
            $resolved = $this->insuredResolver->resolve($row, $operation);

            return [
                'row' => $index + 1,
                'patient_id' => $row->patient_id ?? null,
                'service_date' => $row->date ?? null,
                'character' => $resolved->character,
                'regime' => $resolved->category,
                'identification_method' => $resolved->identificationMethod,
                'used' => [
                    'personal_number' => $resolved->personalNumber,
                    'member_state_code' => $resolved->memberStateCode,
                    'foreign_insured_id' => $resolved->foreignInsuredId,
                    'sex' => $resolved->sex,
                ],
                'errors' => $resolved->errors,
            ];
        })->all();
    }

    private function formatTextLine(array $fields, int $expectedCount, string $errorMessage): string
    {
        if (count($fields) !== $expectedCount) {
            throw ValidationException::withMessages([
                'kilometers_export' => [$errorMessage],
            ]);
        }

        $fields = array_map(function ($field) {
            return $this->toAsciiString((string) $field);
        }, $fields);

        return implode('|', $fields) . '|';
    }

    private function parseDateOnly(mixed $value): string
    {
        $value = (string) $value;

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches)) {
            return $matches[0];
        }

        return Carbon::parse($value)->toDateString();
    }

    private function normalizeDateString($date): string
    {
        return is_string($date)
            ? $this->parseDateOnly($date)
            : (method_exists($date, 'toDateString') ? $date->toDateString() : 'unknown');
    }

    private function normalizeCode(mixed $value): string
    {
        return strtoupper(trim((string) ($value ?? '')));
    }

    private function normalizeAddressAndCityFields($rows)
    {
        return $rows->map(function ($row) {
            // Collections hold object references: never shorten the source row.
            $row = clone $row;
            $row->branch_city = $this->normalizeAddressOrCity($row->branch_city ?? null);
            $row->branch_address = $this->normalizeAddressOrCity($row->branch_address ?? null);
            $row->patient_city = $this->normalizeAddressOrCity($row->patient_city ?? null);
            $row->patient_address = $this->normalizeAddressOrCity($row->patient_address ?? null);

            return $row;
        });
    }

    private function normalizeAddressOrCity(mixed $value): string
    {
        // Apply the limit after transliteration, to the actual TXT value.
        return $this->toAsciiString((string) ($value ?? ''), self::ADDRESS_CITY_MAX_LENGTH);
    }

    private function toAsciiString(?string $value, ?int $limit = null): string
    {
        $normalized = Str::ascii((string) ($value ?? ''));
        $normalized = str_replace(["\r", "\n", '|'], ' ', $normalized);
        $normalized = preg_replace('/[^\x20-\x7E]/', '', $normalized) ?? '';
        $normalized = trim($normalized);

        if ($limit !== null) {
            return mb_substr($normalized, 0, $limit);
        }

        return $normalized;
    }

    private function addValidationError(array &$errors, string $message, ?string $key = null): void
    {
        if ($key !== null) {
            $errors[$key] = $message;
            return;
        }

        $errors[] = $message;
    }

    private function addMissingError(
        array &$errors,
        mixed $value,
        string $message,
        ?string $key = null
    ): void {
        if (!$this->isFilledValue($value)) {
            $this->addValidationError($errors, $message, $key);
        }
    }

    private function addPatternError(
        array &$errors,
        mixed $value,
        string $pattern,
        string $message,
        ?string $key = null
    ): void {
        if (!$this->isFilledValue($value)) {
            return;
        }

        $value = $this->normalizeCode($value);

        if (!preg_match($pattern, $value)) {
            $this->addValidationError($errors, $message, $key);
        }
    }

    private function addLengthBetweenError(
        array &$errors,
        mixed $value,
        int $min,
        int $max,
        string $message,
        ?string $key = null
    ): void {
        if (!$this->isFilledValue($value)) {
            return;
        }

        $length = mb_strlen((string) $value);

        if ($length < $min || $length > $max) {
            $this->addValidationError($errors, $message, $key);
        }
    }

    private function addCoordinateError(
        array &$errors,
        mixed $value,
        float $min,
        float $max,
        string $message,
        ?string $key = null
    ): void {
        if (!$this->isFilledValue($value)) {
            return;
        }

        if (!is_numeric($value)) {
            $this->addValidationError($errors, $message, $key);
            return;
        }

        $number = (float) $value;

        if ($number < $min || $number > $max) {
            $this->addValidationError($errors, $message, $key);
        }
    }

    private function isFilledValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        return true;
    }

    private function formatPatientName(object $row): string
    {
        $name = trim(($row->last_name ?? '') . ' ' . ($row->first_name ?? ''));

        if ($name !== '') {
            return $name;
        }

        if (!empty($row->patient_id)) {
            return "#{$row->patient_id}";
        }

        return 'neznámy pacient';
    }

    private function patientErrorKey(object $row, string $field): string
    {
        $patientId = $row->patient_id ?? 'unknown';

        return "patient:{$patientId}:{$field}";
    }

    private function rowErrorKey(object $row, int $rowNumber, string $field): string
    {
        $rowId = $row->id ?? $rowNumber;

        return "row:{$rowId}:{$field}";
    }

    private function throwKilometersValidationErrors(array $errors): void
    {
        $errors = array_values(array_unique($errors));

        if (!$errors) {
            return;
        }

        throw ValidationException::withMessages([
            'kilometers_export' => $errors,
        ]);
    }
}

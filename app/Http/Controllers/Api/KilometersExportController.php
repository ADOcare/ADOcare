<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KilometersExportController extends Controller
{
    private const DATA_TYPE = '793n';
    private const TRANSPORT_TYPE_ADOS = 'ADOS';
    private const GROUPING_BUFFER_METERS = 100.0;
    private const ADDRESS_CITY_MAX_LENGTH = 50;

    public function preview(Request $request)
    {
        $data = $this->validateInput($request);
        $context = $this->buildExportContext($data);

        $this->validate793nAdosExportContext($context);

        $calculatedRows = $this->calculateKilometersForRows($context['rows']);

        $totalKilometers = collect($calculatedRows)->sum('kilometers');
        $amount = collect($calculatedRows)->sum(
            fn ($row) => (float) $row['kilometers'] * (float) ($row['source']->price ?? 0)
        );

        $sheet = [
            'batchNumber' => $context['batchNumber'],
            'fileName' => "davka.{$context['batchNumber']}.txt",
            'amount' => (string) $amount,
            'kilometers' => round($totalKilometers, 2),
            'periodFrom' => $context['from'],
            'periodTo' => $context['to'],
            'performedBy' => $context['performedBy'],
            'performedDate' => now()->setTimezone('Europe/Bratislava')->toDateString(),
            'companyName' => $context['companyName'],
            'branchName' => $context['branchName'],
            'insuranceName' => $context['insuranceName'],
            'patients' => $context['patientIds'],
        ];

        return response()->json([
            'success' => true,
            'message' => 'Preview generated',
            'data' => [
                'sheet' => $sheet,
            ],
        ]);
    }

    public function download(Request $request)
    {
        $data = $this->validateInput($request);
        $context = $this->buildExportContext($data);

        $this->validate793nAdosExportContext($context);

        $content = $this->build793nAdosContent($context);
        $fileName = "davka.{$context['batchNumber']}.txt";

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $fileName, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    public function statementPdf(Request $request)
    {
        $data = $this->validateInput($request);
        $context = $this->buildExportContext($data);

        $this->validate793nAdosExportContext($context);

        $calculatedRows = $this->calculateKilometersForRows($context['rows']);

        $totalKilometers = collect($calculatedRows)->sum('kilometers');
        $amount = collect($calculatedRows)->sum(
            fn ($row) => (float) $row['kilometers'] * (float) ($row['source']->price ?? 0)
        );

        $sheet = [
            'batchNumber' => $context['batchNumber'],
            'fileName' => "davka.{$context['batchNumber']}.txt",
            'amount' => (string) $amount,
            'kilometers' => round($totalKilometers, 2),
            'periodFrom' => $context['from'],
            'periodTo' => $context['to'],
            'performedBy' => $context['performedBy'],
            'performedDate' => now()->setTimezone('Europe/Bratislava')->toDateString(),
            'companyName' => $context['companyName'],
            'branchName' => $context['branchName'],
            'insuranceName' => $context['insuranceName'],
            'fileType' => 'vykázané kilometre',
        ];

        $pdf = Pdf::loadView('pdf.statement', [
            'sheet' => $sheet,
        ])->setPaper('a4');

        $pdfName = "sprievodny_list_{$sheet['batchNumber']}.pdf";

        return $pdf->download($pdfName);
    }

    private function validateInput(Request $request): array
    {
        return $request->validate([
            'batchNumber' => ['required', 'regex:/^\d{1,6}$/'],
            'batchType.code' => ['required', 'string', 'in:N,O'],
            'insurance.id' => ['required', 'integer'],
            'period' => ['required', 'array', 'size:2'],
            'period.*' => ['required', 'date'],

            'user.id' => ['required', 'integer'],
            'branch.id' => ['required', 'integer'],
            'company.id' => ['required', 'integer'],

            'patients' => ['nullable', 'array'],
            'patients.*.id' => ['required_with:patients', 'integer'],
        ]);
    }

    private function buildExportContext(array $data): array
    {
        $from = $this->parseDateOnly($data['period'][0]);
        $to = $this->parseDateOnly($data['period'][1]);

        $type = (string) data_get($data, 'batchType.code');
        $batchNumber = (string) data_get($data, 'batchNumber');

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
            ->join('patient_coverages as pc', function ($join) {
                $join->on('pc.patient_id', '=', 'p.id')
                    ->where(function ($query) {
                        $query->whereNull('pc.valid_from')
                            ->orWhereColumn('pc.valid_from', '<=', 'pp.date');
                    })
                    ->where(function ($query) {
                        $query->whereNull('pc.valid_to')
                            ->orWhereColumn('pc.valid_to', '>=', 'pp.date');
                    });
            })
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
            ->where('pc.insurance_company_id', $insuranceId)
            ->whereColumn('p.nurse_id', 'pp.user_id')
            ->whereColumn('p.branch_id', 'pp.branch_id')
            ->whereBetween('pp.date', [$from, $to])
            ->whereIn('pp.procedure_code', ['3439', '3440'])
            ->where('pc.regime', 'domestic')
            ->when(!empty($patientIds), fn ($query) => $query->whereIn('pp.patient_id', $patientIds))
            ->orderBy('pp.date')
            ->orderBy('pp.patient_id')
            ->orderBy('pp.id')
            ->select([
                'pp.id',
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

                'd.pzs as doctor_pzs',
                'd.zpr as doctor_zpr',

                'b.city as branch_city',
                'b.address as branch_address',
                'b.latitude as branch_lat',
                'b.longitude as branch_lng',

                'pcp.price',
            ])
            ->get();

        $rows = $this->normalizeAddressAndCityFields($rows);

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

            'rows' => $rows,

            'companyName' => $companyName,
            'branchName' => $branchName,
            'performedBy' => $performedBy !== '' ? $performedBy : "User #{$userId}",
            'insuranceName' => $insurance?->name,
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
            $batchNumber,
            'EUR',
        ];

        $lines = [
            $this->formatTextLine($line1Fields, 9, 'Identifikácia dávky 793n musí mať presne 9 polí.'),
            $this->formatTextLine($line2Fields, 7, 'Záhlavie dávky 793n musí mať presne 7 polí.'),
        ];

        foreach ($calculatedRows as $index => $calculatedRow) {
            $bodyFields = $this->build793nAdosBodyFields(
                row: $calculatedRow['source'],
                rowNumber: $index + 1,
                kilometers: (float) $calculatedRow['kilometers'],
                userCar: (string) $userCar,
                userId: $userId,
                insuranceCode: $insuranceCode
            );

            $lines[] = $this->formatTextLine(
                $bodyFields,
                23,
                'Veta tela dávky 793n na riadku ' . ($index + 1) . ' musí mať presne 23 polí.'
            );
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    private function build793nAdosBodyFields(
        object $row,
        int $rowNumber,
        float $kilometers,
        string $userCar,
        int $userId,
        string $insuranceCode
    ): array {
        $dayDD = Carbon::parse($row->date)->format('d');

        $patientName = $this->toAsciiString(
            trim(($row->last_name ?? '') . ' ' . ($row->first_name ?? '')),
            60
        );

        $transportRecordCode = $this->buildTransportRecordCode(
            userId: $userId,
            insuranceCode: $insuranceCode,
            date: $row->date,
            rowNumber: $rowNumber
        );

        return [
            $rowNumber,
            $dayDD,
            $this->toAsciiString($row->personal_number ?? ''),
            $patientName,
            $this->toAsciiString($row->diagnosis_code ?? ''),
            '',
            '',
            self::TRANSPORT_TYPE_ADOS,
            (int) round($kilometers, 0),
            $this->toAsciiString($this->normalizeAddressOrCity($row->branch_city ?? null), self::ADDRESS_CITY_MAX_LENGTH),
            $this->toAsciiString($this->normalizeAddressOrCity($row->branch_address ?? null), self::ADDRESS_CITY_MAX_LENGTH),
            $this->toAsciiString($this->normalizeAddressOrCity($row->patient_city ?? null), self::ADDRESS_CITY_MAX_LENGTH),
            $this->toAsciiString($this->normalizeAddressOrCity($row->patient_address ?? null), self::ADDRESS_CITY_MAX_LENGTH),
            $transportRecordCode,
            $this->normalizeCode($userCar),
            '0',
            '',
            'N',
            $this->normalizeCode($row->doctor_pzs ?? ''),
            $this->normalizeCode($row->doctor_zpr ?? ''),
            '',
            '',
            '',
        ];
    }

    private function buildTransportRecordCode(
        int $userId,
        string $insuranceCode,
        mixed $date,
        int $rowNumber
    ): string {
        $formattedUserId = str_pad((string) $userId, 2, '0', STR_PAD_LEFT);
        $insuranceDigit = substr(trim($insuranceCode), -1);
        $month = Carbon::parse($date)->format('m');
        $formattedRowNumber = str_pad((string) $rowNumber, 3, '0', STR_PAD_LEFT);

        return $formattedUserId
            . $insuranceDigit
            . $month
            . $formattedRowNumber;
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

        if (!in_array($type, ['N', 'O'], true)) {
            $this->addValidationError(
                $errors,
                'Neplatný charakter dávky. Pre kilometrové dávky sú povolené hodnoty N alebo O.',
                'batch:invalid_type'
            );
        }

        if (!preg_match('/^\d{1,6}$/', (string) $batchNumber)) {
            $this->addValidationError(
                $errors,
                'Číslo dávky musí obsahovať iba číslice a môže mať maximálne 6 číslic.',
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
                insuranceCode: (string) $insuranceCode
            );
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
        string $insuranceCode
    ): void {
        $patientName = $this->formatPatientName($row);
        $label = "{$patientName} / riadok {$rowNumber}";

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
            $row->personal_number ?? null,
            "Chýba rodné číslo alebo BIČ pacienta: {$patientName}.",
            $this->patientErrorKey($row, 'missing_personal_number')
        );

        $this->addLengthBetweenError(
            $errors,
            $row->personal_number ?? null,
            9,
            10,
            "Rodné číslo alebo BIČ musí mať 9 až 10 znakov: {$patientName}.",
            $this->patientErrorKey($row, 'invalid_personal_number_length')
        );

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

        if ($userId < 1 || $userId > 99) {
            $this->addValidationError(
                $errors,
                'ID používateľa pre kód záznamu musí byť v rozsahu 1 až 99.',
                'user:invalid_transport_record_user_id'
            );
        }

        if ($rowNumber > 999) {
            $this->addValidationError(
                $errors,
                "Poradové číslo riadku môže mať maximálne 3 číslice: {$label}.",
                $this->rowErrorKey($row, $rowNumber, 'transport_record_row_number_too_large')
            );
        }

        if (
            $this->isFilledValue($row->date ?? null)
            && in_array((string) $insuranceCode, ['24', '25', '27'], true)
            && $userId >= 1
            && $userId <= 99
            && $rowNumber <= 999
        ) {
            $transportRecordCode = $this->buildTransportRecordCode(
                userId: $userId,
                insuranceCode: $insuranceCode,
                date: $row->date,
                rowNumber: $rowNumber
            );

            if (!preg_match('/^\d{8}$/', $transportRecordCode)) {
                $this->addValidationError(
                    $errors,
                    "Kód záznamu prepravy musí mať presne 8 číslic: {$label}.",
                    $this->rowErrorKey($row, $rowNumber, 'invalid_transport_record_code')
                );
            }
        }

        $fields = $this->build793nAdosBodyFields(
            row: $row,
            rowNumber: $rowNumber,
            kilometers: 0.0,
            userCar: $userCar,
            userId: $userId,
            insuranceCode: $insuranceCode
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
        $visitedAddressesPerDay = [];
        $calculatedRows = [];

        foreach ($rows as $index => $row) {
            $kilometers = 0.0;

            if ($this->hasValidCoords($row->branch_lat, $row->branch_lng, $row->patient_lat, $row->patient_lng)) {
                $dateString = $this->normalizeDateString($row->date);
                $visitedAddressesPerDay[$dateString] ??= [];

                if (!$this->hasNearbyVisitedAddress(
                    $visitedAddressesPerDay[$dateString],
                    (float) $row->patient_lat,
                    (float) $row->patient_lng,
                    self::GROUPING_BUFFER_METERS
                )) {
                    $kilometers = $this->getDistanceFromRouteService(
                        (float) $row->branch_lat,
                        (float) $row->branch_lng,
                        (float) $row->patient_lat,
                        (float) $row->patient_lng
                    );

                    $visitedAddressesPerDay[$dateString][] = [
                        'lat' => (float) $row->patient_lat,
                        'lng' => (float) $row->patient_lng,
                        'patient_id' => (int) $row->patient_id,
                        'pp_id' => (int) $row->id,
                    ];
                }
            }

            $calculatedRows[] = [
                'index' => $index,
                'source' => $row,
                'kilometers' => $kilometers,
            ];
        }

        return $calculatedRows;
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

    private function hasNearbyVisitedAddress(array $visitedPoints, float $lat, float $lng, float $bufferMeters = 100.0): bool
    {
        foreach ($visitedPoints as $point) {
            $distance = $this->distanceInMeters(
                (float) $point['lat'],
                (float) $point['lng'],
                $lat,
                $lng
            );

            if ($distance <= $bufferMeters) {
                return true;
            }
        }

        return false;
    }

    private function distanceInMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    private function getDistanceFromRouteService($startLat, $startLng, $endLat, $endLng): float
    {
        try {
            $baseUrl = rtrim(config('services.route_service.base_url'), '/');
            $endpoint = ltrim(config('services.route_service.endpoint', '/tsp-solver'), '/');
            $timeout = (int) config('services.route_service.timeout', 8);

            $url = "{$baseUrl}/{$endpoint}";

            $payload = [
                'start_location' => [(float) $startLng, (float) $startLat],
                'end_location' => [(float) $endLng, (float) $endLat],
                'points_locations' => [],
            ];

            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->withBody(json_encode($payload), 'application/json')
                ->send('GET', $url);

            if (!$response->successful()) {
                Log::warning('Route service failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return 0.0;
            }

            $json = $response->json();
            $lengthMeters = data_get($json, 'response.0.length');

            if (!is_numeric($lengthMeters)) {
                Log::warning('Route service: missing/invalid length', [
                    'parsed_length' => $lengthMeters,
                    'json' => $json,
                ]);

                return 0.0;
            }

            return ((float) $lengthMeters) / 1000.0;
        } catch (\Throwable $e) {
            Log::error('Route service error', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 0.0;
        }
    }

    private function hasValidCoords($branchLat, $branchLng, $patientLat, $patientLng): bool
    {
        return $branchLat !== null
            && $branchLng !== null
            && $patientLat !== null
            && $patientLng !== null;
    }

    private function normalizeCode(mixed $value): string
    {
        return strtoupper(trim((string) ($value ?? '')));
    }

    private function normalizeAddressAndCityFields($rows)
    {
        return $rows->map(function ($row) {
            $row->branch_city = $this->normalizeAddressOrCity($row->branch_city ?? null);
            $row->branch_address = $this->normalizeAddressOrCity($row->branch_address ?? null);
            $row->patient_city = $this->normalizeAddressOrCity($row->patient_city ?? null);
            $row->patient_address = $this->normalizeAddressOrCity($row->patient_address ?? null);

            return $row;
        });
    }

    private function normalizeAddressOrCity(mixed $value): string
    {
        $normalized = trim((string) ($value ?? ''));

        return mb_substr($normalized, 0, self::ADDRESS_CITY_MAX_LENGTH);
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

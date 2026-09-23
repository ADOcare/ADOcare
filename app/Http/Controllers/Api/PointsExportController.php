<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PointClaimBatch;
use App\Services\PointsBatchNumberService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PointsExportController extends Controller
{
    private const DATA_TYPE = '753d';
    private const CARE_TYPE_ADOS = '850';

    public function __construct(private PointsBatchNumberService $batchNumberService)
    {
    }

    public function preview(Request $request)
    {
        $data = $this->validateInput($request);
        $context = $this->buildExportContext($data);

        $this->validate753dAdosExportContext($context);

        $amount = $context['rows']
            ->sum(fn ($row) => (float) ($row->quantity ?? 0) * (float) ($row->price ?? 0));

        $sheet = [
            'batchNumber' => $context['batchNumber'],
            'fileName' => "davka.{$context['batchNumber']}.txt",
            'amount' => (string) $amount,
            'periodFrom' => $context['from'],
            'periodTo' => $context['to'],
            'performedBy' => $context['performedBy'],
            'performedDate' => now()->setTimezone('Europe/Bratislava')->toDateString(),
            'companyName' => $context['companyName'],
            'branchName' => $context['branchName'],
            'patients' => $context['patientIds'],
            'insuranceName' => $context['insuranceName'],
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

        $this->validate753dAdosExportContext($context);

        $content = $this->build753dAdosContent($context);
        $fileName = "davka.{$context['batchNumber']}.txt";

        if ($context['claimBatch']) {
            $context['claimBatch']->update([
                'status' => 'exported',
                'exported_at' => now(),
            ]);
            $context['claimBatch']->lines()
                ->where('status', 'pending')
                ->update(['status' => 'exported']);
        }

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

        $this->validate753dAdosExportContext($context);

        $amount = $context['rows']
            ->sum(fn ($row) => (float) ($row->quantity ?? 0) * (float) ($row->price ?? 0));

        $sheet = [
            'batchNumber' => $context['batchNumber'],
            'fileName' => "davka.{$context['batchNumber']}.txt",
            'amount' => (string) $amount,
            'periodFrom' => $context['from'],
            'periodTo' => $context['to'],
            'performedBy' => $context['performedBy'],
            'performedDate' => now()->setTimezone('Europe/Bratislava')->toDateString(),
            'companyName' => $context['companyName'],
            'branchName' => $context['branchName'],
            'insuranceName' => $context['insuranceName'],
            'fileType' => 'vykázané body',
        ];

        $pdf = Pdf::loadView('pdf.statement', [
            'sheet' => $sheet,
        ])->setPaper('a4');

        $pdfName = "sprievodny_list_{$sheet['batchNumber']}.pdf";

        return $pdf->download($pdfName);
    }

    private function validateInput(Request $request): array
    {
        $data = $request->validate([
            'batchType.code' => ['required', 'string', 'in:N,O,A,E,F,G,I,J,K'],
            'insurance.id' => ['required', 'integer'],
            'period' => ['required', 'array', 'size:2'],
            'period.*' => ['required', 'date'],

            'branch.id' => ['required', 'integer'],
            'claimBatchId' => ['nullable', 'integer', 'exists:point_claim_batches,id'],
            'pointIds' => ['nullable', 'array', 'min:1', 'required_without:patients'],
            'pointIds.*' => ['required', 'integer', 'distinct'],
            'patients' => ['nullable', 'array', 'min:1', 'required_without:pointIds'],
            'patients.*.id' => ['required', 'integer', 'distinct'],
        ]);

        $actor = $request->user();
        $branchId = (int) data_get($data, 'branch.id');
        $branch = DB::table('branches')->where('id', $branchId)->first(['id', 'company_id']);

        abort_unless($actor && $branch && $actor->isInBranch($branchId), 403);

        data_set($data, 'user.id', (int) $actor->id);
        data_set($data, 'company.id', (int) $branch->company_id);

        return $data;
    }

    private function buildExportContext(array $data): array
    {
        $from = $this->parseDateOnly($data['period'][0]);
        $to = $this->parseDateOnly($data['period'][1]);

        $type = (string) data_get($data, 'batchType.code');
        $userId = (int) data_get($data, 'user.id');
        $branchId = (int) data_get($data, 'branch.id');
        $companyId = (int) data_get($data, 'company.id');
        $insuranceId = (int) data_get($data, 'insurance.id');
        $claimBatchId = (int) data_get($data, 'claimBatchId', 0);
        $batchNumber = $this->batchNumberService->make($userId, $insuranceId, $from);

        $pointIds = collect(data_get($data, 'pointIds', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $legacyPatientIds = collect(data_get($data, 'patients', []))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
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
            ->select('id', 'name', 'branch_code')
            ->first();

        $rows = DB::table('patient_points as pp')
            ->join('patients as p', 'p.id', '=', 'pp.patient_id')
            ->leftJoin('doctors as d', 'd.id', '=', 'p.doctor_id')
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
            ->leftJoin('procedure_company_prices as pcp', function ($join) use ($companyId) {
                $join->on('pcp.procedure_id', '=', 'pp.procedure_id')
                    ->on('pcp.insurance_company_id', '=', 'pc.insurance_company_id')
                    ->where('pcp.company_id', '=', $companyId);
            })
            ->where('pp.user_id', $userId)
            ->where('pp.branch_id', $branchId)
            ->where('pc.insurance_company_id', $insuranceId)
            ->whereBetween('pp.date', [$from, $to])
            ->when(!empty($pointIds), fn ($query) => $query->whereIn('pp.id', $pointIds))
            ->when(empty($pointIds), fn ($query) => $query->whereIn('pp.patient_id', $legacyPatientIds))
            ->when(in_array($type, ['N', 'O', 'A'], true), fn ($query) => $query->where('pc.regime', 'domestic'))
            ->when(in_array($type, ['E', 'F', 'G'], true), fn ($query) => $query->where('pc.regime', 'eu'))
            ->when(in_array($type, ['I', 'J', 'K'], true), fn ($query) => $query->where('pc.regime', 'special'))
            ->orderBy('pp.date')
            ->select([
                'pp.id as patient_point_id',
                'pp.date',
                'pp.reference_date as request_date',

                'p.id as patient_id',
                'p.personal_number',
                'p.last_name',
                'p.first_name',
                'p.sex',
                'p.latitude',
                'p.longitude',
                'pc.member_state_code as country_code',
                'pc.foreign_insured_id',

                'pp.diagnosis_code',
                'pp.procedure_code',
                'pp.quantity',

                'd.pzs as doctor_pzs',
                'd.zpr as doctor_zpr',

                'pcp.price',

                DB::raw("'O' as sender_type"),
                DB::raw("NULL as patient_type"),
            ])
            ->get();

        $claimBatch = null;
        if ($claimBatchId > 0) {
            $claimBatch = PointClaimBatch::query()
                ->whereKey($claimBatchId)
                ->where('healthcare_worker_id', $userId)
                ->where('branch_id', $branchId)
                ->where('insurance_company_id', $insuranceId)
                ->where('batch_type', $type)
                ->with('lines')
                ->first();

            if (! $claimBatch) {
                throw ValidationException::withMessages([
                    'claimBatchId' => ['Uložená dávka sa nenašla alebo k nej nemáte prístup.'],
                ]);
            }

            $batchNumber = (string) $claimBatch->batch_number;

            $pointIds = $claimBatch->lines
                ->pluck('patient_point_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            $rows = $claimBatch->lines
                ->map(fn ($line) => (object) $line->snapshot)
                ->values();
        }

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
            'patientIds' => $rows->pluck('patient_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'pointIds' => $pointIds,
            'claimBatch' => $claimBatch,

            'company' => $company,
            'branch' => $branch,
            'user' => $user,
            'workingTime' => $workingTime ?? 0,
            'insurance' => $insurance,

            'rows' => $rows,

            'companyName' => $companyName,
            'branchName' => $branchName,
            'performedBy' => $performedBy !== '' ? $performedBy : "User #{$userId}",
            'insuranceName' => $insurance?->name,
            'insuranceBranchCode' => $insurance?->branch_code,
        ];
    }

    private function build753dAdosContent(array $context): string
    {
        $type = $context['type'];
        $batchNumber = $context['batchNumber'];
        $rows = $context['rows'];

        $company = $context['company'];
        $branch = $context['branch'];
        $user = $context['user'];
        $workingTime = $context['workingTime'];
        $insuranceBranchCode = $context['insuranceBranchCode'];

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
            self::CARE_TYPE_ADOS,
            $batchNumber,
            'EUR',
        ];

        $lines = [
            $this->formatTextLine($line1Fields, 9, 'Identifikácia dávky musí mať presne 9 polí.'),
            $this->formatTextLine($line2Fields, 8, 'Záhlavie dávky musí mať presne 8 polí.'),
        ];

        foreach ($rows as $index => $row) {
            $bodyFields = $this->build753dAdosBodyFields($row, $index + 1, $type);

            $lines[] = $this->formatTextLine(
                $bodyFields,
                38,
                'Veta tela dávky na riadku ' . ($index + 1) . ' musí mať presne 38 polí.'
            );
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    private function build753dAdosBodyFields(object $row, int $rowNumber, string $type): array
    {
        $isEuBatch = in_array($type, ['E', 'F', 'G'], true);

        $dayDD = Carbon::parse($row->date)->format('d');
        $requestDateYmd = Carbon::parse($row->request_date ?? $row->date)->format('Ymd');

        $patientName = $this->toAsciiString(
            trim(($row->last_name ?? '') . ' ' . ($row->first_name ?? '')),
            60
        );

        return [
            $rowNumber,
            $dayDD,
            $isEuBatch ? '' : $this->toAsciiString($row->personal_number ?? ''),
            $patientName,
            $this->toAsciiString($row->diagnosis_code ?? ''),
            $this->toAsciiString($row->procedure_code ?? ''),
            $row->quantity ?? 1,
            '',
            '',
            $this->toAsciiString($row->patient_type ?? ''),
            '',
            '',
            '',
            '',
            '',
            '',
            $this->normalizeCode($row->sender_type ?? 'O'),
            $this->normalizeCode($row->doctor_pzs ?? ''),
            $this->normalizeCode($row->doctor_zpr ?? ''),
            $isEuBatch ? $this->normalizeCode($row->country_code ?? '') : '',
            $isEuBatch ? $this->toAsciiString($row->foreign_insured_id ?? '') : '',
            $isEuBatch ? $this->normalizeCode($row->sex ?? '') : '',
            $requestDateYmd,
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ];
    }

    private function validate753dAdosExportContext(array $context): void
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
        $insuranceBranchCode = $context['insuranceBranchCode'];
        $rows = $context['rows'];

        if (!in_array($type, ['N', 'O', 'A', 'E', 'F', 'G', 'I', 'J', 'K'], true)) {
            $this->addValidationError(
                $errors,
                'Neplatný charakter dávky. Povolené hodnoty sú N, O, A, E, F, G, I, J, K.',
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
                'Nenašli sa žiadne výkony pre zadané filtre.',
                'batch:no_rows'
            );
        }

        $this->addMissingError($errors, $company, 'Spoločnosť neexistuje.', 'company:missing');
        $this->addMissingError($errors, $company?->ico ?? null, 'Chýba IČO spoločnosti.', 'company:missing_ico');
        $this->addPatternError(
            $errors,
            $company?->ico ?? null,
            '/^\d{8}$/',
            'IČO spoločnosti musí mať presne 8 číslic.',
            'company:invalid_ico'
        );
        $this->addMissingError($errors, $company?->name ?? null, 'Chýba názov spoločnosti.', 'company:missing_name');

        $this->addMissingError($errors, $branch, 'Prevádzka neexistuje.', 'branch:missing');
        $this->addMissingError($errors, $branch?->identificator ?? null, 'Chýba identifikátor PZS.', 'branch:missing_identificator');

        $this->addPatternError(
            $errors,
            $branch?->identificator ?? null,
            '/^P\d{5}$/',
            'Identifikátor PZS musí mať 6 znakov: písmeno P a za ním 5 číslic, napr. P12345.',
            'branch:invalid_identificator'
        );

        $this->addMissingError($errors, $branch?->code ?? null, 'Chýba kód PZS.', 'branch:missing_code');

        $this->addPatternError(
            $errors,
            $branch?->code ?? null,
            '/^P\d{11}$/',
            'Kód PZS musí mať 12 znakov: písmeno P a za ním 11 číslic, napr. P12345678901.',
            'branch:invalid_code'
        );

        $this->addMissingError($errors, $user, 'Používateľ neexistuje.', 'user:missing');
        $this->addMissingError($errors, $user?->code ?? null, 'Chýba kód ZPR používateľa.', 'user:missing_code');

        $this->addPatternError(
            $errors,
            $user?->code ?? null,
            '/^[A-Z]\d{8}$/',
            'Kód ZPR používateľa musí mať 9 znakov: jedno písmeno a za ním 8 číslic, napr. A12345678.',
            'user:invalid_code'
        );

        $this->addMissingError(
            $errors,
            $workingTime,
            'Chýba pracovný čas používateľa na prevádzke.',
            'user_branch:missing_working_time'
        );

        if ($this->isFilledValue($workingTime) && !is_numeric($workingTime)) {
            $this->addValidationError(
                $errors,
                'Pracovný čas používateľa na prevádzke musí byť číslo.',
                'user_branch:invalid_working_time'
            );
        }

        $this->addMissingError(
            $errors,
            $insuranceBranchCode,
            'Chýba kód pobočky poisťovne.',
            'insurance:missing_branch_code'
        );

        $this->addPatternError(
            $errors,
            $insuranceBranchCode,
            '/^\d{4}$/',
            'Kód pobočky poisťovne musí mať presne 4 číslice.',
            'insurance:invalid_branch_code'
        );

        foreach ($rows as $index => $row) {
            $this->validate753dAdosRow($errors, $row, $index + 1, $type, $from, $to);
        }

        $this->throwPointsValidationErrors($errors);
    }

    private function validate753dAdosRow(
        array &$errors,
        object $row,
        int $rowNumber,
        string $type,
        string $from,
        string $to
    ): void {
        $patientName = $this->formatPatientName($row);
        $patientLabel = $patientName !== '' ? $patientName : "pacient #{$row->patient_id}";
        $rowLabel = "{$patientLabel} / riadok {$rowNumber}";
        $isEuBatch = in_array($type, ['E', 'F'], true);

        $this->addMissingError(
            $errors,
            $row->date ?? null,
            "Chýba dátum výkonu: {$rowLabel}.",
            $this->rowErrorKey($row, $rowNumber, 'missing_date')
        );

        if ($this->isFilledValue($row->date ?? null)) {
            $date = Carbon::parse($row->date)->toDateString();

            if ($date < $from || $date > $to) {
                $this->addValidationError(
                    $errors,
                    "Dátum výkonu nie je v zadanom období: {$rowLabel}.",
                    $this->rowErrorKey($row, $rowNumber, 'date_out_of_period')
                );
            }
        }

        $this->addMissingError(
            $errors,
            $row->last_name ?? null,
            "Chýba priezvisko pacienta: {$patientLabel}.",
            $this->patientErrorKey($row, 'missing_last_name')
        );

        $this->addMissingError(
            $errors,
            $row->first_name ?? null,
            "Chýba meno pacienta: {$patientLabel}.",
            $this->patientErrorKey($row, 'missing_first_name')
        );

        $fullName = $this->toAsciiString(trim(($row->last_name ?? '') . ' ' . ($row->first_name ?? '')));

        if ($this->isFilledValue($fullName) && mb_strlen($fullName) > 60) {
            $this->addValidationError(
                $errors,
                "Meno poistenca môže mať maximálne 60 znakov: {$patientLabel}.",
                $this->patientErrorKey($row, 'invalid_full_name_length')
            );
        }

        if ($isEuBatch) {
            $this->addMissingError(
                $errors,
                $row->country_code ?? null,
                "Chýba členský štát poistenca: {$patientLabel}.",
                $this->patientErrorKey($row, 'missing_country_code')
            );

            $this->addLengthBetweenError(
                $errors,
                $row->country_code ?? null,
                1,
                3,
                "Členský štát poistenca musí mať 1 až 3 znaky: {$patientLabel}.",
                $this->patientErrorKey($row, 'invalid_country_code_length')
            );

            $this->addMissingError(
                $errors,
                $row->personal_number ?? null,
                "Chýba identifikačné číslo poistenca: {$patientLabel}.",
                $this->patientErrorKey($row, 'missing_foreign_patient_number')
            );

            $this->addLengthBetweenError(
                $errors,
                $row->personal_number ?? null,
                1,
                20,
                "Identifikačné číslo poistenca musí mať 1 až 20 znakov: {$patientLabel}.",
                $this->patientErrorKey($row, 'invalid_foreign_patient_number_length')
            );

            $this->addMissingError(
                $errors,
                $row->sex ?? null,
                "Chýba pohlavie poistenca: {$patientLabel}.",
                $this->patientErrorKey($row, 'missing_sex')
            );

            $this->addPatternError(
                $errors,
                $row->sex ?? null,
                '/^[MF]$/',
                "Pohlavie poistenca musí byť M alebo F: {$patientLabel}.",
                $this->patientErrorKey($row, 'invalid_sex')
            );
        } else {
            $this->addMissingError(
                $errors,
                $row->personal_number ?? null,
                "Chýba rodné číslo alebo BIČ pacienta: {$patientLabel}.",
                $this->patientErrorKey($row, 'missing_personal_number')
            );

            $this->addLengthBetweenError(
                $errors,
                $row->personal_number ?? null,
                9,
                10,
                "Rodné číslo alebo BIČ musí mať 9 až 10 znakov: {$patientLabel}.",
                $this->patientErrorKey($row, 'invalid_personal_number_length')
            );
        }

        $this->addMissingError(
            $errors,
            $row->diagnosis_code ?? null,
            "Chýba diagnóza: {$patientLabel}.",
            $this->patientErrorKey($row, 'missing_diagnosis_code')
        );

        $this->addLengthBetweenError(
            $errors,
            $row->diagnosis_code ?? null,
            3,
            5,
            "Kód diagnózy musí mať 3 až 5 znakov: {$patientLabel}.",
            $this->patientErrorKey($row, 'invalid_diagnosis_length')
        );

        $this->addPatternError(
            $errors,
            $row->diagnosis_code ?? null,
            '/^[A-Z][0-9A-Z]{2,4}$/i',
            "Kód diagnózy musí byť bez bodky a bez špeciálnych znakov: {$patientLabel}.",
            $this->patientErrorKey($row, 'invalid_diagnosis_format')
        );

        $this->addMissingError(
            $errors,
            $row->procedure_code ?? null,
            "Chýba kód výkonu: {$rowLabel}.",
            $this->rowErrorKey($row, $rowNumber, 'missing_procedure_code')
        );

        $this->addLengthBetweenError(
            $errors,
            $row->procedure_code ?? null,
            1,
            7,
            "Kód výkonu musí mať 1 až 7 znakov: {$rowLabel}.",
            $this->rowErrorKey($row, $rowNumber, 'invalid_procedure_code_length')
        );

        $this->addMissingError(
            $errors,
            $row->quantity ?? null,
            "Chýba počet výkonov: {$rowLabel}.",
            $this->rowErrorKey($row, $rowNumber, 'missing_quantity')
        );

        if ($this->isFilledValue($row->quantity ?? null) && (!is_numeric($row->quantity) || (float) $row->quantity < 0)) {
            $this->addValidationError(
                $errors,
                "Počet výkonov musí byť nezáporné číslo: {$rowLabel}.",
                $this->rowErrorKey($row, $rowNumber, 'invalid_quantity')
            );
        }

        $this->addMissingError(
            $errors,
            $row->price ?? null,
            "Chýba cena výkonu v cenníku poisťovne: {$patientLabel}.",
            $this->patientErrorKey($row, 'missing_price')
        );

        if ($this->isFilledValue($row->price ?? null) && !is_numeric($row->price)) {
            $this->addValidationError(
                $errors,
                "Cena výkonu v cenníku poisťovne musí byť číslo: {$patientLabel}.",
                $this->patientErrorKey($row, 'invalid_price')
            );
        }

        $senderType = $this->normalizeCode($row->sender_type ?? 'O');

        $this->addMissingError(
            $errors,
            $senderType,
            "Chýba typ odosielateľa: {$rowLabel}.",
            $this->rowErrorKey($row, $rowNumber, 'missing_sender_type')
        );

        $this->addPatternError(
            $errors,
            $senderType,
            '/^[DAKPO]$/',
            "Typ odosielateľa musí byť D, A, K, P alebo O: {$rowLabel}.",
            $this->rowErrorKey($row, $rowNumber, 'invalid_sender_type')
        );

        if ($this->normalizeCode($row->patient_type ?? '') === 'N' && $senderType !== 'A') {
            $this->addValidationError(
                $errors,
                "Ak je typ poistenca N, typ odosielateľa musí byť A: {$rowLabel}.",
                $this->rowErrorKey($row, $rowNumber, 'invalid_sender_for_patient_type')
            );
        }

        if ($senderType === 'O') {
            $this->addMissingError(
                $errors,
                $row->doctor_pzs ?? null,
                "Chýba kód PZS odosielateľa: {$patientLabel}.",
                $this->patientErrorKey($row, 'missing_doctor_pzs')
            );

            $this->addMissingError(
                $errors,
                $row->doctor_zpr ?? null,
                "Chýba kód ZPR odosielateľa: {$patientLabel}.",
                $this->patientErrorKey($row, 'missing_doctor_zpr')
            );
        }

        $this->addPatternError(
            $errors,
            $row->doctor_pzs ?? null,
            '/^[A-Z]\d{11}$/',
            "Kód PZS odosielateľa musí mať 12 znakov, písmeno  a za ním 11 číslic: {$patientLabel}.",
            $this->patientErrorKey($row, 'invalid_doctor_pzs')
        );

        $this->addPatternError(
            $errors,
            $row->doctor_zpr ?? null,
            '/^[A-Z]\d{8}$/',
            "Kód ZPR odosielateľa musí mať 9 znakov: jedno písmeno a za ním 8 číslic: {$patientLabel}.",
            $this->patientErrorKey($row, 'invalid_doctor_zpr')
        );

        if ($this->isFilledValue($row->doctor_pzs ?? null)) {
            $this->addMissingError(
                $errors,
                $row->request_date ?? null,
                "Chýba dátum vystavenia žiadanky alebo výmenného lístka: {$rowLabel}.",
                $this->rowErrorKey($row, $rowNumber, 'missing_request_date')
            );
        }

        if ($this->isFilledValue($row->request_date ?? null)) {
            $requestDate = Carbon::parse($row->request_date)->format('Ymd');

            if (!preg_match('/^\d{8}$/', $requestDate)) {
                $this->addValidationError(
                    $errors,
                    "Dátum vystavenia žiadanky alebo výmenného lístka musí byť vo formáte RRRRMMDD: {$rowLabel}.",
                    $this->rowErrorKey($row, $rowNumber, 'invalid_request_date')
                );
            }
        }

        $fields = $this->build753dAdosBodyFields($row, $rowNumber, $type);

        if (count($fields) !== 38) {
            $this->addValidationError(
                $errors,
                "Interná chyba: veta tela dávky 753d musí mať presne 38 polí: {$rowLabel}.",
                $this->rowErrorKey($row, $rowNumber, 'invalid_field_count')
            );
        }
    }

    private function formatTextLine(array $fields, int $expectedCount, string $errorMessage): string
    {
        if (count($fields) !== $expectedCount) {
            throw ValidationException::withMessages([
                'points_export' => [$errorMessage],
            ]);
        }

        $fields = array_map(function ($field) {
            return $this->toAsciiString((string) $field);
        }, $fields);

        return implode('|', $fields) . '|';
    }

    private function deduplicateNearbyProcedureRows(iterable $rows, int $radiusMeters = 50): array
    {
        $seenAddresses = [];
        $filteredRows = [];

        foreach ($rows as $row) {
            $procedureCode = (string) ($row->procedure_code ?? '');

            $shouldDeduplicate =
                in_array($procedureCode, ['3439', '3440'], true) &&
                $row->latitude !== null &&
                $row->longitude !== null;

            if (!$shouldDeduplicate) {
                $filteredRows[] = $row;
                continue;
            }

            $bucketKey = (string) $row->date . '|' . $procedureCode;

            if (!isset($seenAddresses[$bucketKey])) {
                $seenAddresses[$bucketKey] = [];
            }

            $currentLatitude = (float) $row->latitude;
            $currentLongitude = (float) $row->longitude;

            $isNearby = false;

            foreach ($seenAddresses[$bucketKey] as $seenPoint) {
                $distanceMeters = $this->distanceInMeters(
                    $currentLatitude,
                    $currentLongitude,
                    $seenPoint['latitude'],
                    $seenPoint['longitude']
                );

                if ($distanceMeters <= $radiusMeters) {
                    $isNearby = true;
                    break;
                }
            }

            if ($isNearby) {
                continue;
            }

            $seenAddresses[$bucketKey][] = [
                'latitude' => $currentLatitude,
                'longitude' => $currentLongitude,
            ];

            $filteredRows[] = $row;
        }

        return $filteredRows;
    }

    private function distanceInMeters(
        float $latitude1,
        float $longitude1,
        float $latitude2,
        float $longitude2
    ): float {
        $earthRadius = 6371000;

        $latFrom = deg2rad($latitude1);
        $lonFrom = deg2rad($longitude1);
        $latTo = deg2rad($latitude2);
        $lonTo = deg2rad($longitude2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(
            pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
        ));

        return $earthRadius * $angle;
    }

    private function normalizeCode(mixed $value): string
    {
        return strtoupper(trim((string) ($value ?? '')));
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
        $rowId = $row->patient_point_id ?? $row->id ?? $rowNumber;

        return "row:{$rowId}:{$field}";
    }

    private function throwPointsValidationErrors(array $errors): void
    {
        $errors = array_values(array_unique($errors));

        if (!$errors) {
            return;
        }

        throw ValidationException::withMessages([
            'points_export' => $errors,
        ]);
    }

    private function parseDateOnly(mixed $value): string
    {
        $value = (string) $value;

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches)) {
            return $matches[0];
        }

        return Carbon::parse($value)->toDateString();
    }
}

<!doctype html>
<html lang="sk">

<head>
    <meta charset="utf-8">
    <style>
        @page {
            size: A4 portrait;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            width: auto;
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #000;
            background: #fff;
            box-sizing: border-box;
        }

        html {
            margin: 14mm;
        }

        * {
            box-sizing: border-box;
        }

        .document-content {
            width: auto;
            max-width: none;
            margin: 0 auto;
            box-sizing: border-box;
        }

        .title {
            text-align: center;
            font-weight: 700;
            font-size: 16px;
            margin-bottom: 14px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 6px;
            text-align: left;
        }

        td {
            border: 1px solid #000;
            padding: 5px;
            vertical-align: top;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        .no-border {
            border: none;
            padding: 0;
        }

        .signature-block {
            margin-top: 16px;
            display: table;
            width: 100%;
            table-layout: fixed;
            text-align: center;
            font-size: 10px;
        }

        .signature-box {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            padding: 0 8px;
        }

        .signature-area {
            height: 90px;
            margin-bottom: 6px;
            position: relative;
        }

        .signature-image,
        .stamp-image {
            position: absolute;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
        }

        .stamp-image {
            max-width: 150px;
            max-height: 90px;
            z-index: 1;
        }

        .signature-image {
            max-width: 140px;
            max-height: 55px;
            z-index: 2;
        }

        .line {
            border-top: 1px solid #000;
            margin-top: 2px;
            height: 0;
        }

        .text-justify {
            text-align: justify;
        }

        .fill-box {
            margin-top: 6px;
            min-height: 18px;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .option-list {
            margin-top: 6px;
        }

        .option-row {
            margin: 1px 0;
        }

        .compact-list {
            margin: 0;
            padding-left: 16px;
        }

        .compact-list li {
            margin: 1px 0;
        }
    </style>
</head>

<body>
    @php
        $normalizeDiagnosis = static function ($value): array {
            if (!is_array($value)) {
                $label = trim((string) $value);
                return $label === '' ? [] : [explode(' - ', $label)[0]];
            }

            $items = [];

            foreach ($value as $item) {
                $label = is_array($item)
                    ? trim((string) ($item['description'] ?? $item['code'] ?? ''))
                    : trim((string) $item);

                if ($label !== '') {
                    $items[] = explode(' - ', $label)[0];
                }
            }

            return $items;
        };

        $frequencyMap = [
            'once' => 'Jednorazovo',
            'daily' => 'Denne',
            'twice_daily' => '2× denne',
            'three_times_daily' => '3× denne',
            '1x_daily' => '1x denne',
            '2x_daily' => '2x denne',
            '3x_daily' => '3x denne',
            '4x_daily' => '4x denne',
            '5x_daily' => '5x denne',
            '6x_daily' => '6x denne',
            'every_other_day' => 'Každý druhý deň',
            'three_times_weekly' => '3x týždenne',
            'twice_weekly' => '2x týždenne',
            'once_weekly' => '1x týždenne',
            'twice_monthly' => '2x mesačne',
            'once_monthly' => '1x mesačne',
            'weekdays' => 'V pracovné dni',
            'weekends' => 'Počas víkendov a sviatkov',
            'as_needed' => 'Podľa potreby',
        ];

        $translateFrequency = static function (string $value) use ($frequencyMap): string {
            return $frequencyMap[$value] ?? $value;
        };

        $doctorDiagnoses = $normalizeDiagnosis($proposalData['diagnosis'] ?? []);
        $nurseDiagnoses = $normalizeDiagnosis($proposalData['nurse_diagnosis'] ?? []);

        $procedures = [];
        foreach (($proposalData['procedures'] ?? []) as $procedure) {
            $code = trim((string) (is_array($procedure) ? ($procedure['code'] ?? '') : data_get($procedure, 'code', '')));
            $frequency = trim((string) (is_array($procedure) ? ($procedure['frequency'] ?? '') : data_get($procedure, 'frequency', '')));

            if ($code !== '') {
                $procedures[] = $code . ' – ' . $translateFrequency($frequency);
            }
        }

        $expectedDuration = [
            'one_month' => 'do1mesiac',
            'three_months' => 'do3mesiacov',
            'six_months' => 'do6mesiacov',
            'over_six_months' => 'nad6mesiacov',
        ][$proposalData['expected_duration'] ?? ''] ?? '';

        $documentDate = !empty($proposalData['date'])
            ? \Carbon\Carbon::parse($proposalData['date'])->format('d. m. Y')
            : '';

        $patientMobility = is_array($proposalData['mobility'] ?? null)
            ? ($proposalData['mobility'][0] ?? '')
            : '';
    @endphp

    <div class="document-content">
        <div class="title">NÁVRH NA POSKYTOVANIE OŠETROVATEĽSKEJ STAROSTLIVOSTI</div>

        <table>
            <tbody>
                <tr>
                    <td style="width: 60%;">
                        Zdravotnícke zariadenie:<br/>
                        <strong>{{ $proposalData['company_name'] ?? '' }}</strong>
                    </td>
                    <td style="width: 40%;">
                        so sídlom v:<br/>
                        <strong>{{ $proposalData['company_address'] ?? '' }}</strong>
                    </td>
                </tr>
            </tbody>
        </table>

        <table>
            <tbody>
                <tr>
                    <td style="width: 50%;">
                        Meno, priezvisko, titul pacienta/pacientky:<br/>
                        <strong>{{ $proposalData['patient_name'] ?? '' }}</strong>
                    </td>
                    <td style="width: 25%;">
                        Rodné číslo:<br/>
                        <strong>{{ $proposalData['patient_birth_number'] ?? '' }}</strong>
                    </td>
                    <td style="width: 25%;">
                        Kód ZP:<br/>
                        <strong>{{ $proposalData['insurance_code'] ?? '' }}</strong>
                    </td>
                </tr>
                <tr>
                    <td colspan="3">
                        Bydlisko trvalé:<br/>
                        <strong>{{ $proposalData['patient_address'] ?? '' }}</strong>
                    </td>
                </tr>
                <tr>
                    <td colspan="3">
                        Bydlisko prechodné:<br/>
                    </td>
                </tr>
                <tr>
                    <td colspan="3">
                        <strong>Kategória pacienta:</strong>
                        <div class="option-list">
                            <div class="option-row">
                                <input type="checkbox" {{ $patientMobility === 'H' ? 'checked' : '' }} disabled />
                                <span>H – pacient/pacientka s obmedzenou pohyblivosťou (50%)</span>
                            </div>
                            <div class="option-row">
                                <input type="checkbox" {{ $patientMobility === 'I' ? 'checked' : '' }} disabled />
                                <span>I – imobilný pacient/pacientka (75%)</span>
                            </div>
                            <div class="option-row">
                                <input type="checkbox" {{ $patientMobility === 'F' ? 'checked' : '' }} disabled />
                                <span>F – psychiatrická diagnóza / mentálne retardovaný (75%)</span>
                            </div>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>

        <table>
            <tbody>
                <tr>
                    <td style="width: 50%;">
                        Lekárska diagnóza:<br/>
                        <strong>{{ !empty($doctorDiagnoses) ? implode(', ', $doctorDiagnoses) : '-' }}</strong>
                    </td>
                    <td style="width: 50%;">
                        Sesterská diagnóza:<br/>
                        <strong>{{ !empty($nurseDiagnoses) ? implode(', ', $nurseDiagnoses) : '-' }}</strong>
                    </td>
                </tr>
            </tbody>
        </table>

        <table>
            <tbody>
                <tr>
                    <td>
                        <strong>Epikríza a zdôvodnenie:</strong>
                        <div class="fill-box" style="text-align: justify;">{{ $proposalData['epicrisis'] ?? '' }}</div>
                    </td>
                </tr>
                <tr>
                    <td>
                        <strong>Plán ošetrovateľskej starostlivosti:</strong>
                        <div class="fill-box" style="text-align: justify;">{{ $proposalData['care_plan'] ?? '' }}</div>
                    </td>
                </tr>
                <tr>
                    <td>
                        <strong>Výkony a frekvencia:</strong>
                        <div class="fill-box" style="text-align: justify;">{{ !empty($procedures) ? implode(', ', $procedures) : '' }}</div>
                    </td>
                </tr>
                <tr>
                    <td>
                        <strong>Predpokladaná dĺžka:</strong>
                        <div class="option-list">
                            <div class="option-row">
                                <input type="radio" {{ $expectedDuration === 'do1mesiac' ? 'checked' : '' }} disabled />
                                <span>do 1 mesiaca</span>
                            </div>
                            <div class="option-row">
                                <input type="radio" {{ $expectedDuration === 'do3mesiacov' ? 'checked' : '' }} disabled />
                                <span>do 3 mesiacov</span>
                            </div>
                            <div class="option-row">
                                <input type="radio" {{ $expectedDuration === 'do6mesiacov' ? 'checked' : '' }} disabled />
                                <span>do 6 mesiacov</span>
                            </div>
                            <div class="option-row">
                                <input type="radio" {{ $expectedDuration === 'nad6mesiacov' ? 'checked' : '' }} disabled />
                                <span>nad 6 mesiacov</span>
                            </div>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>

        <table>
            <tbody>
                <tr>
                    <td style="width: 75%;">
                        Lekár:<br/>
                        <strong>{{ $proposalData['doctor_name'] ?? '' }}</strong>
                    </td>
                    <td style="width: 25%;">
                        Dátum:<br/>
                        <strong>{{ $documentDate }}</strong>
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="signature-block">
            <div class="signature-box">
                <div class="signature-area"></div>

                <div class="line"></div>
                <div>podpis lekára a pečiatka</div>
            </div>

            <div class="signature-box">
                <div class="signature-area">
                    @if(!empty($stampDataUri))
                        <img src="{{ $stampDataUri }}" alt="Pečiatka spoločnosti" class="stamp-image">
                    @endif

                    @if(!empty($signatureDataUri))
                        <img src="{{ $signatureDataUri }}" alt="Podpis odborného zástupcu" class="signature-image">
                    @endif
                </div>

                <div class="line"></div>
                <div>{{ $proposalData['representative_name'] ?? '' }}</div>
                <div style="font-size: 9px;">
                    odborný zástupca poskytovateľa ošetrovateľskej starostlivosti
                </div>
            </div>
        </div>
    </div>
</body>

</html>
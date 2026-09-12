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
            font-size: 14px;
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

        .record-sheet {
            width: 100%;
            max-width: none;
            margin: 0 auto;
        }

        .page {
            width: 100%;
        }

        .header {
            line-height: 1.2;
        }

        .header-title {
            text-align: center;
            font-weight: 700;
            font-size: 16px;
            margin-bottom: 8px;
        }

        .header-sub {
            text-align: center;
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 6px;
            margin-top: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 8px;
            text-align: left;
            page-break-inside: avoid;
        }

        td {
            padding: 6px;
            vertical-align: top;
            overflow-wrap: break-word;
            word-wrap: break-word;
            border: 1px solid #000;

        }

        .field-table {
            font-size: 9px;
        }

        .field-table.compact td {
            padding: 4px 6px;
        }

        .field-table.full td {
            padding: 6px;
        }

        /* Section table styles: header row is bold and has stronger border; body rows have lighter separators */
        .section-table {
            border: 1px solid #000;
        }

        .section-table thead th {
            text-align: left;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 9px;
            padding: 8px 6px;
            border-bottom: 1px solid #000;
        }

        .section-table tbody td {
            border-bottom: 1px solid #ddd;
            padding: 6px;
        }

        .section-table tbody tr:last-child td {
            border-bottom: none;
        }

        .section-title {
            font-weight: 700;
            text-transform: uppercase;
            font-size: 11px;
            margin: 6px 0;
            padding: 4px;
            border-bottom: 1px solid #000;
        }

        .field-label {
            font-weight: 700;
            font-size: 9px;
        }

        .field-value {
            white-space: pre-wrap;
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
        }

        ul.value-list {
            margin: 0 0 0 14px;
            padding: 0;
        }

        ul.value-list li {
            margin: 0 0 2px 0;
        }

        .footer {
            margin-top: 12px;
        }

        .signatures {
            display: table;
            width: 100%;
            table-layout: fixed;
        }

        .sign-cell {
            display: table-cell;
            vertical-align: top;
            padding: 8px;
            text-align: center;
        }

        .sign-area {
            height: 70px;
        }

        .stamp {
            max-width: 150px;
            max-height: 60px;
            object-fit: contain;
            opacity: 0.9;
        }

        .signature-img {
            max-width: 180px;
            max-height: 90px;
            object-fit: contain;
        }
    </style>
</head>

<body>
    <div class="record-sheet">
        <div class="page">
            <div class="header">
                <div class="header-title">OŠETROVATEĽSKÝ ZÁZNAM</div>

                <table>
                    <tbody>
                        <tr>
                            <td>
                                Zdravotnícke zariadenie:<br /><strong>{{ $recordData['company_name'] ?? ($recordData['facilityName'] ?? '') }}</strong>
                            </td>
                            <td>
                                Sídlo / adresa:<br /><strong>{{ $recordData['company_address'] ?? ($recordData['facilityAddress'] ?? '') }}</strong>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <table>
                    <tbody>
                        <tr>
                            <td>
                                Meno, priezvisko, titul pacienta/pacientky:<br /><strong>{{ $recordData['patient_name'] ?? ($recordData['patientName'] ?? '') }}</strong>
                            </td>
                            <td>
                                Rodné číslo:<br /><strong>{{ $recordData['patient_birth_number'] ?? ($recordData['patientIdNumber'] ?? '') }}</strong>
                            </td>
                            <td>
                                Kód ZP:<br /><strong>{{ $recordData['insurance_code'] ?? ($recordData['patientHealthCode'] ?? '') }}</strong>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="3">
                                Trvalý pobyt:<br /><strong>{{ $recordData['patient_address'] ?? ($recordData['patientCurrentAddress'] ?? '') }}</strong>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="content">
                @php
                    // Prefer an explicit form spec passed in recordData.form_spec; otherwise load the default spec config
                    $defaultSpecFile = resource_path('views/pdf/form_specs/record_form_spec.php');
                    $defaultSpec = file_exists($defaultSpecFile) ? include $defaultSpecFile : null;
                    $formSpec = $recordData['form_spec'] ?? $defaultSpec;
                    $formData = $recordData['form_data'] ?? ($recordData['formData'] ?? []);

                    $formatValue = function ($val) {
                        if (is_array($val)) {
                            return json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                        }
                        return (string) ($val ?? '');
                    };

                    $isFilledValue = function ($val) use (&$isFilledValue) {
                        if ($val === null || $val === '') {
                            return false;
                        }

                        if (is_array($val)) {
                            foreach ($val as $item) {
                                if ($isFilledValue($item)) {
                                    return true;
                                }
                            }

                            return false;
                        }

                        return true;
                    };

                    // load translations (generic + field-specific)
                    $translationsFile = resource_path('views/pdf/form_specs/record_form_translations.php');
                    $translationsConfig = file_exists($translationsFile) ? include $translationsFile : ['generic' => [], 'fields' => []];
                    $genericTranslations = $translationsConfig['generic'] ?? [];
                    $fieldTranslations = $translationsConfig['fields'] ?? [];
                @endphp

                @if ($formSpec && is_array($formSpec['sections'] ?? null))
                    @php
                        $fieldLabels = [];
                        $optionLabels = [];
                        $coveredFieldIds = [];

                        foreach ($formSpec['sections'] ?? [] as $specSection) {
                            foreach ($specSection['fields'] ?? [] as $specField) {
                                $fieldId = (string) ($specField['id'] ?? '');
                                if ($fieldId === '') {
                                    continue;
                                }

                                $fieldLabels[$fieldId] = $specField['label'] ?? $fieldId;
                                $coveredFieldIds[$fieldId] = true;

                                foreach ($specField['options'] ?? [] as $option) {
                                    if (is_array($option) && array_key_exists('value', $option)) {
                                        $optionLabels[$fieldId][(string) $option['value']] = $option['label'] ?? (string) $option['value'];
                                    }
                                }
                            }
                        }

                        // helper to read both submitted flat keys like 'nutrition.weightKg' and nested keys
                        $getNested = function ($data, $key) {
                            if ($key === null || $key === '') {
                                return null;
                            }

                            if (is_array($data) && array_key_exists($key, $data)) {
                                return $data[$key];
                            }

                            $parts = explode('.', $key);
                            $cur = $data;
                            foreach ($parts as $p) {
                                if (is_array($cur) && array_key_exists($p, $cur)) {
                                    $cur = $cur[$p];
                                } else {
                                    return null;
                                }
                            }
                            return $cur;
                        };

                        $translateScalar = function ($value, $fieldId = null) use ($genericTranslations, $fieldTranslations, $optionLabels) {
                            $lookup = is_bool($value) ? ($value ? '1' : '0') : (is_scalar($value) ? (string) $value : null);

                            if ($lookup === null) {
                                return null;
                            }

                            if ($fieldId && isset($optionLabels[$fieldId][$lookup])) {
                                return $optionLabels[$fieldId][$lookup];
                            }

                            if ($fieldId && isset($fieldTranslations[$fieldId][$lookup])) {
                                return $fieldTranslations[$fieldId][$lookup];
                            }

                            $normalized = strtolower($lookup);
                            if (isset($genericTranslations[$lookup])) {
                                return $genericTranslations[$lookup];
                            }

                            if (isset($genericTranslations[$normalized])) {
                                return $genericTranslations[$normalized];
                            }

                            return null;
                        };

                        $renderValue = function ($val, $fieldId = null) use ($formatValue, $translateScalar) {
                            if (is_null($val) || $val === '') {
                                return '-';
                            }

                            if (is_array($val)) {
                                $items = array_values($val);
                                $out = '<ul class="value-list">';
                                foreach ($items as $it) {
                                    if (is_array($it)) {
                                        $display = trim((string) (($it['code'] ?? '') . ((isset($it['code'], $it['description']) && $it['description'] !== '') ? ' - ' : '') . ($it['description'] ?? ($it['label'] ?? ($it['name'] ?? '')))));
                                        $out .= '<li>' . e($display !== '' ? $display : json_encode($it, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</li>';
                                        continue;
                                    }

                                    $translated = $translateScalar($it, $fieldId);
                                    $out .= '<li>' . e($translated ?? (string) $it) . '</li>';
                                }
                                $out .= '</ul>';
                                return $out;
                            }

                            $translated = $translateScalar($val, $fieldId);
                            if ($translated !== null) {
                                return e($translated);
                            }

                            return nl2br(e($formatValue($val)));
                        };

                        $humanizeFieldId = function ($key) use ($fieldLabels) {
                            if (isset($fieldLabels[$key]) && trim((string) $fieldLabels[$key]) !== '') {
                                return $fieldLabels[$key];
                            }

                            return str_replace(['_', '.'], [' ', ' → '], (string) $key);
                        };

                        $uncoveredFields = [];
                        foreach ($formData as $key => $value) {
                            if (!isset($coveredFieldIds[$key]) && $isFilledValue($value)) {
                                $uncoveredFields[$key] = $value;
                            }
                        }
                    @endphp

                    @foreach ($formSpec['sections'] as $section)
                        <table class="section-table">
                            <thead>
                                <tr>
                                    <th colspan="4">{{ $section['title'] ?? $section['id'] }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $fields = $section['fields'] ?? [];
                                    $count = count($fields);
                                @endphp

                                @if ($count === 0)
                                    <tr>
                                        <td colspan="4" class="field-value">-</td>
                                    </tr>
                                @elseif ($count <= 2 || ($section['layout'] ?? 'compact') === 'full')
                                    {{-- Render each field stacked (label then value) consuming full width --}}
                                    @foreach ($fields as $f)
                                        @php $val = $getNested($formData, $f['id']); @endphp
                                        <tr>
                                            <td colspan="4" class="field-label small">{{ $f['label'] ?? $f['id'] }}</td>
                                        </tr>
                                        <tr>
                                            <td colspan="4" class="field-value">{!! $renderValue($val, $f['id']) !!}</td>
                                        </tr>
                                    @endforeach
                                @else
                                    {{-- Render two items per row: label/value | label/value --}}
                                    @foreach (array_chunk($fields, 2) as $pair)
                                        <tr>
                                            @foreach ($pair as $f)
                                                @php $val = $getNested($formData, $f['id']); @endphp
                                                <td class="field-label" style="width:25%">{{ $f['label'] ?? $f['id'] }}</td>
                                                <td class="field-value" style="width:25%">{!! $renderValue($val, $f['id']) !!}</td>
                                            @endforeach
                                            @if (count($pair) === 1)
                                                <td style="width:25%"></td>
                                                <td style="width:25%"></td>
                                            @endif
                                        </tr>
                                    @endforeach
                                @endif
                            </tbody>
                        </table>
                    @endforeach

                    @if (!empty($uncoveredFields))
                        <table class="section-table">
                            <thead>
                                <tr>
                                    <th colspan="4">Ďalšie vyplnené údaje</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach (array_chunk($uncoveredFields, 2, true) as $pair)
                                    <tr>
                                        @foreach ($pair as $key => $value)
                                            <td class="field-label" style="width:25%">{{ $humanizeFieldId($key) }}</td>
                                            <td class="field-value" style="width:25%">{!! $renderValue($value, $key) !!}</td>
                                        @endforeach
                                        @if (count($pair) === 1)
                                            <td style="width:25%"></td>
                                            <td style="width:25%"></td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                @elseif (!empty($formData) && is_array($formData))
                    <div class="section-title">Pozorovaní a zistenia</div>
                    <table class="field-table compact">
                        <tbody>
                            @php $pairs = array_chunk($formData, 2, true); @endphp
                            @foreach ($pairs as $pair)
                                <tr>
                                    @foreach ($pair as $k => $v)
                                        <td class="field-label">{{ $k }}</td>
                                        <td class="field-value">{{ $formatValue($v) ?: '-' }}</td>
                                    @endforeach
                                    @if (count($pair) === 1)
                                        <td class="field-label"></td>
                                        <td class="field-value"></td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="section-title">Pozorovaní a zistenia</div>
                    <table class="field-table full">
                        <tbody>
                            <tr>
                                <td>Žiadne záznamy</td>
                            </tr>
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="footer">
                <div class="signatures">
                    <div class="sign-cell">
                        <div class="sign-area"></div>
                        <div style="border-top:1px solid #000; margin-top:6px;">podpis pacienta</div>
                    </div>
                    <div class="sign-cell">
                        <div class="sign-area">
                            @if (!empty($recordData['company_stamp'] ?? ($recordData['stampDataUri'] ?? null)))
                                <img src="{{ $recordData['company_stamp'] ?? ($recordData['stampDataUri'] ?? null) }}" alt="Pečiatka" class="stamp" />
                            @endif
                            @if (!empty($signatureDataUri))
                                <img src="{{ $signatureDataUri }}" alt="Podpis" class="signature-img" />
                            @endif
                        </div>
                        <div style="border-top:1px solid #000; margin-top:6px;">{{ $recordData['user_name'] ?? ($recordData['userName'] ?? '') }}</div>
                        <div style="font-size:10px;">zdravotný pracovník</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>

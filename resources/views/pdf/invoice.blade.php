<!doctype html>
<html lang="sk">

<head>
    <meta charset="utf-8">
    <style>
        @page {
            size: A4 portrait;
            margin: 0;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #000;
            background: #fff;
        }

        html {
            margin: 14mm
        }

        * {
            box-sizing: border-box;
        }

        .invoice-sheet {
            margin: 0 auto;
            /* padding: 6mm; */
            background: white;
            box-sizing: border-box;
        }

        .header-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 32px;
        }

        .invoice-title {
            font-size: 24px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .invoice-info {
            margin-bottom: 8px;
        }

        .invoice-info-right {
            text-align: right;
            font-size: 11px;
        }

        .invoice-info-right div {
            margin-bottom: 4px;
        }

        .section-title {
            font-weight: bold;
            margin-bottom: 8px;
        }

        .border-box {
            border: 1px solid #000;
            padding: 12px;
            margin-bottom: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }

        td,
        th {
            border: 1px solid #000;
            padding: 6px;
            vertical-align: top;
            font-size: 11px;
        }

        .text-right {
            text-align: right;
        }

        .text-bold {
            font-weight: bold;
        }

        .total-row td {
            font-weight: bold;
        }

        .dates-section {
            margin: 24px 0;
            font-size: 11px;
        }

        .invoice-text {
            margin: 24px 0;
            line-height: 1.6;
            font-size: 12px;
        }

        .items-table {
            margin: 24px 0;
        }

        .items-table thead tr {
            background: #f5f5f5;
        }

        .attachments-section {
            margin-top: 40px;
            font-size: 11px;
        }

        .inline-table {
            width: 100%;
            border-collapse: collapse;
        }

        .inline-table td {
            border: none;
            padding: 4px 0;
            font-size: 11px;
        }
    </style>
</head>

<body>
    <div class="invoice-sheet">
        @php
            $type = $invoiceData['type'] ?? 'procedures';
            $period = $invoiceData['period'] ?? '';
            [$year, $month] = explode('-', $period) + [0, 0];
            $periodLabel = $month && $year ? "$month.$year" : $period;

            function formatDate($dateStr)
            {
                if (!$dateStr) {
                    return '';
                }
                try {
                    return \Carbon\Carbon::parse($dateStr)->format('d.m.Y');
                } catch (\Exception $e) {
                    return $dateStr;
                }
            }

            function formatCurrency($amount)
            {
                return number_format($amount ?? 0, 2, ',', ' ') . ' €';
            }

            $invoiceText = '';
            if ($type === 'credit_note') {
                $invoiceText = 'Dobropis k faktúre č. ' . ($invoiceData['related_invoice_number'] ?? '') . '.';
            } elseif ($type === 'debit_note') {
                $invoiceText = 'Ťarchopis k faktúre č. ' . ($invoiceData['related_invoice_number'] ?? '') . '.';
            } elseif ($type === 'transport') {
                $invoiceText = "Fakturujeme Vám za poskytnutie nákladov na dopravu pre Vašich poistencov za obdobie $periodLabel na základe Zmluvy o poskytovaní a úhrade zdravotnej starostlivosti.";
            } elseif ($type === 'procedures') {
                $invoiceText = "Fakturujeme Vám za poskytnutie ošetrovateľskej starostlivosti pre Vašich poistencov za obdobie $periodLabel na základe Zmluvy o poskytovaní a úhrade zdravotnej starostlivosti.";
            }

            $attachmentsText = '';
            if ($type === 'credit_note') {
                $attachmentsText = 'dobropis';
            } elseif ($type === 'debit_note') {
                $attachmentsText = 'ťarchopis';
            } elseif ($type === 'transport') {
                $attachmentsText = 'x dávka 793n - dopravná dávka';
            } elseif ($type === 'procedures') {
                $attachmentsText = 'x dávka 753n - výkonová dávka';
            }
        @endphp

        <!-- HEADER -->
        <div class="header-section">
            <div>
                <div class="invoice-title">
                    @if ($type === 'credit_note')
                        Dobropis
                    @elseif($type === 'debit_note')
                        Ťarchopis
                    @else
                        Faktúra
                    @endif
                </div>
                <div class="invoice-info">
                    Faktúra číslo: <strong>{{ $invoiceData['invoice_number'] ?? '' }}</strong>
                </div>
                @if ($invoiceData['related_invoice_number'] ?? null)
                    <div class="invoice-info" style="font-size: 11px;">
                        K faktúre: <strong>{{ $invoiceData['related_invoice_number'] }}</strong>
                    </div>
                @endif
            </div>

            <div class="invoice-info-right">
                <div><strong>Konštatný symbol: </strong> {{ $invoiceData['constant_symbol'] ?? '' }}</div>
                <div><strong>Spôsob úhrady:</strong> {{ $invoiceData['payment_method'] ?? '' }}</div>
                <div><strong>Dátum splatnosti:</strong> {{ formatDate($invoiceData['due_date'] ?? '') }}</div>
            </div>
        </div>

        <!-- SUPPLIER AND CUSTOMER -->
        <table>
            <tbody>
                <tr>
                    <td style="border: 1px solid #000; padding: 12px; width: 50%;">
                        <div class="section-title">Dodávateľ</div>
                        <table class="inline-table">
                            <tbody>
                                <tr>
                                    <td style="width: 50%; padding-right: 8px;">
                                        <div><strong>{{ $invoiceData['company_name'] ?? '' }}</strong></div>
                                        <div>{{ $invoiceData['company_address'] ?? '' }}</div>
                                        <div>{{ $invoiceData['company_zip'] ?? '' }}, {{ $invoiceData['company_city'] ?? '' }}</div>
                                    </td>
                                    <td style="width: 50%;">
                                        <div>IČO: {{ $invoiceData['company_ico'] ?? '' }}</div>
                                        <div>DIČ: {{ $invoiceData['company_dic'] ?? '' }}</div>
                                        <div>IČ DPH: {{ $invoiceData['company_ic_dph'] ?? '' }}</div>
                                        <div>IBAN: {{ $invoiceData['company_iban'] ?? '' }}</div>
                                        <div>BIC: {{ $invoiceData['company_bic'] ?? '' }}</div>
                                        @if ($invoiceData['company_register'] ?? null)
                                            <div style="margin-top: 8px; font-size: 10px;">{{ $invoiceData['company_register'] }}</div>
                                        @endif
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                    <td style="border: 1px solid #000; padding: 12px; width: 50%;">
                        <div class="section-title">Odberateľ</div>
                        <table class="inline-table">
                            <tbody>
                                <tr>
                                    <td style="width: 50%; padding-right: 8px;">
                                        <div><strong>{{ $invoiceData['insurance_company_name'] ?? '' }}</strong></div>
                                        <div>{{ $invoiceData['insurance_company_address'] ?? '' }}</div>
                                        <div>{{ $invoiceData['insurance_company_zip'] ?? '' }} {{ $invoiceData['insurance_company_city'] ?? '' }}</div>
                                    </td>
                                    <td style="width: 50%;">
                                        <div>IČO: {{ $invoiceData['insurance_company_ico'] ?? '' }}</div>
                                        <div>DIČ: {{ $invoiceData['insurance_company_dic'] ?? '' }}</div>
                                        <div>IČ DPH: {{ $invoiceData['insurance_company_ic_dph'] ?? '' }}</div>
                                        @if ($invoiceData['insurance_company_register'] ?? null)
                                            <div style="margin-top: 8px; font-size: 10px;">{{ $invoiceData['insurance_company_register'] }}</div>
                                        @endif
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- DATES -->
        <table class="dates-section">
            <tbody>
                <tr>
                    <td style="border: 1px solid #000; width: 33.33%;">
                        <strong>Dátum vystavenia</strong><br />
                        {{ formatDate($invoiceData['invoice_created_at'] ?? '') }}
                    </td>
                    <td style="border: 1px solid #000; width: 33.33%;">
                        <strong>Dátum odoslania</strong><br />
                        {{ formatDate($invoiceData['invoice_sent_at'] ?? '') }}
                    </td>
                    @if ($type === 'transport' || $type === 'procedures')
                        <td style="border: 1px solid #000; width: 33.33%;">
                            <strong>Dátum dodania služby</strong><br />
                            {{ formatDate($invoiceData['services_delivered_at'] ?? '') }}
                        </td>
                    @endif
                </tr>
            </tbody>
        </table>

        <!-- INVOICE TEXT -->
        <div class="invoice-text">
            {{ $invoiceText }}
        </div>

        <!-- ITEMS TABLE -->
        @if ($type === 'transport' || $type === 'procedures')
            @php
                $nonZeroDocs = array_filter($invoiceData['associated_documents'] ?? [], fn($doc) => (float) ($doc['amount'] ?? 0) > 0);
            @endphp
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: auto;"></th>
                        <th>Kód PZS</th>
                        <th>Kód zdravotníka</th>
                        <th class="text-right">Suma</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($nonZeroDocs as $index => $doc)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>{{ $doc['branch_code'] ?? '' }}</td>
                            <td>{{ $doc['user_code'] ?? '' }} {{ $doc['user_initials'] ?? '' }}</td>
                            <td class="text-right">{{ formatCurrency($doc['amount']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="total-row">
                        <td colspan="3" style="text-align: right;">K úhrade</td>
                        <td class="text-right">{{ formatCurrency($invoiceData['total'] ?? 0) }}</td>
                    </tr>
                </tbody>
            </table>

            <!-- ATTACHMENTS -->
            @if (count($nonZeroDocs) > 0)
                <div class="attachments-section">
                    <div><strong>Prílohy:</strong><br />{{ count($nonZeroDocs) }} {{ $attachmentsText }}</div>
                </div>
            @endif
        @elseif ($type === 'credit_note' || $type === 'debit_note')
            <!-- CREDIT/DEBIT NOTE AMOUNT -->
            <table class="items-table">
                <tbody>
                    <tr class="total-row">
                        <td colspan="3" style="text-align: right;">K úhrade</td>
                        <td class="text-right">{{ formatCurrency($invoiceData['total'] ?? 0) }}</td>
                    </tr>
                </tbody>
            </table>
        @endif
    </div>
</body>

</html>

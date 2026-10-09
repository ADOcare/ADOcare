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
            font-size: 10px;
            color: #000;
            background: #fff;
            box-sizing: border-box;
        }

        html{
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
            font-size: 15px;
            margin-bottom: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 6px;
        }

        td,
        th {
            border: 1px solid #000;
            padding: 4px;
            vertical-align: top;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        .header td {
            font-size: 10px;
        }

        .right {
            text-align: right;
        }

        .center {
            text-align: center;
        }

        .small {
            font-size: 9px;
        }

        .route-table td,
        .route-table th {
            border: 1px solid #aaa;
        }

        .day-block {
            margin-bottom: 8px;
            page-break-inside: avoid;
        }

        .sig {
            max-height: 36px;
            max-width: 140px;
            vertical-align: middle;
            margin-left: 8px;
        }
    </style>
</head>
<body>
    <div class="document-content">
        <div class="title">DENNÝ ZÁZNAM CIEST</div>
        @if(!empty($dzcData['calculation_note']))
            <p>{{ $dzcData['calculation_note'] }}</p>
        @endif

        @php
            $month = $dzcData['month'] ?? '';
            $year = $dzcData['year'] ?? '';
            $carConsumption = isset($dzcData['car_consumption_l_per_100km']) && is_numeric($dzcData['car_consumption_l_per_100km'])
                ? number_format((float) $dzcData['car_consumption_l_per_100km'], 1, ',', '')
                : '-';

            $dayTotals = $dzcData['day_totals'] ?? [];
            $patientAddresses = $dzcData['patient_addresses'] ?? [];

            $monthTotalKm = $dzcData['month_totals']['distance_km'] ?? null;
            if ($monthTotalKm === null) {
                $sum = 0.0;
                foreach ($patientAddresses as $addresses) {
                    foreach (($addresses ?? []) as $addr) {
                        $sum += (float) ($addr['kilometers'] ?? 0);
                    }
                }
                $monthTotalKm = round($sum, 2);
            }
        @endphp

        <table class="header">
            <tbody>
                <tr>
                    <td style="width: 34%;">
                        Obdobie:<br>
                        <strong>{{ $month }}/{{ $year }}</strong>
                    </td>
                    <td style="width: 66%;" colspan="2">
                        Pracovník:<br>
                        <strong>{{ $dzcData['user_name'] ?? '' }}</strong>
                        @if(!empty($signatureDataUri))
                            <img src="{{ $signatureDataUri }}" alt="Podpis" class="sig">
                        @endif
                    </td>
                </tr>
                <tr>
                    <td>
                        Celkový počet km:<br>
                        <strong>{{ $monthTotalKm }}</strong>
                    </td>
                    <td>
                        Dopravný prostriedok:<br>
                        <strong>{{ $dzcData['car_model'] ?? '' }} - {{ $dzcData['car_license_plate'] ?? '' }}</strong>
                    </td>
                    <td>
                        Spotreba:<br>
                        <strong>{{ $carConsumption }}</strong> L/100 km
                    </td>
                </tr>
            </tbody>
        </table>

        @foreach($patientAddresses as $date => $addresses)
            @php
                $distanceKm = $dayTotals[$date]['distance_km'] ?? null;
                if ($distanceKm === null) {
                    $sumDay = 0.0;
                    foreach (($addresses ?? []) as $a) {
                        $sumDay += (float) ($a['kilometers'] ?? 0);
                    }
                    $distanceKm = round($sumDay, 2);
                }
                $duration = $dayTotals[$date]['total_time'] ?? '-';
            @endphp

            <div class="day-block">
                <table>
                    <tbody>
                        <tr>
                            <td style="width: 25%;">
                                <strong>Dátum</strong><br>
                                {{ !empty($date) ? \Carbon\Carbon::parse($date)->format('d.m.Y') : '' }}
                            </td>
                            <td style="width: 25%;">
                                <strong>Účel cesty</strong><br>
                                {{ $dzcData['trip_purpose'] ?? 'Návšteva pacienta' }}
                            </td>
                            <td style="width: 25%;">
                                <strong>Počet km</strong><br>
                                {{ $distanceKm }}
                            </td>
                            <td style="width: 25%;">
                                <strong>Trvanie</strong><br>
                                {{ $duration }}
                            </td>
                        </tr>
                        <tr>
                            <td colspan="4">
                                <table class="route-table small">
                                    <thead>
                                        <tr>
                                            <th style="width: 18%;" class="center">Poradové číslo</th>
                                            <th style="width: 52%;">Adresa</th>
                                            <th style="width: 15%;" class="center">Príchod</th>
                                            <th style="width: 15%;" class="right">KM</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach(($addresses ?? []) as $idx => $addr)
                                            @php
                                                $arrival = $addr['arrival_time'] ?? null;
                                                $arrivalText = '-';
                                                if (!empty($arrival)) {
                                                    try {
                                                        $arrivalText = \Carbon\Carbon::parse($arrival)->format('H:i');
                                                    } catch (\Throwable $e) {
                                                        $arrivalText = '-';
                                                    }
                                                }
                                            @endphp
                                            <tr>
                                                <td class="center"><strong>{{ $idx + 1 }}.</strong></td>
                                                <td>{{ $addr['address'] ?? '' }}</td>
                                                <td class="center">{{ $arrivalText }}</td>
                                                <td class="right">{{ $addr['kilometers'] ?? '-' }} km</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>
</body>
</html>

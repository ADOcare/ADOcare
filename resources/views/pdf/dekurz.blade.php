<!doctype html>
<html lang="sk">

<head>
    <meta charset="utf-8">

    <style>
        @page {
            size: A4 portrait;
            margin: 10mm;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            width: 100%;
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            line-height: 1.25;
            color: #000;
            background: #fff;
            box-sizing: border-box;
        }

        * {
            box-sizing: border-box;
        }

        .dekurz-page {
            background: #fff;
            margin: 5mm 10mm;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .dekurz-page+.dekurz-page {
            page-break-before: always;
            break-before: page;
        }

        @media screen {
            html,
            body {
                width: 210mm;
            }

            .dekurz-page {
                width: 210mm;
                height: 297mm;
                margin: 0;
                padding: 5mm 10mm;
                overflow: hidden;
            }
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 0;
            }

            html,
            body {
                width: 210mm;
                margin: 0;
                padding: 0;
            }

            .dekurz-page {
                width: 210mm;
                height: 297mm;
                margin: 0;
                padding: 5mm 10mm;
                overflow: hidden;
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .dekurz-page + .dekurz-page {
                page-break-before: always;
                break-before: page;
            }
        }

        .page-inner {
            width: 100%;
        }

        .page-title {
            text-align: center;
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 6px;
        }

        .dekurz-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 3px;
        }

        .dekurz-table td {
            border: 1px solid #000;
            padding: 5px 6px;
            vertical-align: top;
            overflow-wrap: break-word;
            word-wrap: break-word;
            line-height: 1.25;
            font-size: 10.5px;
        }

        .text-normal {
            font-size: 10.5px;
            line-height: 1.25;
            margin: 1px 0;
        }

        .font-normal {
            font-weight: normal;
            font-size: 10.5px;
            line-height: 1.25;
        }

        .align-top {
            vertical-align: top;
        }

        .row-signature {
            display: flex;
            align-items: flex-end;
            gap: 8px;
            min-height: 28px;
            margin-top: 5px;
        }

        .signature-image {
            margin-left: auto;
            height: 28px;
            max-width: 120px;
            object-fit: contain;
            flex-shrink: 0;
        }

        .text-sm {
            font-size: 9.5px;
            line-height: 1.2;
        }

        .whitespace-pre-line {
            white-space: pre-line;
        }

        .leading-snug {
            line-height: 1.25;
        }

        .header-table {
            margin-bottom: 3px;
        }

        .company-info-table td:nth-child(1),
        .company-info-table td:nth-child(2),
        .company-info-table td:nth-child(3),
        .company-info-table td:nth-child(4) {
            width: 25%;
        }

        .entries-table-header {
            font-weight: 700;
            font-size: 9.5px;
            line-height: 1.2;
        }

        .entries-table td:nth-child(1) {
            width: 18%;
        }

        .entries-table td:nth-child(2) {
            width: 82%;
        }

        .empty-state-cell {
            text-align: center;
            padding: 10px;
        }
    </style>
</head>

<body>
    @php
        $days = $dekurzData['days'] ?? [];
        $sortedDays = collect($days)->sortBy('date')->values()->toArray();

        $estimateWrappedLines = static function (?string $text): int {
            $normalized = trim(strip_tags((string) $text));

            if ($normalized === '') {
                return 1;
            }

            $paragraphs = preg_split('/\r\n|\r|\n/u', $normalized) ?: [];
            $lines = 0;

            foreach ($paragraphs as $paragraph) {
                $line = trim((string) preg_replace('/[\t ]+/u', ' ', $paragraph));
                $lines += max(1, (int) ceil(mb_strlen($line) / 90));
            }

            return max(1, $lines);
        };

        $estimateRowLines = static function (array $row) use ($estimateWrappedLines): int {
            $descriptionLines = $estimateWrappedLines($row['text'] ?? '');

            return max(3, $descriptionLines + 3);
        };

        $splitTextForPages = static function (?string $text, int $maximumLines = 18) use ($estimateWrappedLines): array {
            $text = trim((string) $text);

            if ($text === '' || $estimateWrappedLines($text) <= $maximumLines) {
                return [$text];
            }

            $tokens = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$text];
            $chunks = [];
            $current = '';

            foreach ($tokens as $token) {
                $candidate = $current.$token;

                if ($current !== '' && $estimateWrappedLines($candidate) > $maximumLines) {
                    $chunks[] = trim($current);
                    $current = ltrim($token);
                } else {
                    $current = $candidate;
                }
            }

            if (trim($current) !== '') {
                $chunks[] = trim($current);
            }

            return $chunks ?: [''];
        };

        $formatVisitTime = static function ($value): string {
            $value = trim((string) $value);

            if ($value === '') {
                return '00:00';
            }

            try {
                return \Carbon\Carbon::parse($value)->format('H:i');
            } catch (\Throwable $e) {
                return strlen($value) >= 5 ? substr($value, -8, 5) : $value;
            }
        };

        $rowsPerPageLines = 27;
        $layoutRows = [];

        foreach ($sortedDays as $row) {
            foreach ($splitTextForPages($row['text'] ?? '') as $chunkIndex => $textChunk) {
                $layoutRow = $row;
                $layoutRow['text'] = $textChunk;
                $layoutRow['is_continuation'] = $chunkIndex > 0;
                $layoutRows[] = $layoutRow;
            }
        }

        $pages = [];
        $currentPageRows = [];
        $currentPageLines = 0;

        foreach ($layoutRows as $row) {
            $rowLines = $estimateRowLines($row);

            if (!empty($currentPageRows) && $currentPageLines + $rowLines > $rowsPerPageLines) {
                $pages[] = $currentPageRows;
                $currentPageRows = [];
                $currentPageLines = 0;
            }

            $currentPageRows[] = $row;
            $currentPageLines += $rowLines;
        }

        if (!empty($currentPageRows)) {
            $pages[] = $currentPageRows;
        }

        if (empty($pages)) {
            $pages = [[]];
        }

        $totalPages = count($pages);
    @endphp

    @foreach ($pages as $pageIdx => $pageRows)
        @php
            $dekurzNumber = (int) ($dekurzData['dekurz_number'] ?? 1) + $pageIdx;
        @endphp

        <div class="dekurz-page" data-page="{{ $pageIdx + 1 }}">
            <div class="page-inner">
                <div class="page-title">
                    DEKURZ OŠETROVATEĽSKEJ STAROSTLIVOSTI
                </div>

                <table class="dekurz-table company-info-table header-table">
                    <colgroup>
                        <col />
                        <col />
                        <col />
                        <col />
                    </colgroup>

                    <tbody>
                        <tr>
                            <td colspan="4">
                                <div class="text-normal">
                                    <strong>{{ $dekurzData['company_name'] ?? '' }}</strong>
                                </div>
                                <div class="text-normal">{{ $dekurzData['company_address'] ?? '' }}</div>
                                <div class="text-normal">Agentúra domácej ošetrovateľskej starostlivosti</div>
                            </td>
                        </tr>

                        <tr>
                            <td colspan="2" class="align-top">
                                <div class="text-normal">Meno, priezvisko, titul pacienta/pacientky:</div>
                                <div class="font-normal">
                                    <strong>{{ $dekurzData['patient_name'] ?? '' }}</strong>
                                </div>
                            </td>

                            <td class="align-top">
                                <div class="text-normal">Rodné číslo:</div>
                                <div class="font-normal">
                                    <strong>{{ $dekurzData['patient_personal_number'] ?? '—' }}</strong>
                                </div>
                            </td>

                            <td class="align-top">
                                <div class="text-normal">Poisťovňa:</div>
                                <div class="font-normal">
                                    <strong>{{ $dekurzData['insurance_code'] ?? '—' }}</strong>
                                </div>
                            </td>
                        </tr>

                        <tr>
                            <td colspan="3" class="align-top">
                                <div class="text-normal">Adresa pacienta/pacientky:</div>
                                <div class="font-normal">
                                    <strong>{{ $dekurzData['patient_address'] ?? '' }}</strong>
                                </div>
                            </td>

                            <td class="align-top">
                                <div class="text-normal">Poradové číslo dekurzu:</div>
                                <div class="font-normal">
                                    <strong data-dekurz-number>{{ $dekurzNumber }}</strong>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <table class="dekurz-table entries-table">
                    <colgroup>
                        <col />
                        <col />
                    </colgroup>

                    <tbody>
                        <tr>
                            <td class="align-top entries-table-header">
                                Dátum a<br />čas zápisu:
                            </td>

                            <td class="align-top entries-table-header">
                                Rozsah poskytnutej ZS a služieb súvisiacich s poskytnutím ZS, identifikácia ošetrujúceho zdravotného pracovníka
                            </td>
                        </tr>

                        @forelse($pageRows as $row)
                            @php
                                $dateStr = \Carbon\Carbon::parse($row['date'])->format('d.m.Y');
                                $terrainTimeStr = !empty($row['terrain_time']) ? $formatVisitTime($row['terrain_time']) : '00:00';
                                $administrativeTimeStr = !empty($row['administrative_time']) ? $formatVisitTime($row['administrative_time']) : '00:00';
                                $text = trim($row['text'] ?? '');
                            @endphp

                            <tr data-dekurz-row>
                                <td class="align-top">
                                    <div class="whitespace-pre-line text-normal">
                                        @if (!empty($row['is_continuation']))
                                            {{ $dateStr }}<br />pokračovanie
                                        @else
                                            {{ $dateStr }}<br />{{ $terrainTimeStr }}
                                        @endif
                                    </div>
                                </td>

                                <td class="align-top">
                                    <div class="leading-snug text-normal">
                                        <span class="font-normal">{{ $administrativeTimeStr }}: </span>
                                        <span class="whitespace-pre-line">{{ $text }}</span>
                                    </div>

                                    <div class="row-signature">
                                        <div class="text-sm">
                                            <strong>{{ $dekurzData['user_name'] ?? '' }}</strong>
                                        </div>

                                        @if (!empty($signatureDataUri))
                                            <img src="{{ $signatureDataUri }}" alt="Podpis" class="signature-image" />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr data-empty-state>
                                <td colspan="2" class="empty-state-cell">Žiadne záznamy.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

    <script>
        (() => {
            const initialPages = Array.from(document.querySelectorAll('.dekurz-page[data-page]'));
            const rows = initialPages.flatMap((page) => Array.from(page.querySelectorAll('[data-dekurz-row]')));
            const firstPage = initialPages[0];

            if (!firstPage || rows.length === 0) {
                return;
            }

            const baseNumber = Number(firstPage.querySelector('[data-dekurz-number]')?.textContent ?? 1);
            const pageTemplate = firstPage.cloneNode(true);

            pageTemplate.querySelectorAll('[data-dekurz-row], [data-empty-state]').forEach((element) => element.remove());
            initialPages.forEach((page) => page.remove());

            let pageIndex = 0;
            let currentPage;
            let currentBody;

            const appendPage = () => {
                const page = pageTemplate.cloneNode(true);
                const number = page.querySelector('[data-dekurz-number]');

                page.dataset.page = String(pageIndex + 1);
                page.style.display = '';

                if (number) {
                    number.textContent = String(baseNumber + pageIndex);
                }

                document.body.appendChild(page);
                pageIndex++;
                currentPage = page;
                currentBody = page.querySelector('.entries-table tbody');
            };

            const pageOverflows = () => {
                const table = currentPage?.querySelector('.entries-table');

                if (!currentPage || !table) {
                    return false;
                }

                const pageStyle = getComputedStyle(currentPage);
                const contentBottom = currentPage.getBoundingClientRect().bottom -
                    Number.parseFloat(pageStyle.paddingBottom || '0');

                return table.getBoundingClientRect().bottom > contentBottom - 2;
            };

            appendPage();

            rows.forEach((row) => {
                currentBody.appendChild(row);

                if (pageOverflows() && currentBody.querySelectorAll('[data-dekurz-row]').length > 1) {
                    row.remove();
                    appendPage();
                    currentBody.appendChild(row);
                }
            });

            document.documentElement.dataset.pageLayoutReady = 'true';
        })();
    </script>
</body>

</html>
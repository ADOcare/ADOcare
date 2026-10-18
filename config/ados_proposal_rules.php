<?php

return [
    'version' => '1.0',
    'care_type' => 'ADOS',

    'procedures' => [
        '3390' => [
            'episode' => ['allowed_when' => ['new', 'restart'], 'restart_after_days' => 90],
            'forbidden_with' => ['3394'],
            'isolated_care_warning' => true,
        ],
        '3392A' => [
            'episode' => ['allowed_when' => ['new', 'restart', 'significant_change']],
        ],
        '3393' => [
            'episode' => ['allowed_when' => ['new', 'restart', 'significant_change']],
            'forbidden_with' => ['3394', '3395'],
            'isolated_care_warning' => true,
        ],
        '3394' => [
            'episode' => ['allowed_when' => ['active'], 'minimum_age_days' => 30],
            'frequency' => [['max' => 1, 'period' => 'month']],
            'forbidden_with' => ['3390', '3393', '3395'],
            'isolated_care_warning' => true,
        ],
        '3395' => [
            'episode' => ['allowed_when' => ['ending']],
            'max_per_episode' => 1,
            'forbidden_with' => ['3393', '3394'],
        ],
        '3400' => [
            'frequency' => [['max' => 6, 'period' => 'day']],
            'forbidden_with' => ['3424'],
        ],
        '3410' => [
            'frequency' => [['max' => 1, 'period' => 'day']],
        ],
        '3413' => [
            'frequency' => [['max' => 5, 'period' => 'day']],
            'forbidden_with' => ['3422B', '3422C'],
        ],
        '3414' => [
            'frequency' => [['max' => 1, 'period' => 'day']],
        ],
        '3416' => [
            'frequency' => [['max' => 4, 'period' => 'day']],
            'patient' => ['mobility' => ['limited', 'immobile']],
            'documentation_hint' => 'Pri výkone má byť v návrhu alebo súvisiacej lekárskej dokumentácii uvedené liečivo, dávka a frekvencia podania.',
        ],
        '3418' => [
            'frequency' => [['max' => 2, 'period' => 'day']],
            'patient' => ['mobility' => ['limited', 'immobile']],
            'forbidden_with' => ['3419'],
            'documentation_hint' => 'Pri výkone má byť v návrhu alebo súvisiacej lekárskej dokumentácii uvedené liečivo, dávka a frekvencia podania.',
        ],
        '3419' => [
            'frequency' => [['max' => 2, 'period' => 'day']],
            'forbidden_with' => ['3418'],
            'max_duration_months' => 3,
            'documentation_hint' => 'Pri infúzii má byť v návrhu alebo súvisiacej lekárskej dokumentácii uvedený roztok, koncentrácia, množstvo, frekvencia a dĺžka podávania.',
        ],
        '3422A' => [
            'frequency' => [['max' => 2, 'period' => 'day']],
            'patient' => ['mobility' => ['immobile']],
            'forbidden_with' => ['3424'],
            'documentation_hint' => 'Pri prevencii dekubitov má byť v dokumentácii plán starostlivosti a relevantné hodnotenie rizika dekubitov.',
        ],
        '3422B' => [
            'frequency' => [['max' => 5, 'period' => 'day']],
            'forbidden_with' => ['3413'],
            'documentation_hint' => 'Dokumentácia má obsahovať lokalizáciu, veľkosť, fázu hojenia, spodinu, exsudát, okolie a lokálnu liečbu.',
        ],
        '3422C' => [
            'frequency' => [['max' => 5, 'period' => 'day']],
            'forbidden_with' => ['3413'],
            'documentation_hint' => 'Dokumentácia má obsahovať lokalizáciu, veľkosť, hĺbku, fázu hojenia, spodinu, exsudát, okolie a lokálnu liečbu.',
        ],
        '3423' => [
            'frequency' => [['max' => 1, 'period' => 'visit']],
        ],
        '3423A' => [
            'frequency' => [['max' => 5, 'period' => 'day']],
            'documentation_hint' => 'Dokumentácia má obsahovať lokalizáciu, veľkosť, fázu hojenia, spodinu, exsudát, okolie a lokálnu liečbu.',
        ],
        '3423B' => [
            'frequency' => [['max' => 5, 'period' => 'day']],
            'documentation_hint' => 'Dokumentácia má obsahovať lokalizáciu, veľkosť, hĺbku, fázu hojenia, spodinu, exsudát, okolie a lokálnu liečbu.',
        ],
        '3424' => [
            'frequency' => [['max' => 2, 'period' => 'day']],
            'patient' => ['mobility' => ['limited', 'immobile']],
            'max_duration_months' => 3,
            'forbidden_with' => ['3400', '3422A'],
            'documentation_hint' => 'Pri ošetrovateľskej rehabilitácii má byť v dokumentácii uvedený plán rehabilitácie.',
        ],
        '3433' => [
            'frequency' => [['max' => 1, 'period' => 'day']],
            'patient' => ['mobility' => ['limited', 'immobile']],
        ],
        '3436' => [
            'frequency' => [['max' => 4, 'period' => 'day']],
            'patient' => ['mobility' => ['limited', 'immobile']],
        ],
        '3437' => [
            'frequency' => [['max' => 1, 'period' => 'visit']],
        ],
        '3439' => [
            'forbidden_with' => ['3440'],
        ],
        '3440' => [
            'schedule' => [
                'outside_regular_working_hours' => true,
                'weekday_time_from' => '19:00',
                'weekday_time_to' => '07:00',
                'allowed_days' => ['saturday', 'sunday', 'holiday'],
            ],
            'forbidden_with' => ['3439'],
            'documentation_hint' => 'Výkon 3440 je určený na návštevu mimo riadneho pracovného času. Dôvod takejto starostlivosti má byť uvedený v návrhu alebo súvisiacej dokumentácii.',
        ],
    ],
];

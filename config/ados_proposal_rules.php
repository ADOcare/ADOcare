<?php

return [
    'version' => '1.1',

    'care_type' => 'ADOS',

    'procedures' => [
        '3390' => [
            'proposal' => [
                'isolated_care_warning' => true,
            ],

            'episode' => [
                'role' => 'start',
                'allowed_when' => [
                    'new',
                    'restart',
                ],
                'restart_after_days' => 90,
            ],

            'scoring' => [
                'forbidden_with' => [
                    '3394',
                ],
            ],
        ],

        '3392A' => [
            'episode' => [
                'role' => 'education',
                'allowed_when' => [
                    'new',
                    'restart',
                    'significant_change',
                ],
            ],
        ],

        '3393' => [
            'proposal' => [
                'isolated_care_warning' => true,
            ],

            'episode' => [
                'role' => 'plan',
                'allowed_when' => [
                    'new',
                    'restart',
                    'significant_change',
                ],
            ],

            'scoring' => [
                'forbidden_with' => [
                    '3394',
                    '3395',
                ],
            ],
        ],

        '3394' => [
            'proposal' => [
                'frequency' => [
                    [
                        'max' => 1,
                        'period' => 'month',
                    ],
                ],
                'isolated_care_warning' => true,
            ],

            'episode' => [
                'role' => 'ongoing',
                'allowed_when' => [
                    'active',
                ],
                'minimum_age_days' => 30,
            ],

            'scoring' => [
                'forbidden_with' => [
                    '3390',
                    '3393',
                    '3395',
                ],
            ],
        ],

        '3395' => [
            'episode' => [
                'role' => 'end',
                'allowed_when' => [
                    'ending',
                ],
                'max_per_episode' => 1,
            ],

            'scoring' => [
                'forbidden_with' => [
                    '3393',
                    '3394',
                ],
            ],
        ],

        '3400' => [
            'proposal' => [
                'frequency' => [
                    [
                        'max' => 6,
                        'period' => 'day',
                    ],
                ],
            ],

            'scoring' => [
                'forbidden_with' => [
                    '3424',
                ],
            ],
        ],

        '3410' => [
            'proposal' => [
                'frequency' => [
                    [
                        'max' => 1,
                        'period' => 'day',
                    ],
                ],
            ],
        ],

        '3413' => [
            'proposal' => [
                'frequency' => [
                    [
                        'max' => 5,
                        'period' => 'day',
                    ],
                ],
            ],

            'scoring' => [
                'forbidden_with' => [
                    '3422B',
                    '3422C',
                ],
            ],
        ],

        '3414' => [
            'proposal' => [
                'frequency' => [
                    [
                        'max' => 1,
                        'period' => 'day',
                    ],
                ],
            ],
        ],

        '3416' => [
            'proposal' => [
                'frequency' => [
                    [
                        'max' => 4,
                        'period' => 'day',
                    ],
                ],
                'patient' => [
                    'mobility' => [
                        'limited',
                        'immobile',
                    ],
                ],
                'documentation_hint' => 'Pri výkone má byť v návrhu alebo súvisiacej lekárskej dokumentácii uvedené liečivo, dávka a frekvencia podania.',
            ],
        ],

        '3418' => [
            'proposal' => [
                'frequency' => [
                    [
                        'max' => 2,
                        'period' => 'day',
                    ],
                ],
                'patient' => [
                    'mobility' => [
                        'limited',
                        'immobile',
                    ],
                ],
                'documentation_hint' => 'Pri výkone má byť v návrhu alebo súvisiacej lekárskej dokumentácii uvedené liečivo, dávka a frekvencia podania.',
            ],

            'scoring' => [
                'forbidden_with' => [
                    '3419',
                ],
            ],
        ],

        '3419' => [
            'proposal' => [
                'frequency' => [
                    [
                        'max' => 2,
                        'period' => 'day',
                    ],
                ],
                'max_duration_months' => 3,
                'documentation_hint' => 'Pri infúzii má byť v návrhu alebo súvisiacej lekárskej dokumentácii uvedený roztok, koncentrácia, množstvo, frekvencia a dĺžka podávania.',
            ],

            'scoring' => [
                'forbidden_with' => [
                    '3418',
                ],
            ],
        ],

        '3422A' => [
            'proposal' => [
                'frequency' => [
                    [
                        'max' => 2,
                        'period' => 'day',
                    ],
                ],
                'patient' => [
                    'mobility' => [
                        'immobile',
                    ],
                ],
                'documentation_hint' => 'Pri prevencii dekubitov má byť v dokumentácii plán starostlivosti a relevantné hodnotenie rizika dekubitov.',
            ],

            'scoring' => [
                'forbidden_with' => [
                    '3424',
                ],
            ],
        ],

        '3422B' => [
            'proposal' => [
                'frequency' => [
                    [
                        'max' => 5,
                        'period' => 'day',
                    ],
                ],
                'documentation_hint' => 'Dokumentácia má obsahovať lokalizáciu, veľkosť, fázu hojenia, spodinu, exsudát, okolie a lokálnu liečbu.',
            ],

            'scoring' => [
                'forbidden_with' => [
                    '3413',
                ],
            ],
        ],

        '3422C' => [
            'proposal' => [
                'frequency' => [
                    [
                        'max' => 5,
                        'period' => 'day',
                    ],
                ],
                'documentation_hint' => 'Dokumentácia má obsahovať lokalizáciu, veľkosť, hĺbku, fázu hojenia, spodinu, exsudát, okolie a lokálnu liečbu.',
            ],

            'scoring' => [
                'forbidden_with' => [
                    '3413',
                ],
            ],
        ],

        '3423' => [
            'proposal' => [
                'frequency' => [
                    [
                        'max' => 1,
                        'period' => 'visit',
                    ],
                ],
            ],
        ],

        '3423A' => [
            'proposal' => [
                'frequency' => [
                    [
                        'max' => 5,
                        'period' => 'day',
                    ],
                ],
                'documentation_hint' => 'Dokumentácia má obsahovať lokalizáciu, veľkosť, fázu hojenia, spodinu, exsudát, okolie a lokálnu liečbu.',
            ],
        ],

        '3423B' => [
            'proposal' => [
                'frequency' => [
                    [
                        'max' => 5,
                        'period' => 'day',
                    ],
                ],
                'documentation_hint' => 'Dokumentácia má obsahovať lokalizáciu, veľkosť, hĺbku, fázu hojenia, spodinu, exsudát, okolie a lokálnu liečbu.',
            ],
        ],

        '3424' => [
            'proposal' => [
                'frequency' => [
                    [
                        'max' => 2,
                        'period' => 'day',
                    ],
                ],
                'patient' => [
                    'mobility' => [
                        'limited',
                        'immobile',
                    ],
                ],
                'max_duration_months' => 3,
                'documentation_hint' => 'Pri ošetrovateľskej rehabilitácii má byť v dokumentácii uvedený plán rehabilitácie.',
            ],

            'scoring' => [
                'forbidden_with' => [
                    '3400',
                    '3422A',
                ],
            ],
        ],

        '3433' => [
            'proposal' => [
                'frequency' => [
                    [
                        'max' => 1,
                        'period' => 'day',
                    ],
                ],
                'patient' => [
                    'mobility' => [
                        'limited',
                        'immobile',
                    ],
                ],
            ],
        ],

        '3436' => [
            'proposal' => [
                'frequency' => [
                    [
                        'max' => 4,
                        'period' => 'day',
                    ],
                ],
                'patient' => [
                    'mobility' => [
                        'limited',
                        'immobile',
                    ],
                ],
            ],
        ],

        '3437' => [
            'proposal' => [
                'frequency' => [
                    [
                        'max' => 1,
                        'period' => 'visit',
                    ],
                ],
            ],
        ],

        '3439' => [
            'scoring' => [
                'forbidden_with' => [
                    '3440',
                ],
            ],
        ],

        '3440' => [
            'proposal' => [
                'documentation_hint' => 'Výkon 3440 je určený na návštevu mimo riadneho pracovného času. Dôvod takejto starostlivosti má byť uvedený v návrhu alebo súvisiacej dokumentácii.',
            ],

            'scoring' => [
                'forbidden_with' => [
                    '3439',
                ],
                'schedule' => [
                    'outside_regular_working_hours' => true,
                    'weekday_time_from' => '19:00',
                    'weekday_time_to' => '07:00',
                    'allowed_days' => [
                        'saturday',
                        'sunday',
                        'holiday',
                    ],
                ],
            ],
        ],
    ],
];

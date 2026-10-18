<?php

return [
    'definitions' => [
        'daily' => [
            'type' => 'daily',
            'times_per_day' => 1,
        ],
        'twice_daily' => [
            'type' => 'daily',
            'times_per_day' => 2,
        ],
        'three_times_daily' => [
            'type' => 'daily',
            'times_per_day' => 3,
        ],
        'weekdays' => [
            'type' => 'weekdays',
            'times_per_day' => 1,
        ],
        'every_other_day' => [
            'type' => 'interval_days',
            'every_days' => 2,
            'times_per_day' => 1,
        ],
        'three_times_weekly' => [
            'type' => 'weekly',
            'times_per_week' => 3,
        ],
        'twice_weekly' => [
            'type' => 'weekly',
            'times_per_week' => 2,
        ],
        'once_weekly' => [
            'type' => 'weekly',
            'times_per_week' => 1,
        ],
        'weekends' => [
            'type' => 'weekends_and_holidays',
            'times_per_day' => 1,
        ],
        'twice_monthly' => [
            'type' => 'monthly',
            'times_per_month' => 2,
        ],
        'once_monthly' => [
            'type' => 'monthly',
            'times_per_month' => 1,
        ],
        'once' => [
            'type' => 'once',
        ],
        'as_needed' => [
            'type' => 'as_needed',
        ],
    ],

    'aliases' => [
        '1x_daily' => 'daily',
        '2x_daily' => 'twice_daily',
        '3x_daily' => 'three_times_daily',
    ],

    'legacy_definitions' => [
        '4x_daily' => [
            'type' => 'daily',
            'times_per_day' => 4,
        ],
        '5x_daily' => [
            'type' => 'daily',
            'times_per_day' => 5,
        ],
        '6x_daily' => [
            'type' => 'daily',
            'times_per_day' => 6,
        ],
    ],
];

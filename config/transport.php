<?php

return [
    // Existing route service URL/authentication remains in config/services.php.
    'route_version' => 2,
    'contracts' => [
        // Override by company ID, then insurance code (24, 25, 27).
        // Rates are read from the existing procedure_company_prices, procedure 0000.
        // Example: 1 => ['25' => ['enabled' => true, 'max_leg_km' => 60]],
    ],
    'default_contract' => [
        'enabled' => true,
        'max_leg_km' => null,
        'rounding' => 'nearest', // nearest | ceil | floor; output 793n requires whole km
    ],
];

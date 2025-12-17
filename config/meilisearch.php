<?php

return [
    'enabled' => env('MEILI_ENABLED', false),

    'host' => env('MEILI_HOST', ''),
    'key'  => env('MEILI_MASTER_KEY', ''),

    'indexes' => [
        'products' => env('MEILI_INDEX_PRODUCTS', 'products'),
    ],
];

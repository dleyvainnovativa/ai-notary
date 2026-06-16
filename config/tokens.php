<?php

return [
    // key => [tokens, price in cents (USD), label]
    'packages' => [
        'starter' => ['tokens' => 10,  'price' => 1000,  'label' => '10 documentos'],
        'pro'     => ['tokens' => 50,  'price' => 4500,  'label' => '50 documentos'],
        'bulk'    => ['tokens' => 100, 'price' => 8000,  'label' => '100 documentos'],
    ],

    'reservation_ttl_minutes' => 30, // a hold older than this is reclaimable
    'currency' => 'usd',
];

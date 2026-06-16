<?php

return [
    'openai' => [
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        // USD per 1M tokens — set to current rates for your chosen model
        'pricing' => [
            'gpt-4.1-mini' => ['in' => 0.40, 'out' => 1.60],  // verify current rates
            'gpt-4o-mini'  => ['in' => 0.15, 'out' => 0.60],
        ],
    ],
];

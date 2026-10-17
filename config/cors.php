<?php

return [

    'paths' => [

        'api/*',

        'sanctum/csrf-cookie',

    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [

        'https://website.throbtech.com',
        'https://generic-turbofan-rundown.ngrok-free.dev',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:8080',
        'http://127.0.0.1:8080',
        'http://localhost:8000',
        'http://127.0.0.1:8000',
        'https://talkinexx.com',
        'https://crm.talkinexx.com',
        'https://website.talkinexx.com'
        'https://throbtech.com',
        'https://www.throbtech.com',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
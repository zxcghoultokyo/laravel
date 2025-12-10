<?php

return [

    'mailgun' => [
        'domain'   => env('MAILGUN_DOMAIN'),
        'secret'   => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme'   => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // 👇 Ми вже додавали це раніше
    'horoshop' => [
        'domain'   => env('HOROSHOP_DOMAIN'),
        'login'    => env('HOROSHOP_API_LOGIN'),
        'password' => env('HOROSHOP_API_PASSWORD'),
    ],

    // 👇 Додаємо налаштування OpenAI
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model'   => env('OPENAI_MODEL', 'gpt-5.1'),
        'base_url'=> env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],

];

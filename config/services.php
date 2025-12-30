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
        'key'      => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        
        // Різні моделі для різних задач
        'model'         => env('OPENAI_MODEL', 'gpt-4.1'),           // Основна модель (чат, класифікація)
        'model_chat'    => env('OPENAI_MODEL_CHAT', 'gpt-5.1'),      // Для чату з користувачами (якість)
        'model_analyze' => env('OPENAI_MODEL_ANALYZE', 'gpt-4.1-mini'), // Для аналізу товарів (економія)
        'model_rerank'  => env('OPENAI_MODEL_RERANK', 'gpt-4.1-mini'),  // Для ререйнку результатів
        
        // Timeouts (seconds)
        'timeout_fast'   => (int) env('OPENAI_TIMEOUT_FAST', 5),     // Для classify, normalize
        'timeout_normal' => (int) env('OPENAI_TIMEOUT_NORMAL', 15),  // Для звичайних запитів
        'timeout_long'   => (int) env('OPENAI_TIMEOUT_LONG', 30),    // Для складних запитів
        
        // Rate limiting
        'rate_limit_per_minute' => (int) env('OPENAI_RATE_LIMIT', 60),
    ],

    // Admin panel
    'admin' => [
        'api_token' => env('ADMIN_API_TOKEN'),
    ],

];

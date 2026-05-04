<?php

return [

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // 👇 Ми вже додавали це раніше
    'horoshop' => [
        'domain' => env('HOROSHOP_DOMAIN'),
        'login' => env('HOROSHOP_API_LOGIN'),
        'password' => env('HOROSHOP_API_PASSWORD'),
    ],

    // 👇 Додаємо налаштування OpenAI
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),

        // Різні моделі для різних задач
        // gpt-4o - найкращий баланс ціна/якість/швидкість, високі rate limits (10K+ RPM)
        // gpt-4o-mini - найдешевший, найвищі ліміти (30K+ RPM), для масових операцій
        'model' => env('OPENAI_MODEL', 'gpt-4o'),             // Основна модель для чату
        'model_chat' => env('OPENAI_MODEL_CHAT', 'gpt-4o'),        // Для чату з користувачами
        'model_analyze' => env('OPENAI_MODEL_ANALYZE', 'gpt-4o-mini'), // Для аналізу товарів
        'model_rerank' => env('OPENAI_MODEL_RERANK', 'gpt-4o-mini'),  // Для ререйнку результатів

        // New function calling agent (enabled by default for testing)
        'use_function_calling' => env('OPENAI_USE_FUNCTION_CALLING', true),

        // Timeouts (seconds)
        'timeout_fast' => (int) env('OPENAI_TIMEOUT_FAST', 5),     // Для classify, normalize
        'timeout_normal' => (int) env('OPENAI_TIMEOUT_NORMAL', 15),  // Для звичайних запитів
        'timeout_long' => (int) env('OPENAI_TIMEOUT_LONG', 30),    // Для складних запитів

        // Rate limiting
        'rate_limit_per_minute' => (int) env('OPENAI_RATE_LIMIT', 60),

        // Modular prompt system (reduces prompt from ~14K to ~3K tokens)
        // Set to false to use legacy full prompt
        'modular_prompt' => env('OPENAI_MODULAR_PROMPT', true),
    ],

    // Admin panel
    'admin' => [
        'api_token' => env('ADMIN_API_TOKEN'),
    ],

    // Rozetka Seller API
    'rozetka' => [
        'username' => env('ROZETKA_USERNAME'),
        'password' => env('ROZETKA_PASSWORD'),
    ],

    // Contractor panel auth
    'contractor' => [
        'username' => env('CONTRACTOR_USERNAME', 'contractor'),
        'password_hash' => env('CONTRACTOR_PASSWORD_HASH'),
    ],

    // Diagnostic API. SECRET MUST be provided via DIAGNOSTIC_SECRET_KEY env var.
    // If unset, DiagnosticGuard fails closed (503).
    'diagnostic' => [
        'secret_key' => env('DIAGNOSTIC_SECRET_KEY'),
        'allowed_ips' => env('DIAGNOSTIC_ALLOWED_IPS', ''),
        'rate_limit_per_minute' => (int) env('DIAGNOSTIC_RATE_LIMIT', 30),
    ],

    // Escalation settings
    'escalation' => [
        'notify_email' => env('ESCALATION_NOTIFY_EMAIL'),
        'enabled' => env('ESCALATION_ENABLED', true),
    ],

];

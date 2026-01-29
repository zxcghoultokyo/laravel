<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Billing Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default billing driver that will be used
    | to process payments and subscriptions. WayForPay is recommended
    | for Ukrainian market as it has built-in subscription support.
    |
    */
    'default' => env('BILLING_DRIVER', 'wayforpay'),

    /*
    |--------------------------------------------------------------------------
    | Billing Currency
    |--------------------------------------------------------------------------
    |
    | The default currency for all billing operations.
    |
    */
    'currency' => env('BILLING_CURRENCY', 'UAH'),

    /*
    |--------------------------------------------------------------------------
    | Billing Drivers
    |--------------------------------------------------------------------------
    |
    | Configuration for each billing driver. Add your credentials from
    | the payment provider's dashboard.
    |
    */
    'drivers' => [
        'wayforpay' => [
            'merchant_account' => env('WAYFORPAY_MERCHANT_ACCOUNT'),
            'merchant_secret' => env('WAYFORPAY_MERCHANT_SECRET'),
            'merchant_domain' => env('WAYFORPAY_MERCHANT_DOMAIN', env('APP_URL')),
        ],

        'liqpay' => [
            'public_key' => env('LIQPAY_PUBLIC_KEY'),
            'private_key' => env('LIQPAY_PRIVATE_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription Plans
    |--------------------------------------------------------------------------
    |
    | Define your subscription plans here. Prices are in UAH (not kopecks).
    | Each plan includes limits that will be enforced by the tenant middleware.
    |
    */
    'plans' => [
        'starter' => [
            'name' => 'Starter',
            'description' => 'Для малих магазинів',
            'price' => 799,
            'interval' => 'month',
            'limits' => [
                'messages_per_month' => 1000,
                'products_limit' => 500,
                'features' => [
                    'chat_widget',
                    'widget_customization',
                    'custom_greetings',
                    'conversions', // always available
                    // NO: custom_prompts, proactive_triggers, advanced_analytics
                ],
            ],
            'wayforpay_product_id' => 'ailure_starter_monthly',
            'liqpay_product_id' => 'ailure_starter',
        ],

        'pro' => [
            'name' => 'Pro',
            'description' => 'Для середніх магазинів',
            'price' => 1999,
            'interval' => 'month',
            'limits' => [
                'messages_per_month' => 5000,
                'products_limit' => 5000,
                'features' => [
                    'chat_widget',
                    'widget_customization',
                    'custom_greetings',
                    'custom_prompts',
                    'proactive_triggers',
                    'advanced_analytics',
                    'conversions',
                    'priority_support',
                ],
            ],
            'wayforpay_product_id' => 'ailure_pro_monthly',
            'liqpay_product_id' => 'ailure_pro',
        ],

        'enterprise' => [
            'name' => 'Enterprise',
            'description' => 'Для великих магазинів',
            'price' => 4999,
            'interval' => 'month',
            'limits' => [
                'messages_per_month' => 50000,
                'products_limit' => null, // unlimited
                'features' => [
                    'chat_widget',
                    'widget_customization',
                    'custom_greetings',
                    'custom_prompts',
                    'proactive_triggers',
                    'advanced_analytics',
                    'conversions',
                    'priority_support',
                    'api_access',
                    'white_label',
                    'dedicated_support',
                ],
            ],
            'wayforpay_product_id' => 'ailure_enterprise_monthly',
            'liqpay_product_id' => 'ailure_enterprise',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trial Settings
    |--------------------------------------------------------------------------
    |
    | Configure trial period for new signups.
    |
    */
    'trial' => [
        'enabled' => env('BILLING_TRIAL_ENABLED', true),
        'days' => env('BILLING_TRIAL_DAYS', 14),
        // Trial gets Pro limits to hook users on Pro experience!
        'limits' => [
            'messages_per_month' => 5000,
            'products_limit' => 5000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Settings
    |--------------------------------------------------------------------------
    |
    | Webhook URLs for each provider (auto-generated from APP_URL).
    |
    */
    'webhooks' => [
        'wayforpay' => '/api/billing/webhook/wayforpay',
        'liqpay' => '/api/billing/webhook/liqpay',
    ],

    /*
    |--------------------------------------------------------------------------
    | Success/Cancel URLs
    |--------------------------------------------------------------------------
    |
    | Where to redirect users after payment.
    |
    */
    'urls' => [
        'success' => '/billing/success',
        'cancel' => '/billing/cancel',
    ],

    /*
    |--------------------------------------------------------------------------
    | Fiscal Receipts (ПРРО / Checkbox)
    |--------------------------------------------------------------------------
    |
    | Settings for Ukrainian fiscal receipts. Required for ФОП 3 група
    | when accepting card payments online.
    |
    */
    'fiscal' => [
        'enabled' => env('FISCAL_ENABLED', false),
        'provider' => env('FISCAL_PROVIDER', 'checkbox'), // 'checkbox' or 'vchasno'
        'checkbox' => [
            'license_key' => env('CHECKBOX_LICENSE_KEY'),
            'cashier_pin' => env('CHECKBOX_CASHIER_PIN'),
        ],
    ],
];

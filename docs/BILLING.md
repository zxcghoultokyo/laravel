# Billing System Documentation

## Overview

AIntento використовує власну білінгову систему з підтримкою WayForPay та LiqPay для українського ринку.

## Тарифні плани

| План | Ціна | Повідомлень/міс | Товарів | Фічі |
|------|------|-----------------|---------|------|
| **Starter** | 799 ₴/міс | 1,000 | до 500 | Базова аналітика, email підтримка |
| **Pro** | 1,999 ₴/міс | 5,000 | до 10,000 | Розширена аналітика, proactive triggers, пріоритетна підтримка |
| **Enterprise** | Custom | Необмежено | Необмежено | Кастомні інтеграції, SLA, виділений менеджер |

## Trial Period

- **14 днів** безкоштовного тестування
- Повний функціонал Pro плану
- Без прив'язки картки
- Після закінчення — віджет блокується

## Configuration

```php
// config/billing.php

return [
    'plans' => [
        'starter' => [
            'name' => 'Starter',
            'price' => 799,
            'currency' => 'UAH',
            'messages_limit' => 1000,
            'products_limit' => 500,
        ],
        'pro' => [
            'name' => 'Pro',
            'price' => 1999,
            'currency' => 'UAH',
            'messages_limit' => 5000,
            'products_limit' => 10000,
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'price' => null, // Custom pricing
            'currency' => 'UAH',
            'messages_limit' => null, // Unlimited
            'products_limit' => null,
        ],
    ],
    
    'trial_days' => 14,
    
    'payment' => [
        'default' => env('PAYMENT_DRIVER', 'wayforpay'),
        
        'wayforpay' => [
            'merchant_account' => env('WAYFORPAY_MERCHANT_ACCOUNT'),
            'merchant_secret' => env('WAYFORPAY_MERCHANT_SECRET'),
            'webhook_url' => env('WAYFORPAY_WEBHOOK_URL'),
        ],
        
        'liqpay' => [
            'public_key' => env('LIQPAY_PUBLIC_KEY'),
            'private_key' => env('LIQPAY_PRIVATE_KEY'),
        ],
    ],
];
```

## Key Files

| File | Purpose |
|------|---------|
| `config/billing.php` | Plans and pricing configuration |
| `app/Services/Billing/BillingManager.php` | Main billing orchestrator |
| `app/Services/Billing/Drivers/WayForPayDriver.php` | WayForPay integration |
| `app/Services/Billing/Drivers/LiqPayDriver.php` | LiqPay integration |
| `app/Http/Controllers/BillingController.php` | Billing UI controller |
| `app/Http/Controllers/Api/BillingWebhookController.php` | Payment webhooks |

## Tenant Subscription Model

```php
// app/Models/Tenant.php

// Fields:
- plan: string (starter|pro|enterprise|null)
- plan_expires_at: timestamp
- trial_ends_at: timestamp

// Methods:
isOnTrial()           // Has trial_ends_at in future
isTrialExpired()      // Trial ended, no active subscription
hasActiveSubscription() // plan + plan_expires_at valid
canUseWidget()        // Trial OR active subscription
```

## Payment Flow

### 1. Checkout Page
```
GET /billing/checkout/{plan}
```
User selects plan → redirected to payment provider

### 2. Payment Processing
```
POST /billing/subscribe/{plan}
```
Creates payment order with WayForPay/LiqPay

### 3. Webhook Callback
```
POST /api/billing/webhook/wayforpay
POST /api/billing/webhook/liqpay
```
Receives payment confirmation, updates tenant subscription

### 4. Success/Failure
```
GET /billing/success
GET /billing/cancel
```
User redirected after payment

## API Endpoints

```php
// routes/web.php (Billing)

Route::middleware(['auth'])->prefix('billing')->name('billing.')->group(function () {
    Route::get('/', [BillingController::class, 'index'])->name('index');
    Route::get('/checkout/{plan}', [BillingController::class, 'checkout'])->name('checkout');
    Route::post('/subscribe/{plan}', [BillingController::class, 'subscribe'])->name('subscribe');
    Route::post('/cancel', [BillingController::class, 'cancel'])->name('cancel');
    Route::get('/history', [BillingController::class, 'history'])->name('history');
    Route::get('/invoice/{payment}', [BillingController::class, 'invoice'])->name('invoice');
});

// routes/api.php (Webhooks)

Route::post('/billing/webhook/wayforpay', [BillingWebhookController::class, 'wayforpay']);
Route::post('/billing/webhook/liqpay', [BillingWebhookController::class, 'liqpay']);
```

## WayForPay Integration

### Setup
1. Register at https://wayforpay.com
2. Get Merchant Account and Secret Key
3. Set environment variables:
```bash
WAYFORPAY_MERCHANT_ACCOUNT=your_merchant
WAYFORPAY_MERCHANT_SECRET=your_secret
WAYFORPAY_WEBHOOK_URL=https://aintento.laravel.cloud/api/billing/webhook/wayforpay
```

### Webhook Signature Verification
```php
// app/Services/Billing/Drivers/WayForPayDriver.php

public function verifyWebhook(array $data): bool
{
    $signature = hash_hmac(
        'md5',
        implode(';', [
            $data['merchantAccount'],
            $data['orderReference'],
            $data['amount'],
            $data['currency'],
            $data['authCode'],
            $data['cardPan'],
            $data['transactionStatus'],
            $data['reasonCode'],
        ]),
        $this->config['merchant_secret']
    );
    
    return $signature === $data['merchantSignature'];
}
```

## LiqPay Integration

### Setup
1. Register at https://liqpay.ua
2. Get Public and Private keys
3. Set environment variables:
```bash
LIQPAY_PUBLIC_KEY=your_public_key
LIQPAY_PRIVATE_KEY=your_private_key
```

## Partner/Referral Program

Partners earn **15% of referred customer payments forever**.

### How it works:
1. Partner gets unique referral code
2. Customer registers using referral link
3. On every payment, 15% goes to partner
4. Monthly payouts via bank transfer

### Technical Implementation (planned):
```php
// Database
- referral_codes: id, user_id, code, created_at
- referral_signups: id, referral_code_id, tenant_id, created_at
- referral_payouts: id, user_id, amount, status, paid_at

// Tracking
- UTM parameter: ?ref=CODE
- Stored in tenant record
- Calculated on payment webhook
```

## Testing

```bash
# Test subscription flow
php artisan tinker
>>> $tenant = Tenant::find(1);
>>> $tenant->plan = 'pro';
>>> $tenant->plan_expires_at = now()->addMonth();
>>> $tenant->save();
>>> $tenant->canUseWidget(); // true

# Test trial
>>> $tenant->trial_ends_at = now()->addDays(7);
>>> $tenant->plan = null;
>>> $tenant->save();
>>> $tenant->isOnTrial(); // true
>>> $tenant->canUseWidget(); // true
```

## Roadmap

- [ ] Automatic recurring billing
- [ ] Proration for plan changes
- [ ] Usage-based billing (overage charges)
- [ ] Invoicing and receipts
- [ ] Referral tracking system

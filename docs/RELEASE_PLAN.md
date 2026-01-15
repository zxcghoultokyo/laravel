# 🚀 Release Plan: Multi-Tenant SaaS

> **Мета:** Перетворити single-tenant AI-бота на комерційний SaaS продукт

## 📊 Поточний стан (Січень 2026)

### ✅ Phase 1: MVP Multi-Tenant - ЗАВЕРШЕНО!

| Компонент | Статус | Файли |
|-----------|--------|-------|
| Tenant Model | ✅ | `app/Models/Tenant.php` |
| BelongsToTenant trait | ✅ | `app/Models/Traits/BelongsToTenant.php` |
| TenantScope | ✅ | `app/Models/Scopes/TenantScope.php` |
| Tenant Middleware | ✅ | `app/Http/Middleware/ResolveTenantMiddleware.php` |
| Limit Middleware | ✅ | `app/Http/Middleware/CheckTenantLimitsMiddleware.php` |
| Widget per Tenant | ✅ | `app/Http/Controllers/Api/TenantWidgetController.php` |
| Admin Tenant API | ✅ | `app/Http/Controllers/Api/Admin/TenantController.php` |
| Laravel Breeze Auth | ✅ | Custom registration with tenant |
| Onboarding Wizard | ✅ | `app/Http/Controllers/OnboardingController.php` (5 steps) |
| Tenant Dashboard | ✅ | `app/Http/Controllers/TenantDashboardController.php` |

### ✅ Phase 2: Monetization - ЗАВЕРШЕНО!

| Компонент | Статус | Файли |
|-----------|--------|-------|
| Billing Interface | ✅ | `app/Services/Billing/Contracts/BillingServiceInterface.php` |
| WayForPay Driver | ✅ | `app/Services/Billing/Drivers/WayForPayDriver.php` |
| LiqPay Driver | ✅ | `app/Services/Billing/Drivers/LiqPayDriver.php` |
| BillingManager | ✅ | `app/Services/Billing/BillingManager.php` |
| Subscription Model | ✅ | `app/Models/Subscription.php` |
| Payment Model | ✅ | `app/Models/Payment.php` |
| Billing Controller | ✅ | `app/Http/Controllers/BillingController.php` |
| Webhook Controller | ✅ | `app/Http/Controllers/Api/BillingWebhookController.php` |
| Usage Tracking | ✅ | `app/Services/Usage/UsageTrackingService.php` |
| Billing UI | ✅ | `resources/views/billing/*.blade.php` |

### 💰 Pricing Plans (config/billing.php)

| Plan | Price | Messages/mo | Products |
|------|-------|-------------|----------|
| **Trial** | Free (14 days) | 100 | 100 |
| **Starter** | 799 ₴/mo | 1,000 | 500 |
| **Pro** | 1,999 ₴/mo | 5,000 | 5,000 |
| **Enterprise** | 4,999 ₴/mo | 50,000 | Unlimited |

---

## 📈 Phase 3: Growth (TODO)

### 3.1 Bot Customization
- [ ] Custom AI prompts in admin
- [ ] Product recommendations rules
- [ ] Cross-sell/upsell settings
- [ ] FAQ management

### 3.2 Advanced Analytics
- [ ] Conversion funnel
- [ ] Revenue attribution
- [ ] A/B test results
- [ ] Export reports

### 3.3 Operator Dashboard
- [ ] Live chat takeover
- [ ] Canned responses
- [ ] Assignment rules
- [ ] SLA tracking

### 3.4 Integrations
- [ ] Shopify app
- [ ] WooCommerce plugin
- [ ] Telegram notifications
- [ ] Email alerts

---

## 🏗️ Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        MULTI-TENANT                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌─────────────┐     ┌─────────────┐     ┌─────────────┐        │
│  │   Tenant    │────▶│ Subscription│────▶│  Payments   │        │
│  │  (магазин)  │     │   (plan)    │     │  (history)  │        │
│  └─────────────┘     └─────────────┘     └─────────────┘        │
│         │                                                        │
│         ├──▶ Users (owner, team)                                │
│         ├──▶ Products (scoped)                                  │
│         ├──▶ ChatSessions (scoped)                              │
│         ├──▶ WidgetSettings                                     │
│         └──▶ PromptPresets                                      │
│                                                                  │
│  Widget: /widget/{tenant_slug}.js                               │
│  API: /api/widget/{slug}/chat                                   │
│  Admin: /dashboard (auth required)                              │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### Billing Flow

```
1. User selects plan → /billing/checkout/{plan}
2. Form POST → /billing/subscribe/{plan}
3. Redirect to WayForPay/LiqPay
4. User pays → Webhook: /api/billing/webhook/{provider}
5. Subscription activated → Tenant plan updated
6. Monthly: auto-charge via provider
7. If failed: suspend tenant
```

### Usage Tracking

```php
// Increment on each chat message
$usageService->incrementMessages($tenant);

// Check limits (middleware)
if ($usageService->hasReachedLimit($tenant)) {
    return 429; // Limit exceeded
}

// Monthly reset (scheduled)
php artisan tenants:reset-usage
```

---

## 🔧 Environment Variables

```env
# Billing
BILLING_DRIVER=wayforpay
BILLING_CURRENCY=UAH

# WayForPay
WAYFORPAY_MERCHANT_ACCOUNT=xxx
WAYFORPAY_MERCHANT_SECRET=xxx

# LiqPay
LIQPAY_PUBLIC_KEY=xxx
LIQPAY_PRIVATE_KEY=xxx

# Fiscal (ПРРО)
FISCAL_ENABLED=false
CHECKBOX_LICENSE_KEY=xxx
```

---

## 📅 Timeline

| Week | Phase | Status |
|------|-------|--------|
| 1 | Tenant model + scopes | ✅ DONE |
| 2 | Auth + Onboarding | ✅ DONE |
| 3 | Widget multi-tenant | ✅ DONE |
| 4 | Billing (UA providers) | ✅ DONE |
| 5 | Testing + Polish | 🔄 IN PROGRESS |

---

## ✅ Launch Checklist

### Pre-launch
- [x] All migrations run
- [x] Billing webhooks configured
- [ ] Email templates (welcome, password reset)
- [ ] Error tracking (Sentry)
- [ ] Backup strategy

### Launch
- [ ] DNS configured
- [ ] SSL certificates
- [ ] First tenant created (test)
- [ ] Onboarding tested end-to-end
- [ ] Payment flow tested
- [ ] Widget tested on real site

---

*Last updated: January 15, 2026*

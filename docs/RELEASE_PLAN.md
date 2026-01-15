# 🚀 Release Plan: Multi-Tenant SaaS

> **Мета:** Перетворити single-tenant AI-бота на комерційний SaaS продукт

## 📊 Поточний стан

### Що є ✅
- AI-чат з OpenAI function calling (streaming SSE)
- Пошук товарів (Meilisearch + DB fallback)
- Widget з кастомізацією (колір, лого, позиція)
- Greetings система (по UTM, URL, device)
- Tone/Brand Rules
- Prompt Presets + Auto-generation
- Admin panel (Livewire)
- Analytics (basic)
- **[NEW] Tenant model + migrations** ✅
- **[NEW] BelongsToTenant trait + TenantScope** ✅
- **[NEW] Tenant middleware (resolve + limits)** ✅
- **[NEW] Admin API for tenant management** ✅
- **[NEW] Widget routes per tenant** ✅

### Чого немає ❌
- ~~Multi-tenancy (один магазин = один інстанс)~~ ✅ DONE
- User authentication (no login) - Laravel Breeze pending
- Billing/Subscriptions - Stripe integration pending
- Onboarding wizard
- Customer self-service

---

## 🎯 Phase 1: MVP Multi-Tenant (1-2 тижні)

### ✅ 1.1 Tenant Model & Architecture - COMPLETED!

```
┌─────────────────────────────────────────────────────────────────┐
│                        ARCHITECTURE                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌─────────────┐     ┌─────────────┐     ┌─────────────┐        │
│  │   Tenant    │────▶│WidgetSettings│────▶│  Products   │        │
│  │  (магазин)  │     │  (1 per T)  │     │  (T scope)  │        │
│  └─────────────┘     └─────────────┘     └─────────────┘        │
│         │                   │                   │                │
│         ▼                   ▼                   ▼                │
│  ┌─────────────┐     ┌─────────────┐     ┌─────────────┐        │
│  │    User     │     │  Greetings  │     │ChatSessions │        │
│  │ (owner/team)│     │  (T scope)  │     │  (T scope)  │        │
│  └─────────────┘     └─────────────┘     └─────────────┘        │
│                                                                  │
│  Widget URL: chat.ailure.ai/widget/{tenant_slug}                │
│  Admin URL: app.ailure.ai/admin (auth required)                 │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 1.2 Database Schema

```sql
-- tenants (магазини)
CREATE TABLE tenants (
    id BIGINT PRIMARY KEY,
    name VARCHAR(255),           -- "Contractor Tactical"
    slug VARCHAR(100) UNIQUE,    -- "contractor" → widget URL
    domain VARCHAR(255),         -- "contractor.com.ua"
    
    -- Owner
    owner_user_id BIGINT,
    
    -- Plan & Billing
    plan VARCHAR(50) DEFAULT 'trial',  -- trial, starter, pro, enterprise
    plan_expires_at TIMESTAMP,
    stripe_customer_id VARCHAR(255),
    
    -- Limits
    messages_limit INT DEFAULT 500,    -- per month
    messages_used INT DEFAULT 0,
    
    -- Status
    status VARCHAR(20) DEFAULT 'active',  -- active, suspended, cancelled
    
    -- Horoshop/Shopify credentials
    platform VARCHAR(50),          -- horoshop, shopify, manual
    platform_credentials JSON,     -- encrypted API keys
    
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- users (власники та команда)
CREATE TABLE users (
    id BIGINT PRIMARY KEY,
    tenant_id BIGINT,
    email VARCHAR(255) UNIQUE,
    password VARCHAR(255),
    name VARCHAR(255),
    role VARCHAR(50) DEFAULT 'owner',  -- owner, admin, operator
    email_verified_at TIMESTAMP,
    created_at TIMESTAMP
);

-- Existing tables get tenant_id:
-- widget_settings.tenant_id
-- products.tenant_id
-- chat_sessions.tenant_id
-- greetings.tenant_id
-- prompt_presets.tenant_id
```

### 1.3 TODO: Migrations

```bash
# Порядок міграцій
1. create_tenants_table
2. create_users_table (Laravel default + tenant_id)
3. add_tenant_id_to_widget_settings
4. add_tenant_id_to_products
5. add_tenant_id_to_chat_sessions
6. add_tenant_id_to_greetings
7. add_tenant_id_to_prompt_presets
8. add_tenant_id_to_store_contexts
```

### 1.4 Widget with Tenant

**Current:** `https://shop.com/widget.js`
**New:** `https://chat.ailure.ai/widget/{tenant_slug}.js`

```javascript
// Embed code для клієнта
<script src="https://chat.ailure.ai/widget/contractor.js" async></script>

// widget.js динамічно завантажує конфіг по tenant_slug
// GET /api/widget/{tenant_slug}/settings
// GET /api/widget/{tenant_slug}/greeting
// POST /api/widget/{tenant_slug}/chat
```

### 1.5 Onboarding Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                      ONBOARDING WIZARD                           │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Step 1: Реєстрація                                             │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ Email: [________________]                                   ││
│  │ Password: [________________]                                ││
│  │ Назва магазину: [________________]                         ││
│  │                                   [Створити акаунт]        ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                  │
│  Step 2: Підключення магазину                                   │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ Виберіть платформу:                                        ││
│  │ ┌──────────┐ ┌──────────┐ ┌──────────┐                    ││
│  │ │ Horoshop │ │ Shopify  │ │  Інше    │                    ││
│  │ └──────────┘ └──────────┘ └──────────┘                    ││
│  │                                                            ││
│  │ API Domain: [contractor.horoshop.ua___]                   ││
│  │ API Login:  [________________]                            ││
│  │ API Pass:   [________________]                            ││
│  │                          [Перевірити з'єднання]           ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                  │
│  Step 3: Синхронізація                                          │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ ✅ Знайдено 847 товарів                                    ││
│  │ ✅ 23 категорії                                            ││
│  │ ✅ 15 брендів                                              ││
│  │                                                            ││
│  │ 🤖 AI визначив тип магазину: Тактичне спорядження         ││
│  │                                                            ││
│  │ [Імпортувати товари] — займе ~2 хв                        ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                  │
│  Step 4: Кастомізація віджета                                   │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ Колір: [#2563EB] 🔵                                        ││
│  │ Назва бота: [AI Асистент___]                              ││
│  │ Вітання: [Привіт! Чим можу допомогти?___]                 ││
│  │                                                            ││
│  │ Preview: [Widget preview]                                  ││
│  │                                                            ││
│  │                                        [Продовжити]       ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                  │
│  Step 5: Embed Code                                             │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ 🎉 Все готово!                                             ││
│  │                                                            ││
│  │ Вставте цей код перед </body> на вашому сайті:            ││
│  │ ┌─────────────────────────────────────────────────────────┐││
│  │ │ <script src="https://chat.ailure.ai/widget/            │││
│  │ │   contractor.js" async></script>                        │││
│  │ └─────────────────────────────────────────────────────────┘││
│  │                                        [📋 Копіювати]     ││
│  │                                                            ││
│  │ Або надішліть інструкцію розробнику: [Email]              ││
│  │                                                            ││
│  │                            [Перейти в Dashboard →]        ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 1.6 Implementation Checklist

#### Models
- [ ] `Tenant` model
- [ ] `User` model (extend Laravel default)
- [ ] Add `tenant_id` to all existing models
- [ ] Tenant scopes (global scope for auto-filtering)

#### Auth
- [ ] Registration (email + password)
- [ ] Login
- [ ] Email verification
- [ ] Password reset
- [ ] Tenant middleware (resolve from auth user)

#### API
- [ ] `GET /api/widget/{tenant_slug}/settings`
- [ ] `GET /api/widget/{tenant_slug}/greeting`
- [ ] `POST /api/widget/{tenant_slug}/chat`
- [ ] `GET /api/widget/{tenant_slug}/chat/stream` (SSE)

#### Admin
- [ ] Auth pages (login, register, forgot password)
- [ ] Onboarding wizard (5 steps)
- [ ] Dashboard (tenant-scoped)
- [ ] Settings (tenant-scoped)

#### Widget
- [ ] Dynamic loading by tenant slug
- [ ] Tenant-specific settings
- [ ] Tenant-specific chat endpoint

---

## 💰 Phase 2: Monetization (1 тиждень)

### 2.1 Pricing Plans

| Plan | Price | Messages/mo | Features |
|------|-------|-------------|----------|
| **Trial** | Free (14 days) | 100 | Basic widget, 1 user |
| **Starter** | $29/mo | 1,000 | Custom branding, 2 users |
| **Pro** | $79/mo | 5,000 | Greetings, Tone, 5 users |
| **Enterprise** | Custom | Unlimited | API, Dedicated support |

### 2.2 Stripe Integration

```php
// Subscription flow
1. User selects plan
2. Redirect to Stripe Checkout
3. Webhook: checkout.session.completed
4. Update tenant: plan, stripe_customer_id, plan_expires_at
5. Webhook: invoice.paid → reset messages_used
6. Webhook: invoice.payment_failed → suspend tenant
```

### 2.3 Usage Limits

```php
// Middleware: CheckMessageLimit
public function handle($request, $next)
{
    $tenant = $request->tenant;
    
    if ($tenant->messages_used >= $tenant->messages_limit) {
        return response()->json([
            'error' => 'limit_exceeded',
            'message' => 'Ліміт повідомлень вичерпано. Оновіть план.',
            'upgrade_url' => route('billing.upgrade'),
        ], 402);
    }
    
    return $next($request);
}
```

### 2.4 Implementation Checklist

#### Billing
- [ ] Stripe SDK setup
- [ ] Plans seeder
- [ ] Checkout flow
- [ ] Customer portal (manage subscription)
- [ ] Webhooks (payment, cancellation)
- [ ] Usage tracking

#### UI
- [ ] Pricing page (public)
- [ ] Billing page (admin)
- [ ] Plan comparison
- [ ] Upgrade prompts

#### Limits
- [ ] Message counter
- [ ] Limit exceeded handling
- [ ] Usage dashboard

---

## 📈 Phase 3: Growth (ongoing)

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

## 🏗️ Technical Architecture

### Hosting

```
┌─────────────────────────────────────────────────────────────────┐
│                      INFRASTRUCTURE                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │                    Laravel Cloud                            ││
│  │  ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌─────────┐       ││
│  │  │   Web   │  │  Queue  │  │Scheduler│  │   SSE   │       ││
│  │  │ Server  │  │ Worker  │  │  Cron   │  │ Server  │       ││
│  │  └─────────┘  └─────────┘  └─────────┘  └─────────┘       ││
│  └─────────────────────────────────────────────────────────────┘│
│                              │                                   │
│  ┌───────────────────────────┴───────────────────────────────┐  │
│  │                      Managed Services                      │  │
│  │  ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌─────────┐      │  │
│  │  │ MySQL   │  │  Redis  │  │Meilisearch│ │   S3   │      │  │
│  │  │   DB    │  │  Cache  │  │  Search  │  │ Storage│      │  │
│  │  └─────────┘  └─────────┘  └─────────┘  └─────────┘      │  │
│  └───────────────────────────────────────────────────────────┘  │
│                                                                  │
│  External:                                                       │
│  ┌─────────┐  ┌─────────┐  ┌─────────┐                         │
│  │ OpenAI  │  │ Stripe  │  │ Sentry  │                         │
│  │   API   │  │Payments │  │ Errors  │                         │
│  └─────────┘  └─────────┘  └─────────┘                         │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### Domains

| Domain | Purpose |
|--------|---------|
| `ailure.ai` | Landing page |
| `app.ailure.ai` | Admin dashboard |
| `chat.ailure.ai` | Widget API + assets |
| `api.ailure.ai` | Public API (future) |

### Security

- [ ] HTTPS everywhere
- [ ] API rate limiting (per tenant)
- [ ] CORS (allow only tenant domains)
- [ ] Encrypted credentials (platform API keys)
- [ ] GDPR compliance (data export/delete)

---

## 📅 Timeline

| Week | Phase | Deliverables |
|------|-------|--------------|
| 1 | Tenant model | Migrations, models, scopes |
| 2 | Auth + Onboarding | Registration, login, wizard |
| 3 | Widget multi-tenant | Dynamic loading, tenant API |
| 4 | Billing | Stripe, plans, limits |
| 5 | Polish | Testing, docs, launch prep |

---

## ✅ Launch Checklist

### Pre-launch
- [ ] All migrations run on production
- [ ] Stripe webhooks configured
- [ ] Email templates (welcome, password reset)
- [ ] Error tracking (Sentry)
- [ ] Logging (CloudWatch/Papertrail)
- [ ] Backup strategy

### Launch
- [ ] DNS configured
- [ ] SSL certificates
- [ ] First tenant created (test)
- [ ] Onboarding tested end-to-end
- [ ] Payment flow tested
- [ ] Widget tested on real site

### Post-launch
- [ ] Monitor error rates
- [ ] Monitor API latency
- [ ] Customer support ready
- [ ] Documentation published

---

## 🎯 Success Metrics

| Metric | Target (Month 1) |
|--------|------------------|
| Signups | 50 |
| Trial → Paid | 20% |
| MRR | $500 |
| Churn | <10% |
| Messages processed | 10,000 |
| Avg response time | <2s |

---

## 📝 Notes

### Why Multi-Tenant?
- Scalability: one codebase, many customers
- Easier maintenance: deploy once
- Cost efficiency: shared resources
- Faster onboarding: self-service

### Key Decisions
1. **Tenant isolation**: Soft (tenant_id column) vs Hard (separate DBs) → **Soft** for simplicity
2. **Widget delivery**: CDN vs dynamic → **Dynamic** (per-tenant config)
3. **Auth**: Laravel Breeze vs Jetstream → **Breeze** (simpler)
4. **Billing**: Stripe vs LiqPay → **Stripe** (global, better docs)

---

*Last updated: January 15, 2026*

# Production Configuration & Debugging

## Environment Variables

### Core Application
```bash
APP_KEY=base64:YOUR_APP_KEY_HERE
APP_ENV=production
APP_DEBUG=false
APP_NAME=AIntento
APP_URL=https://aintento.laravel.cloud
```

### OpenAI Integration
```bash
OPENAI_API_KEY=sk-proj-YOUR_KEY_HERE
OPENAI_MODEL=gpt-4.1
OPENAI_BASE_URL=https://api.openai.com/v1
```

### Meilisearch
```bash
MEILI_ENABLED=1
MEILI_HOST=https://your-meili-instance.fly.dev
MEILI_MASTER_KEY=YOUR_MEILI_KEY
MEILI_INDEX_PRODUCTS=products
```

### Horoshop Platform
```bash
HOROSHOP_DOMAIN=https://your-shop.horoshop.ua
HOROSHOP_API_LOGIN=your_login
HOROSHOP_API_PASSWORD=your_password
```

### Billing (WayForPay)
```bash
WAYFORPAY_MERCHANT_ACCOUNT=your_merchant
WAYFORPAY_MERCHANT_SECRET=your_secret
WAYFORPAY_WEBHOOK_URL=https://aintento.laravel.cloud/api/billing/webhook/wayforpay
```

### Caching & Queue
```bash
CACHE_DRIVER=file
QUEUE_CONNECTION=database
```

### Admin
```bash
ADMIN_JOBS_TOKEN=your_secure_token
```

---

## Debugging

### 1. Check Meilisearch Status
```bash
# Via tinker
php artisan tinker
>>> config('meilisearch.enabled') ? 'Meili OK' : 'DISABLED'

# Via curl
curl -i https://your-meili.fly.dev/health
```

### 2. Check Products Index
```bash
php artisan tinker
>>> $index = app('meilisearch')->index('products');
>>> $index->getRawInfo()['numberOfDocuments']
```

### 3. Test Search
```bash
# Meili search API
curl -X POST https://your-meili.fly.dev/indexes/products/search \
  -H "Authorization: Bearer YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"q": "плитоноска", "limit": 5}'
```

### 4. Check Chat via API
```bash
# SSE Stream
curl "https://aintento.laravel.cloud/api/chat/stream?message=привіт&session_id=test123&widget_key=YOUR_KEY"

# POST JSON
curl -X POST https://aintento.laravel.cloud/api/chat \
  -H "Content-Type: application/json" \
  -d '{"message":"плитоноска","session_id":"test","widget_key":"YOUR_KEY"}'
```

---

## Common Issues

### ❌ "Meilisearch is disabled"
- **Fix**: Check `.env` and run `php artisan config:cache`

### ❌ "Meilisearch connection refused"
- **Fix**: Verify Fly.io Meili is running: `flyctl status -a your-meili`

### ❌ "No products found"
- **Fix 1**: Check DB: `Product::count()`
- **Fix 2**: Re-index: `php artisan meili:reindex-products`

### ❌ "Widget blocked"
- **Fix**: Check tenant trial/subscription: `Tenant::find(ID)->canUseWidget()`

---

## Monitoring Commands

```bash
# Real-time logs
tail -f storage/logs/laravel.log

# Recent chats
php artisan tinker --execute="
\App\Models\ChatMessage::latest()->limit(10)->get(['message', 'created_at'])->dump();
"

# Product stats
php artisan tinker --execute="
echo 'Total: ' . \App\Models\Product::count();
"
```

---

## Deployment

1. Push to GitHub → Laravel Cloud auto-deploys
2. Run migrations: `php artisan migrate`
3. Clear cache: `php artisan config:cache`
4. Re-index if needed: `php artisan meili:reindex-products`

---

## Key Files

- **Config**: `config/services.php`, `config/meilisearch.php`, `config/billing.php`
- **Agents**: `app/Services/Agent/FunctionCallingAgent.php`, `app/Services/Agent/StreamingFunctionCallingAgent.php`
- **Search**: `app/Services/Agent/Tools/MeiliProductSearchTool.php`
- **Tenant**: `app/Models/Tenant.php`
- **Logs**: `storage/logs/laravel.log`


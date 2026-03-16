# Deploy Checklist — Multi-Tenant SaaS

## Pre-deploy перевірка

### 1. Код готовий ✅
- [x] FunctionCallingAgent + StreamingFunctionCallingAgent
- [x] Per-tenant prompt presets (PromptPresetService + TenantPromptGenerator)
- [x] Multi-tenant architecture (TenantScope, ResolveTenantMiddleware)
- [x] Eloquent fallback для Meili
- [x] short_query_handler (1-word bypass GPT)
- [x] Age query handling (bavkatoys)
- [x] Filter extraction (budget, color)
- [x] OnboardTenantJob (7-step)

### 2. Env змінні (на Laravel Cloud)
```bash
# Обов'язкові
APP_KEY=base64:...

# OpenAI
OPENAI_API_KEY=sk-proj-...
OPENAI_MODEL=gpt-4o
OPENAI_MODEL_CHAT=gpt-4o
OPENAI_MODEL_ANALYZE=gpt-4o-mini
OPENAI_MODEL_RERANK=gpt-4o-mini
OPENAI_BASE_URL=https://api.openai.com/v1

# Meilisearch (ВАЖЛИВО: якщо MEILI_ENABLED=0, то Eloquent fallback)
MEILI_ENABLED=1
MEILI_HOST=https://meilisearch-aimbot.fly.dev
MEILI_MASTER_KEY=...
MEILI_INDEX_PRODUCTS=products

# Horoshop (per-tenant credentials зберігаються в widget_settings)
# Legacy single-tenant:
HOROSHOP_DOMAIN=https://contractor.kiev.ua
HOROSHOP_API_LOGIN=owner
HOROSHOP_API_PASSWORD=...

# Cache & Queue
CACHE_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=database

# Admin
ADMIN_JOBS_TOKEN=...

# Logging
LOG_CHANNEL=stderr

# Billing
BILLING_DRIVER=wayforpay
BILLING_CURRENCY=UAH
```

### 3. Команди для запуску на production

```bash
# 1. Deploy через git push
git add -A
git commit -m "feat: ..."
git push origin main    # Laravel Cloud auto-deploys

# 2. Після деплою (через Cloud console):
php artisan migrate --force
php artisan meili:setup-products    # one-time
php artisan meili:reindex-products
php artisan optimize
php artisan queue:restart
```

### 4. Перевірка після деплою

1. **Тест чату (T2):**
```bash
curl -s "https://aintento.laravel.cloud/api/chat" \
  -H "Content-Type: application/json" \
  -d '{"message":"шоломи","session_id":"deploy-test","token":"zIzYKx8o2RVdT1KYmJAv25FJO5GIbxZj"}' | python3 -c "
import sys,json;d=json.load(sys.stdin)
print('source:', d.get('meta',{}).get('source','GPT'))
print('products:', len(d.get('products',[])))
"
```

2. **Diagnostic DB stats:**
```bash
curl "https://aintento.laravel.cloud/api/diagnostic/db-stats?key=diagnostic_secret_key_2025"
```

3. **Widget на сайті:**
   - Перевірити SSE streaming працює
   - Перевірити 1-слівні запити (bypass GPT)
   - Перевірити multi-word запити (GPT)

### 5. Моніторинг

- Швидкість відповідей (< 3 сек)
- OpenAI витрати
- Queue health (failed jobs)
- `php artisan pail` — real-time logs

### 6. Rollback план

```bash
# Git revert
git revert HEAD
git push origin main

# Або повернутись на попередній commit
git reset --hard <commit>
git push origin main --force  # ⚠️ тільки якщо критично
```

## 🚀 Новий тенант

```bash
# OnboardTenantJob автоматично запускається при створенні тенанта
# Або ручний запуск:
curl -X POST "https://aintento.laravel.cloud/api/diagnostic/onboard-tenant?key=diagnostic_secret_key_2025&tenant_id=X"
```

---

*Last updated: March 2026*

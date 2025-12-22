# Deploy Checklist - Agent Orchestrator

## Pre-deploy перевірка

### 1. Код готовий ✅
- [x] AgentOrchestrator + 4 tools
- [x] ChatService інтеграція
- [x] Admin debug panel
- [x] Eloquent fallback для Meili
- [x] Filter extraction (budget, color)

### 2. Env змінні (на Laravel Cloud)
Перевір що є:
```bash
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-5.1
OPENAI_BASE_URL=https://api.openai.com/v1

MEILI_ENABLED=true
MEILI_HOST=http://meilisearch:7700
MEILI_MASTER_KEY=...
```

### 3. Команди для запуску на production

```bash
# 1. Deploy через git push
git add -A
git commit -m "feat: Agent Orchestrator з AI debug panel в адмінці"
git push origin main

# Laravel Cloud автоматично задеплоїть

# 2. Після деплою - через Laravel Cloud console або SSH:

# Міграції (якщо були зміни БД)
php artisan migrate --force

# Sync товарів з Horoshop (якщо потрібно)
php artisan sync:horoshop-products

# Setup Meilisearch (один раз)
php artisan meili:setup-products

# Індексація products в Meilisearch
php artisan meili:reindex-products

# Очистити кеш
php artisan optimize

# Restart queue workers
php artisan queue:restart
```

### 4. Перевірка після деплою

1. **Перевірити env** (через tinker):
```bash
php artisan tinker
echo config('services.openai.key') ? 'OpenAI OK' : 'NO KEY';
echo config('meilisearch.enabled') ? 'Meili OK' : 'DISABLED';
exit
```

2. **Тест агента**:
```bash
php test-agent.php
```

3. **Через widget** на contractor.kiev.ua:
   - Відправити: "плити"
   - Відправити: "яку каску взяти?"
   - Перевірити в адмінці debug info

4. **Перевірити адмінку**:
   - Відкрити `/admin/chats`
   - Вибрати останню сесію
   - Перевірити що показується:
     - ✅ AI Agent Flow блок
     - ✅ Intent, ambiguous, filters
     - ✅ Search Debug з timing
     - ✅ Chosen IDs

### 5. Моніторинг

Після деплою слідкувати за:
- Швидкість відповідей (< 3 сек)
- OpenAI витрати (CloudWatch/logs)
- Помилки в `storage/logs/laravel.log`
- Widget auto-open bug (перевірити чи ще є)

### 6. Rollback план

Якщо щось не так:
```bash
# Повернутись на legacy логіку
# ChatService має fallback - просто закоментувати виклик AgentOrchestrator
```

## Deploy команда одним рядком

```bash
git add -A && git commit -m "feat: Agent Orchestrator з AI debug panel" && git push origin main
```

Після успішного деплою на Laravel Cloud:
```bash
# Через Cloud console
php artisan migrate --force && php artisan app:index-products-to-meili && php artisan queue:restart
```

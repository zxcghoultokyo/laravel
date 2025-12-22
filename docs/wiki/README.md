# 📚 Внутрішня Вікі Проекту — Contractor AI Shop

> **Метадані документації**  
> Створено: 22.12.2025  
> Версія: 1.0  
> Статус: Активна розробка

---

## 🎯 Про Проект

**Contractor AI Shop** — інтелектуальна система продажу тактичного військового спорядження для ЗСУ з AI-асистентом для пошуку товарів.

### Ключові особливості
- 🤖 **AI-асистент** — ChatGPT-4.1-mini для класифікації запитів і пошуку
- 🔍 **Meilisearch** — швидкий нечіткий пошук (typo tolerance, фільтри, бустинг)
- 🏗️ **Agent-based Architecture** — оркестратор + інструменти (tools pattern)
- 🛡️ **Tactical Focus** — спеціалізація на військовому екіпіруванні

### Стек Технологій
- **Backend**: Laravel 12, PHP 8.3
- **AI**: OpenAI GPT-4.1-mini
- **Search**: Meilisearch 1.5+
- **Frontend**: Vanilla JS widget (public/widget.js)
- **Database**: MySQL/PostgreSQL + Eloquent ORM
- **Queue**: Laravel Queue (для фонових задач)

---

## 📖 Розділи Документації

### 1. [Архітектура Системи](architecture.md)
Загальний огляд архітектури, сервіси, залежності, flow даних.

### 2. [Система Пошуку](search-system.md) ⭐
- Meilisearch інтеграція
- Agent Orchestrator pipeline
- Tools: MeiliProductSearchTool, DeduperTool, AccessoryFilterTool, AiRerankTool
- Brand detection & boosting
- Фільтрація аксесуарів

### 3. [AI Інтеграція](ai-integration.md) ⭐
- AiRouter — центральний OpenAI клієнт
- Intent classification (product_search, order_status, faq, smalltalk)
- Query normalization
- AI Reranking (dynamic limit 3-10)

### 4. [Frontend & Widget](frontend-integration.md) ⭐
- Widget.js — standalone чат
- Інтеграція на сторонні сайти
- API контракт з backend
- Стилізація та налаштування

### 5. [Імплементація Чату](chat-implementation.md) ⭐
- Інтеграція widget.js у HTML
- Налаштування токенів
- Кастомізація UI

### 6. [Product Service](product-service.md) ⭐
- Синхронізація з Horoshop (зовнішня платформа)
- Product model структура
- Індексація в Meilisearch
- Background jobs (SyncHoroshopProductsJob, IndexProductsToMeiliJob)

### 7. [Відомі Проблеми](known-issues.md) ⭐
- Критичні баги
- Обмеження системи
- Недоробки
- Planned fixes

### 8. [Hardcoded Values](hardcoded-values.md) ⭐
- FAQ responses в AgentOrchestrator
- Category hints в ProductService
- Accessory keywords
- Що потрібно винести в БД/конфіг

### 9. [Roadmap & Progress](roadmap.md) ⭐
- ✅ Що вже зроблено
- 🚧 В процесі
- 📋 Що потрібно зробити
- 🔮 Майбутні плани

### 10. [Deployment](deployment.md)
- Laravel Cloud deployment
- Environment variables
- Queue setup
- Scheduler setup

### 11. [API Documentation](api-reference.md)
- Endpoints
- Request/Response formats
- Authentication

### 12. [Database Schema](database-schema.md)
- Таблиці
- Міграції
- Relationships

---

## 🚀 Швидкий Старт

### Локальна Розробка
```bash
# Клонувати репо
git clone <repo-url>
cd laravel

# Встановити залежності
composer install
npm install

# Налаштувати .env
cp .env.example .env
php artisan key:generate

# Запустити dev середовище
composer run dev  # Запускає: server + queue + vite + logs
```

### Production Deployment
```bash
# На Laravel Cloud
# 1. Push код до main branch
git push origin main

# 2. Laravel Cloud автоматично деплоїть
# 3. Налаштувати env змінні через UI:
#    - OPENAI_API_KEY
#    - MEILI_HOST
#    - HOROSHOP_*
```

---

## 📊 Метрики Проекту

### Статистика Коду (станом на 22.12.2025)
- **PHP Lines**: ~15,000 LOC
- **Services**: 25+ сервісів
- **Models**: 12 Eloquent моделей
- **Jobs**: 5 background jobs
- **API Endpoints**: 15+ маршрутів
- **Agent Tools**: 5 інструментів

### Покриття Товарів
- **Продуктів в БД**: ~2,800
- **Брендів**: 73 (KOMBAT UK, АТАКА, HOFFMANN, EastGear, USA Army)
- **Категорій**: 150+ унікальних category_path

---

## 🔗 Корисні Посилання

### Зовнішні Ресурси
- [Laravel 12 Documentation](https://laravel.com/docs/12.x)
- [Meilisearch Docs](https://www.meilisearch.com/docs)
- [OpenAI API Reference](https://platform.openai.com/docs/api-reference)

### Внутрішні Документи
- [AGENT_ORCHESTRATOR.md](../AGENT_ORCHESTRATOR.md) — технічна документація оркестратора
- [DEPLOY_CHECKLIST.md](../DEPLOY_CHECKLIST.md) — чекліст перед деплоєм
- [README.md](../../README.md) — основний README проекту

---

## 🤝 Контрибуція

### Додавання Нової Документації
1. Створіть .md файл у `docs/wiki/`
2. Додайте посилання в цей README
3. Слідуйте структурі існуючих документів
4. Включайте код-приклади та діаграми

### Оновлення Існуючої Документації
- Всі зміни датувати в розділі "Changelog"
- Зберігати історію версій
- Актуалізувати "Остання оновлення" в header

---

## 📝 Ліцензія

Внутрішня документація проекту. Не для публічного доступу.

---

**Підтримка**: Документація створена для розробників команди Contractor AI Shop.

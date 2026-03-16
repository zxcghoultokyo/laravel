# 🧠 Prompt Generation Architecture

> Оновлено: 2026-03-16

## Огляд

Кожен тенант отримує **персональний системний промпт**, що генерується автоматично при онбордингу на основі каталогу магазину. Промпт будується поверх **PromptModulesService** — перевіреного модульного фундаменту з правилами пошуку, форматування та follow-up.

## Архітектура: Як стакаються промпти

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                         SYSTEM PROMPT STACK (per-tenant)                         │
├─────────────────────────────────────────────────────────────────────────────────┤
│                                                                                  │
│  getCriticalPrefix()  — антигалюцинація, ліміт 3 товари, формат                 │
│                                                                                  │
│  ┌───────────────────────────────────────────────────────────────────────────┐  │
│  │ LAYER 1: BASE PRESET (is_default=true для тенанта)                       │  │
│  │   Варіант A: Кастомний (створений адміном, slug != auto-generated-*)     │  │
│  │   Варіант B: Авто-згенерований (TenantPromptGenerator)                   │  │
│  └───────────────────────────────────────────────────────────────────────────┘  │
│                                                                                  │
│  ┌───────────────────────────────────────────────────────────────────────────┐  │
│  │ LAYER 2: OVERLAYS (is_default=false, sort by priority DESC)              │  │
│  │   • Скрипти частих питань (priority=95)                                  │  │
│  │   • Категорійні (Меблі → priority=90, Топ → priority=70)                │  │
│  │   • Кампанійні (UTM match)                                               │  │
│  └───────────────────────────────────────────────────────────────────────────┘  │
│                                                                                  │
│  getCriticalSuffix()  — телефон, обмеження                                      │
│                                                                                  │
│  ↓ АБО (якщо немає жодного preset для тенанта)                                  │
│                                                                                  │
│  FALLBACK: PromptModulesService (shared модульний промпт)                       │
│                                                                                  │
└─────────────────────────────────────────────────────────────────────────────────┘
```

## Ключові сервіси

### 1. TenantPromptGenerator

**Файл:** `app/Services/Ai/TenantPromptGenerator.php`

Аналізує каталог тенанта і генерує персональний промпт.

**Як працює:**
1. `analyzeTenant()` — збирає категорії, бренди, ціни, визначає `has_age_categories`
2. `buildPrompt()` — збирає промпт з модулів PromptModulesService + додає профіль магазину
3. `saveAsPreset()` — зберігає як `PromptPreset` (з безпечною логікою)

**Структура згенерованого промпта:**

```
📋 ПРОФІЛЬ МАГАЗИНУ:                    ← buildStoreProfile()
- Категорії, бренди, кількість товарів
- Приклади пошуку для цього магазину

Ти — AI-консультант магазину "..."

🎯 ГОЛОВНІ ПРАВИЛА:                     ← PromptModulesService::getCoreModule()
- ЗАВЖДИ шукай через search_products()
- МАКСИМУМ 3 товари
- ПОСИЛАННЯ ЗАБОРОНЕНО
- Формат intro, замовлення

🔍 ПОШУК ТОВАРІВ:                       ← PromptModulesService::getSearchModule($hasAge)
- Синоніми через OR
- Retry якщо не знайшов
- Сезонність
- ⛔ НЕ питай про вік (якщо !hasAge)  ← КРИТИЧНО для тактичних магазинів
- ВІКОВА ФІЛЬТРАЦІЯ (якщо hasAge)    ← для дитячих магазинів

🔄 FOLLOW-UP:                           ← PromptModulesService::getFollowUpModule()
- "покажи ще" → exclude_shown
- "дешевше/дорожче" → фільтр ціни
- Негативний фідбек → не повторюй
```

### 2. PromptModulesService

**Файл:** `app/Services/Ai/PromptModulesService.php`

Модульний фундамент промпта. Перевірені правила, що використовуються як TenantPromptGenerator-ом, так і напряму в BaseAgent як fallback.

**Модулі:**
| Метод | Що містить |
|-------|-----------|
| `getCoreModule()` | Головні правила: search_products(), максимум 3, формат intro, заборона посилань, замовлення |
| `getSearchModule(bool $hasAge)` | OR-синоніми, retry, сезонність, вікова фільтрація АБО заборона питати вік |
| `getFollowUpModule()` | Follow-up, негативний фідбек, питання про показаний товар |

### 3. PromptPresetService

**Файл:** `app/Services/Ai/PromptPresetService.php`

Завантажує та стакає промпти для тенанта.

**Ключові методи:**
| Метод | Опис |
|-------|------|
| `getSystemPromptForContext($tenantId, $context)` | Повертає final промпт (base + overlays merged) |
| `findLayersForContext($tenantId, $context)` | Розділяє на `['base' => ?preset, 'overlays' => [...]]` |
| `matchesOverlay($preset, $context)` | Overlay без фільтрів = матчить ЗАВЖДИ |

**Кешування:** 5 хв TTL per tenant, ключ `prompt_presets_active:{tenant_id}`

### 4. BaseAgent.getSystemPrompt()

**Файл:** `app/Services/Agent/BaseAgent.php`

Orchestration — збирає фінальний промпт:

```php
public function getSystemPrompt(): string
{
    $prefix = $this->getCriticalPrefix();   // Антигалюцинація
    $suffix = $this->getCriticalSuffix();   // Телефон, обмеження

    // 1. Спробувати PromptPresetService (per-tenant layered)
    $presetPrompt = $this->promptPresetService->getSystemPromptForContext(...);
    if ($presetPrompt) {
        return $prefix . $presetPrompt . $suffix;
    }

    // 2. Fallback: PromptModulesService (shared)
    $modules = app(PromptModulesService::class);
    return $prefix
        . $modules->getCoreModule()
        . $modules->getSearchModule($hasAge)
        . $modules->getFollowUpModule()
        . $suffix;
}
```

## Безпека saveAsPreset()

`TenantPromptGenerator::saveAsPreset()` має захист від перезапису кастомних промптів:

```
saveAsPreset($tenantId, $tenant, $prompt)
  │
  ├── Чи є кастомний is_default=true preset? (slug != "auto-generated-*")
  │     YES → зберігає авто як INACTIVE backup (is_default=false, is_active=false)
  │     NO  → шукає існуючий auto-generated, оновлює або створює новий як default
  │
  └── PromptPreset boot() hook: тільки ОДИН is_default=true на тенант
```

**Приклад T20 (bavkatoys):**
- Має кастомний BASE preset (ID=11, "БАЗОВИЙ пресет", 7595 chars)
- `generate(20)` → зберігає авто як inactive backup, НЕ чіпає кастомний

**Приклад T2 (Contractor):**
- Не мав presets
- `generate(2)` → створив auto-generated-2 як default (3788 chars)

## Автогенерація при Онбордингу

`OnboardTenantJob` запускає генерацію промпта як **крок 7** після Meili індексації:

```
horoshop_sync (25%) → categories_rebuild (10%) → brands_sync (5%)
  → ai_enrichment (40%) → meili_indexing (20%) → prompt_generation (5%)
```

**Чому після Meili?** Для генерації потрібні: категорії, бренди, ціни — дані з попередніх кроків.

## Diagnostic API

```bash
# Dry run (превью без збереження)
curl -X POST "https://aintento.laravel.cloud/api/diagnostic/generate-prompt/2?key=diagnostic_secret_key_2025&dry_run=1"

# Генерація зі збереженням
curl -X POST "https://aintento.laravel.cloud/api/diagnostic/generate-prompt/2?key=diagnostic_secret_key_2025"

# Перегляд presets тенанта
curl "https://aintento.laravel.cloud/api/diagnostic/prompt-presets?key=diagnostic_secret_key_2025&tenant_id=2"
```

**Відповідь:**
```json
{
  "preset_id": 17,
  "prompt_length": 3788,
  "analysis": {
    "store_name": "Contractor",
    "total_products": 1257,
    "in_stock_count": 728,
    "has_age_categories": false,
    "brands": {"АТАКА": 45, "HOFFMANN": 30, ...},
    "top_level_categories": {"Шеврони та патчі": 150, ...}
  }
}
```

## Prompt Presets Table

```sql
prompt_presets
├── id (PK)
├── tenant_id (nullable, indexed)
├── name
├── slug (unique) -- "auto-generated-{tenant_id}" для авто
├── system_prompt (text)
├── is_default (bool) -- тільки 1 per tenant
├── is_active (bool)
├── priority (int) -- для overlays ordering
├── categories (JSON) -- overlay filter
├── language, tone, campaign -- overlay filters
├── variables (JSON)
└── timestamps
```

## Приклад: Продакшн стан промптів

### T2 (Contractor / tactical store)
| ID | Slug | Default | Active | Priority | Chars |
|----|------|---------|--------|----------|-------|
| 17 | auto-generated-2 | ✅ | ✅ | 0 | 3788 |

### T20 (bavkatoys / children's store)
| ID | Slug | Default | Active | Priority | Chars | Опис |
|----|------|---------|--------|----------|-------|------|
| 11 | bazovii-preset | ✅ | ✅ | 50 | 7595 | Кастомний BASE "Гуся" |
| 14 | bavka-top-prodaziv | ❌ | ✅ | 70 | 1510 | Overlay: Топ продажів |
| 15 | bavka-razom-vigidnise | ❌ | ✅ | 75 | 3821 | Overlay: Комплекти |
| 12 | bavka-mebli | ❌ | ✅ | 90 | 1417 | Overlay: Меблі |
| 13 | skripti-castix-pitan | ❌ | ✅ | 95 | 4818 | Overlay: FAQ скрипти |
| 16 | auto-gusia | ❌ | ❌ | 10 | 1653 | Старий авто (inactive) |

## Файли

| Файл | Опис |
|------|------|
| `app/Services/Ai/TenantPromptGenerator.php` | Генерація промпта з каталогу |
| `app/Services/Ai/PromptModulesService.php` | Модульний фундамент промпта |
| `app/Services/Ai/PromptPresetService.php` | Завантаження та стакання presets |
| `app/Services/Agent/BaseAgent.php` | Orchestration getSystemPrompt() |
| `app/Models/PromptPreset.php` | Модель з boot() hook (1 default per tenant) |
| `app/Jobs/OnboardTenantJob.php` | Крок 7: generatePrompt() |
| `app/Console/Commands/GenerateTenantPrompt.php` | CLI: `php artisan tenant:generate-prompt {id}` |
| `tests/Feature/TenantPromptGeneratorTest.php` | 10 тестів |

*Last updated: 2026-03-16*

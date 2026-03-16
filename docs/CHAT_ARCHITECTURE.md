# Chat Architecture Documentation

## Overview

Ця документація описує архітектуру чат-бота AIntento для магазину тактичного спорядження.

> **⚠️ ВАЖЛИВО**: Є ДВА агенти! Widget за замовчуванням використовує **SSE streaming**.
> Див. таблицю нижче для вибору правильного агента при дебагу.

## ⚡ Швидка довідка: Два Агенти

| Endpoint | Агент | Файл | Коли використовується |
|----------|-------|------|-----------------------|
| `POST /api/chat` | `FunctionCallingAgent` | [FunctionCallingAgent.php](../app/Services/Agent/FunctionCallingAgent.php) | Fallback, тести |
| `GET /api/chat/stream` | `StreamingFunctionCallingAgent` | [StreamingFunctionCallingAgent.php](../app/Services/Agent/StreamingFunctionCallingAgent.php) | **Widget (SSE)** ← основний |

**Якщо чат не працює — перевіряй ОБИДВА агенти!**

## Flow Diagram (Актуальна архітектура)

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                              USER (Widget)                                       │
│                            public/widget.js                                      │
│                         sendMessageStreaming() ← SSE                             │
└─────────────────────────────────────────────────────────────────────────────────┘
                                      │
              ┌───────────────────────┴───────────────────────┐
              ▼ GET /api/chat/stream (SSE)                     ▼ POST /api/chat (fallback)
┌────────────────────────────────────────┐  ┌────────────────────────────────────────┐
│      StreamingChatController           │  │         ChatController                  │
│ app/Http/Controllers/Api/              │  │ app/Http/Controllers/Api/               │
│   StreamingChatController.php          │  │   ChatController.php                    │
└────────────────────────────────────────┘  └────────────────────────────────────────┘
              │                                          │
              ▼                                          ▼
┌────────────────────────────────────────┐  ┌────────────────────────────────────────┐
│  StreamingFunctionCallingAgent ⭐       │  │    FunctionCallingAgent                │
│  app/Services/Agent/                   │  │    app/Services/Agent/                  │
│    StreamingFunctionCallingAgent.php   │  │      FunctionCallingAgent.php           │
│                                        │  │                                          │
│  • stream() → Generator yields events  │  │  • handle() → returns array              │
│  • OpenAI function calling             │  │  • OpenAI function calling               │
│  • Real-time text chunks               │  │  • Full response at once                 │
│  • SSE format for widget               │  │  • JSON response                         │
└────────────────────────────────────────┘  └────────────────────────────────────────┘
              │                                          │
              └───────────────────┬───────────────────────┘
                                  ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                          OpenAI Function Calling                                 │
│                                                                                  │
│  Tools:                                                                          │
│  • search_products(query, filters) → MeiliProductSearchTool                      │
│  • get_product_details(article) → ProductDetailsTool                             │
│  • get_popular_products(category) → DB query                                     │
│  • get_order_status(phone/order_id) → OrderSearchService                         │
└─────────────────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                            Історія чату (DB)                                     │
│                                                                                  │
│  ChatSession - сесія з session_id                                                │
│  ChatMessage - повідомлення з role, content, meta                                │
│                                                                                  │
│  Маркер: [Показані товари: Назва (арт. XXX)]                                     │
│  GPT бачить історію і може відповідати з контексту                               │
└─────────────────────────────────────────────────────────────────────────────────┘
```

## ⚠️ Критичний момент: GPT відповідає без tool_calls

Коли GPT знає товар з історії, він може відповісти JSON **без виклику search_products**:

```json
{"intro": "Ось підсумок під турнікет:", "products": [{"article": "ab3-775", "comment": "..."}]}
```

**Обидва агенти повинні:**
1. Перевірити чи відповідь — JSON
2. Викликати `parseStructuredResponse()` для пошуку товарів в БД по артикулу
3. Повернути товари з images для відображення картки

## 🧠 System Prompt Architecture (Per-Tenant)

Кожен тенант має **персональний системний промпт**. Детально: [PROMPT_GENERATION_ARCHITECTURE.md](PROMPT_GENERATION_ARCHITECTURE.md)

### Стек промпта (в порядку пріоритету)

```
getCriticalPrefix()          ← антигалюцинація, ліміт 3 товари
  │
  ├── PromptPresetService    ← per-tenant layered presets
  │     ├── BASE (is_default=true) — кастомний АБО авто-згенерований
  │     └── OVERLAYS (priority DESC) — FAQ скрипти, категорійні, кампанійні
  │
  └── FALLBACK: PromptModulesService  ← якщо немає presets
        ├── getCoreModule()          ← правила пошуку, формат
        ├── getSearchModule($hasAge) ← синоніми, retry, вік дитини
        └── getFollowUpModule()      ← exclude_shown, негативний фідбек
  │
getCriticalSuffix()          ← телефон, обмеження
```

### Ключові сервіси

| Сервіс | Файл | Роль |
|--------|------|------|
| `TenantPromptGenerator` | `app/Services/Ai/TenantPromptGenerator.php` | Авто-генерація промпта з каталогу |
| `PromptModulesService` | `app/Services/Ai/PromptModulesService.php` | Модульний фундамент (core/search/follow-up) |
| `PromptPresetService` | `app/Services/Ai/PromptPresetService.php` | Завантаження та стакання presets |
| `BaseAgent.getSystemPrompt()` | `app/Services/Agent/BaseAgent.php` | Orchestration |

### Як `has_age_categories` впливає на промпт

| Тип магазину | `has_age_categories` | Ефект |
|-------------|---------------------|-------|
| Дитячий (bavkatoys) | `true` | Вікова фільтрація, питання про вік |
| Тактичний (Contractor) | `false` | `⛔ НЕ питай про вік дитини!` |

## Legacy: AgentOrchestrator (deprecated)

> **Застаріло!** `AgentOrchestrator` більше не використовується як головний шлях.
> Він залишається для backward compatibility, але нові фічі додаються в FunctionCallingAgent.

## Tools (актуальні)

Всі tools використовуються через OpenAI function calling в обох агентах:

| Tool | Файл | Опис |
|------|------|------|
| `search_products` | `app/Services/Agent/Tools/MeiliProductSearchTool.php` | Meili search з Eloquent fallback |
| `get_product_details` | `app/Services/Agent/Tools/ProductDetailsTool.php` | Повні картки з images |
| `get_popular_products` | вбудовано в агент | DB query по популярності |
| `get_order_status` | `app/Services/Agent/Tools/` | OrderSearchService |
| `get_available_sizes` | `app/Services/Agent/Tools/GetAvailableSizesTool.php` | Доступні розміри |

### Supporting tools (використовуються internal)

| Tool | Файл | Опис |
|------|------|------|
| `MeiliProductSearchTool` | `app/Services/Agent/Tools/` | Primary: Meili, Fallback: Eloquent LIKE |
| `DeduperTool` | `app/Services/Agent/Tools/` | Дедуплікація за назвою |
| `AiRerankTool` | `app/Services/Agent/Tools/` | AI ранжування |
| `AccessoryFilterTool` | `app/Services/Agent/Tools/` | Фільтр аксесуарів |
| `ProductDetailsTool` | `app/Services/Agent/Tools/` | Картки + extractImages() |

## Response Flow (функціональний агент)

```
User: "плитоноска до 5000"
           │
           ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│ 1. StreamingFunctionCallingAgent (або FunctionCallingAgent)                      │
│    ├── System prompt (per-tenant via PromptPresetService)                        │
│    ├── Chat history (ChatSession + ChatMessage)                                  │
│    └── OpenAI API call з tools definition                                        │
└─────────────────────────────────────────────────────────────────────────────────┘
           │
           ▼ GPT вирішує викликати tool
┌─────────────────────────────────────────────────────────────────────────────────┐
│ 2. search_products(query: "плитоноска", filters: {budget_max: 5000})            │
│    ├── MeiliProductSearchTool::search()                                          │
│    ├── filterByTitleRelevance() → stem-based relevance filter                    │
│    └── Return max 3 products з images                                            │
└─────────────────────────────────────────────────────────────────────────────────┘
           │
           ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│ 3. GPT генерує текстову відповідь з товарами                                     │
│    - intro: "Ось плитоноски до 5000 ₴:"                                          │
│    - products: [3 картки з images]                                               │
│    - outro: "Потрібна допомога з розміром?"                                       │
└─────────────────────────────────────────────────────────────────────────────────┘
           │
           ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│ 4. API Response                                                                  │
│    { type: "products", text: "...", products: [...], session_id, meta }           │
└─────────────────────────────────────────────────────────────────────────────────┘
           │
           ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│ 5. Widget (public/widget.js)                                                     │
│    ├── addBotMessage() → текстовий bubble                                        │
│    └── addProductCards() → картки товарів з фото                                 │
└─────────────────────────────────────────────────────────────────────────────────┘
```

## short_query_handler (bypass GPT)

Для 1-слівних запитів без дієслова (напр. "шоломи") — **GPT не викликається**:

```
User: "шоломи"
  → short_query_handler
    → MeiliProductSearchTool::search("шоломи")
    → filterByTitleRelevance()
    → max 3 products
    → Response: { type: "products", meta: { source: "short_query_handler" } }
```

## Session Context

| Сервіс | Файл | Зберігає |
|--------|------|----------|
| `ChatService` | `app/Services/Chat/ChatService.php` | session context (cache) |
| `SessionContextService` | `app/Services/Session/SessionContextService.php` | shown_products, last_query, budget |

## Key Methods Reference

### FunctionCallingAgent / StreamingFunctionCallingAgent (BaseAgent)

| Метод | Призначення |
|-------|-------------|
| `handle()` / `stream()` | Головна точка входу |
| `getSystemPrompt()` | Побудова per-tenant промпта |
| `loadConversationHistory()` | Завантаження історії з DB |
| `extractConversationContext()` | Контекст (категорії, розміри) |
| `parseStructuredResponse()` | Обробка JSON-відповідей GPT без tool_calls |
| `handleAgeQuery()` | Вікова фільтрація (bavkatoys) |
| `extractProductImages()` | Витягування картинок з raw |

### ProductDetailsTool

| Метод | Призначення |
|-------|-------------|
| `getCards()` | Повні дані товару з БД + images |
| `extractImages()` | Витягує картинки з raw/images поля |

---

*Last updated: March 2026*

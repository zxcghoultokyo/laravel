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

Код виправлення в `else` гілці (без tool_calls):
```php
$structured = $this->parseStructuredResponse($responseText, []);
if (!empty($structured['products'])) {
    // Повернути intro + products + outro
}
```

## Legacy: AgentOrchestrator (deprecated)

> **Застаріло!** `AgentOrchestrator` більше не використовується як головний шлях.
> Він залишається для backward compatibility, але нові фічі додаються в FunctionCallingAgent.

Див. [AGENT_ORCHESTRATOR.md](../AGENT_ORCHESTRATOR.md) для історичної довідки.
                                      │
                   ┌──────────────────┴──────────────────┐
                   ▼                                      ▼
┌─────────────────────────────────────┐  ┌─────────────────────────────────────────┐
│            TOOLS                     │  │              HANDLERS                    │
│    app/Services/Agent/Tools/         │  │       app/Services/Agent/Handlers/       │
│  ┌─────────────────────────────────┐ │  │  ┌─────────────────────────────────────┐ │
│  │ MeiliProductSearchTool          │ │  │  │ NarrativeBuilder                    │ │
│  │   → search() в Meilisearch      │ │  │  │   → buildProductNarrative()        │ │
│  │   → повертає candidates         │ │  │  │   → buildProductCards() ⭐         │ │
│  ├─────────────────────────────────┤ │  │  │   → generateFollowUpQuestion()     │ │
│  │ DeduperTool                     │ │  │  ├─────────────────────────────────────┤ │
│  │   → dedupe() видаляє дублі      │ │  │  │ OrderStatusHandler                 │ │
│  ├─────────────────────────────────┤ │  │  │   → handle() пошук замовлень       │ │
│  │ AiRerankTool                    │ │  │  ├─────────────────────────────────────┤ │
│  │   → rerank() AI ранжування      │ │  │  │ FaqHandler                         │ │
│  ├─────────────────────────────────┤ │  │  │   → handle() FAQ відповіді         │ │
│  │ ProductDetailsTool              │ │  │  ├─────────────────────────────────────┤ │
│  │   → getCards() повні дані + 🖼️  │ │  │  │ SmallTalkHandler                   │ │
│  │   → extractImages() картинки    │ │  │  │   → handle() привітання            │ │
│  ├─────────────────────────────────┤ │  │  └─────────────────────────────────────┘ │
│  │ AccessoryFilterTool             │ │  └─────────────────────────────────────────┘
│  │   → фільтр аксесуарів           │ │  
│  └─────────────────────────────────┘ │  
└─────────────────────────────────────┘  
                   │
                   ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                          SessionContextService                                   │
│                   app/Services/Session/SessionContextService.php                 │
│  ┌─────────────────────────────────────────────────────────────────────────────┐│
│  │ - loadContext() / saveContext()                                             ││
│  │ - Зберігає: shown_products, last_query, last_category, budget               ││
│  │ - Дозволяє follow-up: "а дешевше?", "чому ці?", "розкажи про них"           ││
│  └─────────────────────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────────────────────┘
```

## Response Flow for Products

```
User: "плитоноска до 5000"
           │
           ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│ 1. AgentOrchestrator::handleProductSearch()                                      │
│    ├── MeiliProductSearchTool::search() → 40 candidates (minimal fields)         │
│    ├── DeduperTool::dedupe() → remove similar                                    │
│    ├── AiRerankTool::rerank() → top 3-5 by relevance                             │
│    └── ProductDetailsTool::getCards() → FULL data + images ⭐                     │
└─────────────────────────────────────────────────────────────────────────────────┘
           │
           ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│ 2. NarrativeBuilder                                                              │
│    ├── buildProductNarrative() → короткий вступ: "Ось варіанти до 5000 ₴:"       │
│    └── buildProductCards() → масив карток з описами ⭐                            │
│        [                                                                         │
│          { description: "⭐ Популярний вибір", product: {..., images: [...]} },  │
│          { description: "⚖️ Збалансований", product: {...} },                    │
│          { description: "💎 Преміум варіант", product: {...} }                   │
│        ]                                                                         │
└─────────────────────────────────────────────────────────────────────────────────┘
           │
           ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│ 3. API Response (ChatService formats)                                            │
│    {                                                                             │
│      type: "products",                                                           │
│      text: "Ось варіанти до 5000 ₴:",                                            │
│      data: {                                                                     │
│        products: [...],           // для сумісності                              │
│        product_cards: [...]       // ⭐ НОВА СТРУКТУРА                            │
│      },                                                                          │
│      meta: { intent, chosen_ids, search_debug }                                  │
│    }                                                                             │
└─────────────────────────────────────────────────────────────────────────────────┘
           │
           ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│ 4. Widget Rendering (public/widget.js)                                           │
│    ├── addBotMessage() → текст "Ось варіанти до 5000 ₴:"                         │
│    └── addProductCards() ⭐ → для кожної картки:                                  │
│        ├── description bubble (сірий): "⭐ Популярний вибір"                     │
│        └── product card (link to site): title, price, image                      │
└─────────────────────────────────────────────────────────────────────────────────┘
```

## Widget Display Format (product_cards)

Коли є `product_cards`, віджет показує **кожен товар ОКРЕМО**:

```
┌─────────────────────────────────────┐
│ 🤖 Ось варіанти до 5000 ₴:          │  ← text bubble
└─────────────────────────────────────┘

┌─────────────────────────────────────┐
│ ⭐ Популярний вибір                  │  ← description bubble (gray)
└─────────────────────────────────────┘
┌─────────────────────────────────────┐
│ 🖼️ [Image]                          │
│ Плитоноска АТАКА Мультикам          │  ← product card (clickable)
│ 4 500 ₴                             │
│ ────────────────────────────────────│
│ contractor.kiev.ua →                │
└─────────────────────────────────────┘

┌─────────────────────────────────────┐
│ ⚖️ Збалансований вибір               │  ← description bubble (gray)
└─────────────────────────────────────┘
┌─────────────────────────────────────┐
│ 🖼️ [Image]                          │
│ Плитоноска Velmet Black             │  ← product card (clickable)
│ 4 800 ₴                             │
└─────────────────────────────────────┘
```

## Known Duplications & Issues

### ✅ DEPRECATED МЕТОДИ (делегують правильно)

| Метод | Делегує до | Статус |
|-------|------------|--------|
| `AgentOrchestrator.loadSessionContext()` | `sessionService->loadContext()` | ✅ Делегує |
| `AgentOrchestrator.saveSessionContext()` | `sessionService->saveContext()` | ✅ Делегує |
| `AgentOrchestrator.handleOrderStatus()` | `orderStatusHandler->handle()` | ✅ Делегує |
| `AgentOrchestrator.handleFaq()` | `faqHandler->handle()` | ✅ Делегує |
| `AgentOrchestrator.handleSmallTalk()` | `smallTalkHandler->handle()` | ✅ Делегує |
| `AgentOrchestrator.buildProductNarrative()` | `narrativeBuilder->buildProductNarrative()` | ✅ Делегує |

> **Примітка:** Ці методи збережені для зворотної сумісності. Вони є тонкими wrappers що викликають відповідні сервіси.

### ⚠️ ВЕЛИКІ ФАЙЛИ

- `AgentOrchestrator.php` — **1974 рядків** (потрібен рефакторинг)
- `NarrativeBuilder.php` — **914 рядків** (OK, окремий сервіс)

### ⚠️ TODO

1. **Винести ProductSearchHandler** — виділити `handleProductSearch()` в окремий Handler
2. **Винести FollowUpHandler** — об'єднати `handleWhyChosenFollowUp()`, `handleProductDetailsFollowUp()`
3. **Видалити deprecated методи** — після перевірки що все працює через Services

## Key Methods Reference

### AgentOrchestrator

| Метод | Призначення |
|-------|-------------|
| `handle()` | Головна точка входу |
| `createPlan()` | Аналіз повідомлення, визначення intent |
| `handleProductSearch()` | Пошук товарів (основний flow) |
| `handlePopularProductsRequest()` | "подарунок", "популярні" |
| `handleWhyChosenFollowUp()` | "чому саме ці?" → повертає product_cards |
| `handleProductDetailsFollowUp()` | "розкажи про них" |
| `buildWhyChosenCards()` | Формує картки з поясненням вибору |
| `buildWhyChosenReason()` | Короткий reason для кожного товару |

### NarrativeBuilder

| Метод | Призначення |
|-------|-------------|
| `buildProductNarrative()` | Короткий вступ ("Ось варіанти:") |
| `buildProductCards()` | ⭐ Масив карток з description + product |
| `buildProductCardDescription()` | Опис для кожної картки |
| `generateFollowUpQuestion()` | Уточнююче питання |

### ProductDetailsTool

| Метод | Призначення |
|-------|-------------|
| `getCards()` | Повні дані товару з БД включно з images |
| `extractImages()` | Витягує картинки з raw/images поля |

## Data Flow Summary

```
Message → Intent Detection → Search → Dedupe → Rerank → Get Details → Build Cards → Response
                                                              │
                                                              ▼
                                                    ProductDetailsTool::getCards()
                                                              │
                                                              ▼
                                              NarrativeBuilder::buildProductCards()
                                                              │
                                                              ▼
                                                    API: { product_cards: [...] }
                                                              │
                                                              ▼
                                                Widget: addProductCards() renders each
```

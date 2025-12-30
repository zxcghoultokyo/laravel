# Chat Architecture Documentation

## Overview

Ця документація описує архітектуру чат-бота AIntento для магазину тактичного спорядження.

## Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                              USER (Widget)                                       │
│                            public/widget.js                                      │
└─────────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼ POST /api/chat
┌─────────────────────────────────────────────────────────────────────────────────┐
│                         ChatController (API Entry)                               │
│                    app/Http/Controllers/Api/ChatController.php                   │
└─────────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                              ChatService                                         │
│                       app/Services/Chat/ChatService.php                          │
│  ┌─────────────────────────────────────────────────────────────────────────────┐│
│  │ - handleMessage() - головний метод                                          ││
│  │ - Логування повідомлень (user/assistant)                                    ││
│  │ - Формування фінальної відповіді для фронту                                 ││
│  │ - Session context management (legacy, use SessionContextService)            ││
│  └─────────────────────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                          AgentOrchestrator                                       │
│                  app/Services/Agent/AgentOrchestrator.php                        │
│  ┌─────────────────────────────────────────────────────────────────────────────┐│
│  │ 🎯 ГОЛОВНИЙ КООРДИНАТОР - вирішує що робити з повідомленням                 ││
│  │                                                                             ││
│  │ handle() → createPlan() → route to handler:                                 ││
│  │   • handleProductSearch() - пошук товарів                                   ││
│  │   • handleProductComparison() - порівняння                                  ││
│  │   • handlePopularProductsRequest() - популярні/подарунки                    ││
│  │   • handleWhyChosenFollowUp() - "чому саме ці?"                             ││
│  │   • handleProductDetailsFollowUp() - "розкажи про них"                      ││
│  │   • handleOrderStatus() - статус замовлення (@deprecated)                   ││
│  │   • handleFaq() - FAQ (@deprecated)                                         ││
│  │   • handleSmallTalk() - привітання (@deprecated)                            ││
│  └─────────────────────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────────────────────┘
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

# 🗺️ Roadmap & Progress Tracking

> **Остання оновлення**: 22.12.2025  
> **Версія проекту**: 1.0 Beta  
> **Статус**: Active Development

---

## 📋 Зміст
1. [Що Вже Зроблено](#що-вже-зроблено)
2. [В Процесі Розробки](#в-процесі-розробки)
3. [Backlog](#backlog)
4. [Майбутні Плани](#майбутні-плани)
5. [Technical Debt](#technical-debt)

---

## Що Вже Зроблено ✅

### Core Infrastructure (Sprint 0-1)
- ✅ **Laravel 12** project setup з dev container
- ✅ **Meilisearch** integration + indexing pipeline
- ✅ **OpenAI GPT-5.1** integration via AiRouter
- ✅ **Horoshop API** client + sync pipeline
- ✅ **Product model** з 30+ полями (title, price, brand, images, raw, ...)
- ✅ **Database migrations** для products, brands, categories
- ✅ **Eloquent fallback** для пошуку (якщо Meilisearch down)

---

### Agent Architecture (Sprint 2-3)
- ✅ **AgentOrchestrator** — головний контролер всього AI pipeline
- ✅ **5 Agent Tools**:
  - ✅ MeiliProductSearchTool (search + brand detection + accessory filtering)
  - ✅ DeduperTool (remove variant duplicates)
  - ✅ AccessoryFilterTool (downrank accessories)
  - ✅ AiRerankTool (AI-powered relevance scoring)
  - ✅ ProductDetailsTool (fetch full product cards)
- ✅ **Intent Classification** (product_search, order_status, faq, smalltalk, fallback)
- ✅ **Query Normalization** (clean user queries for Meilisearch)
- ✅ **Filter Extraction** (budget, color, camo з тексту)

---

### Search Quality (Sprint 4-5)
- ✅ **Brand Detection Service** — detects brands + 3x boosting
- ✅ **73 Brands** в БД (KOMBAT UK, АТАКА, HOFFMANN, EastGear, ...)
- ✅ **Strict Accessory Filtering** — камбербанд, кап, чохол, ремінь, ...
- ✅ **Context-Aware Filtering** — "панель для..." показує панелі
- ✅ **Dynamic Product Limit** — AI обирає 3-10 товарів (не завжди 10)
- ✅ **Popularity Ranking** — враховується в Meilisearch + AI reranking

---

### Background Jobs (Sprint 2)
- ✅ **SyncHoroshopProductsJob** — щоденна синхронізація о 03:00
- ✅ **IndexProductsToMeiliJob** — індексація в Meilisearch о 03:30
- ✅ **RebuildCategoryIndexJob** — rebuild categories таблиці о 03:20
- ✅ **SyncBrandsJob** — sync brands з products о 03:30
- ✅ **GenerateCategoryScenariosJob** — AI-generated scenarios (deprecated)

---

### Admin Tools (Sprint 3)
- ✅ **Manual sync endpoints** — `/api/admin/jobs/sync-horoshop`
- ✅ **Smoke tests** — `php artisan agent:smoke`
- ✅ **Test commands** — `php artisan test:search {query}`
- ✅ **Logging** — structured JSON logs з context
- ✅ **Error tracking** — graceful fallbacks на кожному рівні

---

### Documentation (Sprint 6) 🆕
- ✅ **Internal Wiki** — `/docs/wiki/` з 10+ документів
- ✅ **Search System Docs** — детальний опис pipeline
- ✅ **AI Integration Docs** — OpenAI usage patterns
- ✅ **Product Service Docs** — Horoshop sync flow
- ✅ **Known Issues Tracker** — всі баги і workarounds
- ✅ **Hardcoded Values** — що треба винести в БД
- ✅ **Roadmap** — цей документ 😊

---

## В Процесі Розробки 🚧

### Search Quality Improvements (Sprint 6)
- ✅ **AI Reranker Brand Priority** — комбінований фікс закомічено
  - **Status**: ✅ COMPLETED
  - **Завершено**: 22.12.2025
  - **Рішення**: Brand detection + filtering + AI prompt instruction

---

### Frontend Widget (Sprint 6-7)
- 🚧 **Widget Customization** — налаштування кольорів, fonts через UI
  - **Status**: Hardcoded в widget.js
  - **TODO**: Create widget_settings table + admin panel
  - **ETA**: 28.12.2025

---

## Backlog 📋

### P0 — Critical (Must Have Before Launch)

#### 1. FAQ Management System
**Чому критично**: FAQ захардкожені в коді, не можна змінити без редеплою

**Tasks**:
- [ ] Create `faqs` table (category, keywords, question, answer)
- [ ] Create FaqService
- [ ] Seed initial FAQ data (доставка, оплата, повернення)
- [ ] Update AgentOrchestrator to use FaqService
- [ ] Create admin panel для редагування FAQ

**Estimate**: 2 days  
**Priority**: 🔴 P0  
**ETA**: 26.12.2025

---

#### 2. Commit AI Reranker Brand Priority Fix
**Чому критично**: Brand search показує неправильний порядок

**Tasks**:
- [x] Write brand priority instructions in prompt (DONE)
- [ ] Test "hoffmann" search → verify HOFFMANN first
- [ ] Test "атака плитоноска" → verify АТАКА first
- [ ] Commit changes
- [ ] Deploy to production

**Estimate**: 4 hours  
**Priority**: 🔴 P0  
**ETA**: 23.12.2025

---

### P1 — High (Should Have Soon)

#### 3. Accessory Keywords Database
**Чому важливо**: Hardcoded keywords важко підтримувати

**Tasks**:
- [ ] Create `accessory_keywords` table
- [ ] Create AccessoryDetectionService
- [ ] Seed keywords з існуючого коду
- [ ] Refactor MeiliProductSearchTool to use service
- [ ] Refactor AccessoryFilterTool to use service
- [ ] Add admin UI для управління keywords

**Estimate**: 3 days  
**Priority**: 🟡 P1  
**ETA**: 30.12.2025

---

#### 4. Horoshop API Retry Logic
**Чому важливо**: Якщо API down → sync fails

**Tasks**:
- [ ] Implement exponential backoff (3 retries: 1s, 2s, 4s)
- [ ] Log кожну спробу
- [ ] Send alert якщо всі спроби failed
- [ ] Add circuit breaker pattern (future)

**Estimate**: 1 day  
**Priority**: 🟡 P1  
**ETA**: 02.01.2026

---

#### 5. OpenAI Classification Caching
**Чому важливо**: Економія API costs (~$5/month → ~$1/month для 1000 пошуків)

**Tasks**:
- [ ] Add Cache::remember() in AiRouter::classify()
- [ ] TTL 1 година
- [ ] Cache key: `classify:` + md5(message)
- [ ] Monitor cache hit rate

**Estimate**: 2 hours  
**Priority**: 🟡 P1  
**ETA**: 27.12.2025

---

### P2 — Medium (Nice to Have)

#### 6. Category Hints Database
**Чому корисно**: 50+ ліній hardcoded hints → БД

**Tasks**:
- [ ] Create `category_synonyms` table
- [ ] Migrate hints з ProductService
- [ ] Create CategorySynonymService
- [ ] Cache synonyms (TTL 1 година)

**Estimate**: 2 days  
**Priority**: 🟢 P2  
**ETA**: 05.01.2026

---

#### 7. Context Patterns Database
**Чому корисно**: Flexibility для тестування різних regex

**Tasks**:
- [ ] Create `context_patterns` table
- [ ] Seed існуючі patterns
- [ ] Load patterns dynamically в MeiliProductSearchTool
- [ ] Add admin UI для редагування

**Estimate**: 1 day  
**Priority**: 🟢 P2  
**ETA**: 08.01.2026

---

#### 8. AI Prompts Versioning
**Чому корисно**: A/B testing різних промптів

**Tasks**:
- [ ] Create `ai_prompts` table (name, version, prompt, is_active)
- [ ] Create PromptService
- [ ] Migrate існуючі промпти
- [ ] Track success_rate metrics

**Estimate**: 3 days  
**Priority**: 🟢 P2  
**ETA**: 12.01.2026

---

#### 9. Meilisearch Indexing Optimization
**Чому корисно**: 3 хвилини → 1-1.5 хвилини

**Tasks**:
- [ ] Збільшити batch size 500 → 1000
- [ ] Implement delta indexing (тільки змінені)
- [ ] Parallel indexing (multiple workers)

**Estimate**: 2 days  
**Priority**: 🟢 P2  
**ETA**: 15.01.2026

---

### P3 — Low (Future Enhancements)

#### 10. Multi-language Support
**Tasks**:
- [ ] Add locale field в products, faqs
- [ ] Detect user language в ChatService
- [ ] Return answers в правильній мові

**Estimate**: 5 days  
**Priority**: 🔵 P3  
**ETA**: Q1 2026

---

#### 11. Product Recommendations
**Tasks**:
- [ ] "Клієнти також дивились..."
- [ ] Collaborative filtering
- [ ] ML-based recommendations

**Estimate**: 2 weeks  
**Priority**: 🔵 P3  
**ETA**: Q1 2026

---

#### 12. Analytics Dashboard
**Tasks**:
- [ ] Search queries analytics
- [ ] Conversion tracking
- [ ] Popular products
- [ ] Abandoned searches

**Estimate**: 1 week  
**Priority**: 🔵 P3  
**ETA**: Q1 2026

---

## Майбутні Плани 🔮

### Q1 2026: Stability & Performance
- Виправити всі P0 баги
- Implement caching де можливо
- Performance optimization (query time < 500ms)
- Error rate < 1%

### Q2 2026: Feature Expansion
- Multi-language support (UA, EN, PL)
- Product recommendations
- Order tracking через widget
- Live chat з оператором

### Q3 2026: Analytics & ML
- Advanced analytics dashboard
- ML-based search ranking
- Personalization (враховувати історію користувача)
- A/B testing framework

### Q4 2026: Scale
- Support 10,000+ products
- Handle 1000+ concurrent users
- Multi-tenant architecture (для інших магазинів)
- White-label widget

---

## Technical Debt 💳

### High Priority Debt
1. **Duplicate Accessory Logic** — MeiliProductSearchTool + AccessoryFilterTool
   - **Impact**: Code smell, важко підтримувати
   - **Effort**: 4 hours
   - **Plan**: Refactor в Sprint 7

2. **Hardcoded FAQ** — AgentOrchestrator
   - **Impact**: Не можна змінити без редеплою
   - **Effort**: 2 days
   - **Plan**: Sprint 6 (в процесі)

3. **No Retry Logic** — HoroshopClient
   - **Impact**: Sync fails якщо API тимчасово down
   - **Effort**: 1 day
   - **Plan**: Sprint 7

---

### Medium Priority Debt
4. **Hardcoded Accessory Keywords** — 20+ ліній
5. **Category Hints in Code** — 50+ ліній
6. **No Classification Caching** — Waste of API calls

---

### Low Priority Debt
7. **No prompt versioning**
8. **Slow Meilisearch indexing** (можна оптимізувати)
9. **No A/B testing framework**

---

## Sprint Planning

### Sprint 6 (19.12 - 25.12) — In Progress
**Goal**: Documentation + Brand Priority Fix + API Cost Update

- [x] Create internal wiki structure
- [x] Write 10+ documentation files
- [x] Fix AI reranker brand priority
- [x] Update API costs for GPT-5.1
- [ ] Test brand priority on production
- [ ] Deploy to production

**Status**: 95% complete

---

### Sprint 7 (26.12 - 01.01) — Planned
**Goal**: FAQ Management + Critical Fixes

- [ ] FAQ table + service + seeder
- [ ] Update AgentOrchestrator to use FaqService
- [ ] Admin UI для FAQ management
- [ ] Horoshop retry logic
- [ ] OpenAI classification caching

**Status**: Not started

---

### Sprint 8 (02.01 - 08.01) — Planned
**Goal**: Accessory Keywords Database

- [ ] Create accessory_keywords table
- [ ] AccessoryDetectionService
- [ ] Refactor tools
- [ ] Admin UI
- [ ] Performance testing

**Status**: Not started

---

## Metrics & KPIs

### Current Performance
| Metric | Current | Target | Status |
|--------|---------|--------|--------|
| Search Response Time | 600-900ms | <500ms | 🟡 |
| AI API Cost/Search | $0.005 | <$0.003 | 🟡 |
| Search Success Rate | ~85% | >95% | 🟡 |
| Meilisearch Indexing Time | 3 min | <1.5 min | 🟢 |
| Error Rate | <2% | <1% | 🟢 |

### Quality Metrics
| Metric | Current | Target | Status |
|--------|---------|--------|--------|
| Brand Search Accuracy | ~70% | >95% | 🔴 |
| Accessory Filtering | ~90% | >95% | 🟢 |
| Intent Classification | ~95% | >98% | 🟢 |
| FAQ Coverage | 3 topics | >20 | 🔴 |

---

## Release Plan

### v1.0 Beta (Current)
- ✅ Core search working
- ✅ AI orchestrator
- ✅ Basic FAQ
- ⚠️ Brand search issues

**Status**: Live on production

---

### v1.1 (Target: 30.12.2025)
- ✅ FAQ management
- ✅ Brand search fixed
- ✅ Accessory keywords DB
- ✅ OpenAI caching

**Status**: In planning

---

### v1.2 (Target: 15.01.2026)
- Category hints DB
- Context patterns DB
- Horoshop retry logic
- Performance optimizations

**Status**: Backlog

---

### v2.0 (Target: Q1 2026)
- Multi-language support
- Product recommendations
- Advanced analytics
- ML-based ranking

**Status**: Future

---

## Contributor Guide

### How to Pick a Task
1. Check [Backlog](#backlog) section
2. Pick task based on your skills and priority
3. Create branch: `feature/TASK-NAME`
4. Implement + tests
5. Update this roadmap (move to "В Процесі")
6. Create PR

### Task Format
```markdown
#### Task Title
**Чому важливо**: Short explanation

**Tasks**:
- [ ] Subtask 1
- [ ] Subtask 2

**Estimate**: X days  
**Priority**: 🔴/🟡/🟢/🔵  
**ETA**: DD.MM.YYYY
```

---

**Попередній документ**: [← Hardcoded Values](hardcoded-values.md)  
**Наступний документ**: [Frontend Integration →](frontend-integration.md)

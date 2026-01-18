# 🔍 Конкурентний аналіз AI Chat-ботів для E-commerce

> **Дата:** Січень 2026  
> **Мета:** Визначити позиціонування та конкурентні переваги

---

## 📊 Глобальні конкуренти - Порівняльна таблиця

| Платформа | Стартова ціна | AI вартість | Повідомлень/місяць | UA підтримка | E-com фокус |
|-----------|---------------|-------------|-------------------|--------------|-------------|
| **Tidio** | $29/міс | Включено (Lyro) | 100 розмов | ✅ | ⭐⭐⭐ |
| **Intercom** | $39/seat | +$0.99/резолюція | Pay-per-use | ⚠️ | ⭐⭐ |
| **Zendesk** | $55/agent | +$50/agent addon | Unlimited | ✅ | ⭐⭐ |
| **Freshdesk** | $15/agent | Включено (Freddy) | Unlimited | ✅ | ⭐⭐ |
| **Gorgias** | $60/міс | +$0.50/ticket | 300 tickets | ✅ | ⭐⭐⭐⭐⭐ |
| **LiveChat** | $20/agent | +$52 ChatBot | 100-1000 | ✅ | ⭐⭐⭐ |
| **ManyChat** | $15/міс | Включено | 500 contacts | ✅ | ⭐⭐⭐ |
| **Botpress** | FREE | Включено GPT | 2,000/міс | ✅ | ⭐⭐ |
| **Ada** | ~$500+ | Pay-per-resolution | Custom | ⚠️ | ⭐⭐⭐ |
| **Drift** | $2,500+ | Включено | Custom | ❌ | ⭐⭐ |

### Вартість за 1000 повідомлень (приблизно):

| Платформа | Ціна/1K messages | Коментар |
|-----------|-----------------|----------|
| Botpress Free | **$0** | Ліміт 2K/місяць |
| ManyChat | ~$15 | Скалюється по контактах |
| Tidio Starter | ~$290 | 100 розмов = дорого |
| Gorgias + AI | ~$200+ | $60 base + AI tickets |
| Intercom | ~$99+ seat + резолюції | Дуже дорого |
| **AImbot (ми)** | **~$16** (1999₴/5000 msg) | Конкурентно! |

---

## 🇺🇦 Українські конкуренти

| Платформа | Ціна | AI | Особливості | Слабкості |
|-----------|------|-----|-------------|-----------|
| **SendPulse** 🇺🇦 | від 324₴ | ✅ GPT | Telegram/Viber, грн, локальна підтримка | Не e-com focused |
| **Leeloo.ai** 🇺🇦 | від $29 | ✅ | Sales funnels, UA ринок | Лідогенерація > support |
| **KeyCRM** 🇺🇦 | від 399₴ | ⚠️ Basic | CRM + месенджери | Бот примітивний |
| **Ringostat** 🇺🇦 | від 950₴ | ❌ | Call tracking + chat | Без AI |
| **BotsCrew** 🇺🇦 | від $5,000 | ✅ Custom | Enterprise проекти | Дорого, проектно |
| **Checkbox** 🇺🇦 | від 250₴ | ❌ | Telegram бот для ПРРО | Тільки фіскал |

### Детальніше про українських конкурентів:

#### 1. SendPulse (найближчий конкурент)
```
✅ Переваги:
- Український продукт, підтримка грн
- Telegram, Viber, WhatsApp, Instagram
- GPT інтеграція
- Безкоштовний план (500 підписників)

❌ Недоліки:
- НЕ e-commerce focused
- Немає пошуку по товарах
- Немає інтеграції з CMS магазинів
- Примітивні сценарії (без AI розуміння)
```

#### 2. Leeloo.ai
```
✅ Переваги:
- Український стартап
- AI для продажів
- Воронки лідогенерації

❌ Недоліки:
- Фокус на лідогенерацію, не підтримку
- Немає інтеграції з товарами
- Немає real-time streaming
```

#### 3. Gorgias (глобальний, але популярний в UA)
```
✅ Переваги:
- Найкращий для Shopify
- E-commerce native
- Revenue attribution

❌ Недоліки:
- Дорого ($60 base + $0.50/AI ticket)
- Тільки Shopify/BigCommerce
- Немає Horoshop/Prom.ua інтеграції
- Англомовний support
```

---

## 🚀 НАШІ КОНКУРЕНТНІ ПЕРЕВАГИ (AIntento)

### 1. 🎯 **Глибока E-commerce інтеграція**

| Фіча | Ми | Tidio | Gorgias | SendPulse |
|------|-----|-------|---------|-----------|
| Пошук по товарах AI | ✅ Meilisearch + GPT | ❌ | ⚠️ Basic | ❌ |
| Картки товарів в чаті | ✅ Rich cards | ❌ | ✅ | ❌ |
| Розміри/кольори/варіанти | ✅ Smart CTA | ❌ | ⚠️ | ❌ |
| Cross-sell рекомендації | ✅ AI-powered | ❌ | ⚠️ | ❌ |
| Horoshop інтеграція | ✅ Native | ❌ | ❌ | ❌ |
| Prom.ua sync | ✅ Planned | ❌ | ❌ | ❌ |

**Унікальність:** Бот РОЗУМІЄ товари магазину - шукає, фільтрує, рекомендує.

### 2. 🧠 **Справжній AI Agent (не сценарії)**

```
Конкуренти: Сценарії → IF/THEN → обмежені відповіді
Ми: GPT Function Calling → розуміє контекст → викликає tools
```

| Фіча | Ми | Конкуренти |
|------|-----|------------|
| GPT Function Calling | ✅ | Tidio Lyro (обмежено) |
| Streaming SSE | ✅ Real-time typing | ❌ або затримка |
| Context memory | ✅ Chat history | ⚠️ Session only |
| Multi-tool chains | ✅ Search→Filter→Rerank | ❌ |
| Fallback без AI | ✅ Keyword search | Зазвичай fail |

### 3. 📢 **Proactive Triggers (унікально!)**

| Тригер | Опис | Конкуренти |
|--------|------|------------|
| **Exit Intent** | Ловить коли йде з сайту | Intercom ($$$), Drift ($$$) |
| **Time on Page** | Довго дивиться товар | ❌ або платно |
| **Product PDP** | Пропонує допомогу на картці | ⚠️ Generic popup |
| **Category Browse** | Топ товари категорії | ❌ |
| **Cart Abandonment** | Recovery flow | Gorgias (платно) |

**Це ПРОДАЄ!** Не чекаємо питання - самі починаємо розмову.

### 4. 💰 **Ціна для UA ринку**

| План | Ми (AImbot) | Tidio | Gorgias | Intercom |
|------|-------------|-------|---------|----------|
| Starter | **799₴** (~$20) | $29 | $60 | $39/seat |
| Messages | 1,000 | 100 | 300 | Pay-per |
| Pro | **1,999₴** (~$50) | $59 | $360 | $99/seat |
| Messages | 5,000 | 250 | 2,000 | Pay-per |

**В 3-5 разів дешевше** при більшій кількості повідомлень!

### 5. 🇺🇦 **Локалізація**

| Аспект | Ми | Глобальні |
|--------|-----|-----------|
| Українська мова бота | ✅ Native | ⚠️ Переклад |
| Сленг/жаргон | ✅ Dictionary | ❌ |
| Horoshop/Prom.ua | ✅ | ❌ |
| WayForPay/LiqPay | ✅ | ❌ |
| Підтримка грн | ✅ | ⚠️ |
| Нова Пошта трекінг | ✅ Planned | ❌ |

### 6. 🔧 **Технічні переваги**

| Фіча | Реалізація | Конкуренти |
|------|------------|------------|
| **Meilisearch** | Typo-tolerant, instant | Elasticsearch (складно) |
| **SSE Streaming** | Real-time typing effect | Batch response |
| **Multi-tenant** | Повна ізоляція | Shared DB |
| **AI Reranking** | GPT сортує релевантність | Manual rules |
| **A/B Testing** | Built-in | Окремий сервіс |
| **Webhook notifications** | Telegram, Email | Email only |

---

## 📈 SWOT Аналіз

### Strengths (Сильні сторони)
- ✅ Глибока e-com інтеграція (товари, пошук, картки)
- ✅ Proactive triggers (exit intent, time on page)
- ✅ Справжній AI agent з function calling
- ✅ Українська локалізація
- ✅ Конкурентна ціна
- ✅ Streaming для UX

### Weaknesses (Слабкі сторони)
- ⚠️ Новий продукт (немає бренду)
- ⚠️ Тільки Horoshop поки що
- ⚠️ Немає mobile app
- ⚠️ Маленька команда

### Opportunities (Можливості)
- 🚀 UA e-commerce росте
- 🚀 Глобальні конкуренти дорогі
- 🚀 Prom.ua, Rozetka партнерства
- 🚀 Shopify/WooCommerce plugins

### Threats (Загрози)
- ⚡ SendPulse може додати e-com фічі
- ⚡ Глобальні гравці можуть знизити ціни
- ⚡ OpenAI API ціни можуть зрости

---

## 🎯 Позиціонування

### Головний меседж:
> **"AI-консультант для інтернет-магазину, який РОЗУМІЄ ваші товари"**

### Відмінність від конкурентів:

| Конкурент | Їх позиція | Наша перевага |
|-----------|------------|---------------|
| SendPulse | "Email + чат-боти" | Ми = e-com specialist |
| Tidio | "Live chat + AI" | Ми = глибша товарна інтеграція |
| Gorgias | "Shopify helpdesk" | Ми = UA платформи + дешевше |
| Intercom | "Customer platform" | Ми = e-com фокус + доступно |

### Цільова аудиторія:
1. **Primary:** UA інтернет-магазини на Horoshop (11,000+ магазинів)
2. **Secondary:** Prom.ua продавці, Shopify UA stores
3. **Future:** WooCommerce, OpenCart, custom

---

## 💡 Рекомендації для MVP Launch

### Must-have перед запуском:
1. ✅ Proactive triggers - **ГОТОВО**
2. ✅ Product search - **ГОТОВО**
3. ✅ Multi-tenant - **ГОТОВО**
4. ⚠️ Email notifications - **ТРЕБА**
5. ⚠️ Payment testing - **ТРЕБА**

### Маркетингові повідомлення:

**Для Horoshop магазинів:**
> "Ваш AI-продавець працює 24/7. Знає всі товари. Коштує менше ніж одна година живого консультанта на день."

**Для conversion:**
> "Proactive triggers збільшують конверсію на 15-30%. Бот сам починає розмову, коли клієнт готовий купити."

**Для ціни:**
> "799₴/міс замість $60+ у західних аналогів. Платіть в гривнях, підтримка українською."

---

## 📊 Pricing Comparison Chart

```
Monthly cost for 1,000 AI conversations:

Intercom:     ████████████████████████████ $990+ ($39 + $0.99×1000)
Gorgias:      ██████████████████ $560 ($60 + $0.50×1000)
Tidio:        ██████████████ $290 (Growth plan ~290 conv)
Ada:          ████████████████████ ~$800+ (enterprise)
LiveChat+Bot: ██████████████ $273 ($41 + $232 for 1K)
AImbot Pro:   █████ $50 (1,999₴ = 5,000 msg!)
Botpress:     ██ FREE (2K limit)
SendPulse:    ███ ~$16 (Pro план)
```

**Висновок:** Ми в категорії "affordable AI" поруч з SendPulse та Botpress, але з унікальними e-commerce фічами яких немає у них!

---

*Last updated: January 18, 2026*

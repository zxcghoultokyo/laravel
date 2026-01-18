# Proactive Triggers - Детальна специфікація

> Система проактивних повідомлень для збільшення конверсій

## Загальна архітектура

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│   widget.js     │────▶│  Trigger Engine  │────▶│  Display Layer  │
│  (Detection)    │     │  (Priority Logic)│     │  (UI Component) │
└─────────────────┘     └──────────────────┘     └─────────────────┘
        │                       │                        │
        │                       ▼                        │
        │              ┌──────────────────┐              │
        │              │   Admin Config   │              │
        │              │  (DB + Cache)    │              │
        │              └──────────────────┘              │
        │                       │                        │
        ▼                       ▼                        ▼
┌─────────────────────────────────────────────────────────────┐
│                    Analytics Tracking                        │
│  (trigger_shown, trigger_clicked, add_to_cart, purchase)    │
└─────────────────────────────────────────────────────────────┘
```

---

## Тригери - Детальний опис

### 1. 🚪 EXIT-INTENT (Вихід зі сторінки)

**Коли спрацьовує:**
- Курсор виходить за верхню межу viewport (≤5px від top)
- Швидкий scroll вгору (velocityY > 50px/100ms) на PDP/категорії
- Browser back button detection (popstate)
- Tab visibility change (document.hidden = true) на сторінці товару

**Умови блокування:**
- Вже показано інший trigger в цій сесії (frequency limit)
- Користувач вже в чаті (chatOpen = true)
- Менше 5 секунд на сторінці (bounce filter)
- Товар вже в кошику

**Налаштування в Admin:**
```
┌─────────────────────────────────────────────────────────────┐
│ 🚪 Exit-Intent Trigger                         [✓] Увімкнено │
├─────────────────────────────────────────────────────────────┤
│ Мінімальний час на сторінці: [___5___] секунд              │
│                                                             │
│ Текст повідомлення:                                         │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Йдете? Допоможу підібрати товар за 30 секунд!          │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ Текст кнопки: [____Підібрати____]                          │
│                                                             │
│ Показувати на:                                              │
│ [✓] Сторінка товару (PDP)                                   │
│ [✓] Категорія                                               │
│ [ ] Головна                                                 │
│ [ ] Кошик                                                   │
│                                                             │
│ Дія при кліку:                                              │
│ (•) Відкрити чат з контекстом поточного товару             │
│ ( ) Показати схожі товари                                   │
│ ( ) Відкрити чат з питанням про розмір                      │
└─────────────────────────────────────────────────────────────┘
```

**Технічна реалізація (widget.js):**
```javascript
// Exit-intent detection
document.addEventListener('mouseleave', (e) => {
    if (e.clientY <= 5 && canShowTrigger('exit_intent')) {
        showProactiveTrigger('exit_intent', {
            product: getCurrentProduct(), // from page context
            category: getCurrentCategory()
        });
    }
});

// Scroll velocity detection
let lastScrollY = 0;
let lastScrollTime = Date.now();
window.addEventListener('scroll', () => {
    const now = Date.now();
    const deltaY = lastScrollY - window.scrollY; // positive = scrolling up
    const deltaTime = now - lastScrollTime;
    const velocity = deltaY / deltaTime * 100;
    
    if (velocity > 50 && window.scrollY < 200 && canShowTrigger('exit_intent')) {
        showProactiveTrigger('exit_intent', { reason: 'fast_scroll_up' });
    }
    
    lastScrollY = window.scrollY;
    lastScrollTime = now;
});
```

---

### 2. ⏱️ TIME-ON-PAGE (Довго дивиться)

**Коли спрацьовує:**
- ≥40 секунд на сторінці товару БЕЗ дії (scroll, click)
- ≥60 секунд в категорії переглянувши 3+ товарів
- Idle detection: немає mouse movement 20+ секунд після 30с на сторінці

**Умови блокування:**
- Вже був scroll до "Add to Cart" (user engaged)
- Чат відкритий
- Показано інший trigger

**Налаштування в Admin:**
```
┌─────────────────────────────────────────────────────────────┐
│ ⏱️ Time-on-Page Trigger                       [✓] Увімкнено │
├─────────────────────────────────────────────────────────────┤
│ Час на сторінці товару: [___40___] секунд                  │
│ Час в категорії:        [___60___] секунд                  │
│ Мін. переглянутих товарів в категорії: [___3___]           │
│                                                             │
│ Текст (сторінка товару):                                    │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Підказати розмір за 3 питання?                          │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ Текст (категорія):                                          │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Показати хіти {{category}} в наявності?                  │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ Текст кнопки: [____Показати____]                           │
└─────────────────────────────────────────────────────────────┘
```

**Технічна реалізація:**
```javascript
class TimeOnPageTracker {
    constructor() {
        this.startTime = Date.now();
        this.lastActivity = Date.now();
        this.productViews = [];
        this.scrolledToCart = false;
        
        this.setupActivityTracking();
        this.startIdleCheck();
    }
    
    setupActivityTracking() {
        ['scroll', 'click', 'mousemove'].forEach(event => {
            document.addEventListener(event, () => {
                this.lastActivity = Date.now();
            }, { passive: true });
        });
        
        // Detect scroll to cart button
        const cartObserver = new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting) {
                this.scrolledToCart = true;
            }
        });
        const cartBtn = document.querySelector('[data-add-to-cart]');
        if (cartBtn) cartObserver.observe(cartBtn);
    }
    
    startIdleCheck() {
        setInterval(() => {
            const timeOnPage = (Date.now() - this.startTime) / 1000;
            const idleTime = (Date.now() - this.lastActivity) / 1000;
            
            if (this.isProductPage() && timeOnPage >= 40 && !this.scrolledToCart) {
                if (canShowTrigger('time_on_page')) {
                    showProactiveTrigger('time_on_page', {
                        type: 'product',
                        product: getCurrentProduct()
                    });
                }
            }
            
            if (this.isCategoryPage() && timeOnPage >= 60 && this.productViews.length >= 3) {
                if (canShowTrigger('time_on_page')) {
                    showProactiveTrigger('time_on_page', {
                        type: 'category',
                        category: getCurrentCategory(),
                        viewedCount: this.productViews.length
                    });
                }
            }
        }, 5000);
    }
}
```

---

### 3. 🏷️ UTM-BASED (Рекламна кампанія)

**Коли спрацьовує:**
- URL містить utm_campaign що відповідає налаштованому правилу
- Перша сторінка сесії (landing)
- Можна затримати на 10-15 секунд після landing

**Умови блокування:**
- Користувач вже почав чат
- Показано інший trigger

**Налаштування в Admin:**
```
┌─────────────────────────────────────────────────────────────┐
│ 🏷️ UTM Campaign Triggers                      [✓] Увімкнено │
├─────────────────────────────────────────────────────────────┤
│ + Додати правило                                            │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Кампанія #1                                    [Видалити]│ │
│ ├─────────────────────────────────────────────────────────┤ │
│ │ UTM Campaign містить: [___bf_2025___]                   │ │
│ │ UTM Source (опц.):    [___facebook___]                  │ │
│ │                                                         │ │
│ │ Повідомлення:                                           │ │
│ │ ┌─────────────────────────────────────────────────────┐ │ │
│ │ │ 🔥 Black Friday: знижки до -40%! Показати топ?       │ │ │
│ │ └─────────────────────────────────────────────────────┘ │ │
│ │                                                         │ │
│ │ Фільтр товарів для чату:                                │ │
│ │ Категорія: [▼ Куртки зимові___]                        │ │
│ │ Макс. ціна: [___2000___] грн                           │ │
│ │                                                         │ │
│ │ Затримка показу: [___10___] секунд                     │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Кампанія #2                                    [Видалити]│ │
│ ├─────────────────────────────────────────────────────────┤ │
│ │ UTM Campaign містить: [___winter_jackets___]            │ │
│ │ ...                                                     │ │
│ └─────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

**DB Schema:**
```sql
CREATE TABLE proactive_trigger_rules (
    id BIGINT PRIMARY KEY,
    trigger_type ENUM('exit_intent', 'time_on_page', 'utm_campaign', 'returning_visitor', 'pdp_no_variant'),
    is_enabled BOOLEAN DEFAULT true,
    priority INT DEFAULT 100, -- lower = higher priority
    
    -- Conditions (JSON)
    conditions JSON, -- {"utm_campaign": "bf%", "utm_source": "facebook", "min_time": 10}
    
    -- Display
    message TEXT,
    button_text VARCHAR(50),
    
    -- Action
    action_type ENUM('open_chat', 'open_chat_with_context', 'show_products'),
    action_config JSON, -- {"category": "jackets", "price_max": 2000, "query": "зимові куртки"}
    
    -- Stats
    shown_count INT DEFAULT 0,
    clicked_count INT DEFAULT 0,
    
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

**Технічна реалізація:**
```javascript
class UTMTriggerMatcher {
    constructor(rules) {
        this.rules = rules; // loaded from API
    }
    
    checkOnPageLoad() {
        const params = new URLSearchParams(window.location.search);
        const utm = {
            campaign: params.get('utm_campaign'),
            source: params.get('utm_source'),
            medium: params.get('utm_medium'),
            content: params.get('utm_content')
        };
        
        // Find matching rule
        const matchedRule = this.rules.find(rule => {
            if (!rule.conditions.utm_campaign) return false;
            
            const campaignMatch = utm.campaign && 
                utm.campaign.toLowerCase().includes(rule.conditions.utm_campaign.toLowerCase());
            
            const sourceMatch = !rule.conditions.utm_source || 
                (utm.source && utm.source.toLowerCase() === rule.conditions.utm_source.toLowerCase());
            
            return campaignMatch && sourceMatch;
        });
        
        if (matchedRule && canShowTrigger('utm_campaign')) {
            const delay = (matchedRule.conditions.min_time || 10) * 1000;
            setTimeout(() => {
                showProactiveTrigger('utm_campaign', {
                    rule: matchedRule,
                    utm: utm
                });
            }, delay);
        }
    }
}
```

---

### 4. 🔄 RETURNING VISITOR (Повернувся)

**Коли спрацьовує:**
- localStorage має `lastVisit` > 24 годин тому
- Користувач заходить на ту саму категорію
- Є збережені `viewedProducts` з минулого візиту

**Умови блокування:**
- Перший візит (немає lastVisit)
- Різниця < 24 години
- Інша категорія

**Налаштування в Admin:**
```
┌─────────────────────────────────────────────────────────────┐
│ 🔄 Returning Visitor Trigger                  [✓] Увімкнено │
├─────────────────────────────────────────────────────────────┤
│ Мін. час між візитами: [___24___] годин                    │
│                                                             │
│ Повідомлення:                                               │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Раді бачити знову! Показати товари з минулого візиту    │ │
│ │ та нові надходження?                                    │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ Текст кнопки: [____Показати____]                           │
│                                                             │
│ При кліку показати:                                         │
│ [✓] Раніше переглянуті товари                              │
│ [✓] Нові товари в категорії                                │
│ [✓] Знижки на переглянуті                                  │
└─────────────────────────────────────────────────────────────┘
```

---

### 5. 🎯 PDP NO VARIANT (Не обрав варіант)

**Коли спрацьовує:**
- На сторінці товару з варіантами (розмір/колір)
- ≥30 секунд без вибору варіанту
- Scroll до опису/характеристик (shows interest)

**Умови блокування:**
- Варіант вже обрано
- Товар в кошику

**Налаштування в Admin:**
```
┌─────────────────────────────────────────────────────────────┐
│ 🎯 PDP No Variant Trigger                     [✓] Увімкнено │
├─────────────────────────────────────────────────────────────┤
│ Час без вибору варіанту: [___30___] секунд                 │
│                                                             │
│ Повідомлення:                                               │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Не впевнені з розміром? Підберу за 3 питання!           │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ Текст кнопки: [____Підібрати розмір____]                   │
│                                                             │
│ Дія: відкрити чат з контекстом товару + запуск size quiz   │
└─────────────────────────────────────────────────────────────┘
```

---

## Пріоритети тригерів (за замовчуванням)

| Пріоритет | Тригер | Причина |
|-----------|--------|---------|
| 1 (найвищий) | Exit-Intent | Останній шанс утримати |
| 2 | PDP No Variant | Конкретна проблема з вибором |
| 3 | UTM Campaign | Узгодженість з рекламою |
| 4 | Time-on-Page | Загальна допомога |
| 5 (найнижчий) | Returning Visitor | Welcome back |

---

## Frequency Limiting

```javascript
const TRIGGER_LIMITS = {
    per_session: 1,        // Максимум 1 trigger на сесію
    per_day: 3,            // Максимум 3 на день (для returning visitors)
    cooldown_minutes: 30,  // Мінімум 30 хв між triggers
};

function canShowTrigger(triggerType) {
    const state = JSON.parse(localStorage.getItem('proactive_triggers') || '{}');
    
    // Check session limit
    if (state.sessionShown >= TRIGGER_LIMITS.per_session) {
        return false;
    }
    
    // Check daily limit
    const today = new Date().toDateString();
    if (state.lastDate === today && state.dailyShown >= TRIGGER_LIMITS.per_day) {
        return false;
    }
    
    // Check cooldown
    if (state.lastShownAt) {
        const minutesSince = (Date.now() - state.lastShownAt) / 60000;
        if (minutesSince < TRIGGER_LIMITS.cooldown_minutes) {
            return false;
        }
    }
    
    // Check if chat is already open
    if (window.AimbotWidget?.isOpen) {
        return false;
    }
    
    return true;
}
```

---

## Analytics Tracking

**Events to track:**
```javascript
// Trigger shown
trackEvent('proactive_trigger_shown', {
    trigger_type: 'exit_intent',
    page_type: 'pdp',
    product_id: '12345',
    time_on_page: 45
});

// Trigger clicked
trackEvent('proactive_trigger_clicked', {
    trigger_type: 'exit_intent',
    action: 'open_chat'
});

// Conversion funnel
trackEvent('proactive_trigger_conversion', {
    trigger_type: 'exit_intent',
    step: 'add_to_cart', // or 'purchase'
    product_id: '12345',
    value: 1500
});
```

**Admin Dashboard Metrics:**
```
┌─────────────────────────────────────────────────────────────┐
│ 📊 Proactive Triggers Performance (Last 7 days)             │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│ Trigger         | Shows | Clicks | CTR  | Cart | Purchases │
│ ─────────────────────────────────────────────────────────── │
│ Exit-Intent     |  450  |   89   | 20%  |  34  |    12     │
│ Time-on-Page    |  280  |   45   | 16%  |  18  |     6     │
│ UTM Campaign    |  120  |   38   | 32%  |  22  |     9     │
│ PDP No Variant  |  190  |   52   | 27%  |  28  |    11     │
│ Returning       |   85  |   19   | 22%  |   8  |     3     │
│ ─────────────────────────────────────────────────────────── │
│ TOTAL           | 1125  |  243   | 22%  | 110  |    41     │
│                                                             │
│ 💰 Revenue from triggers: ₴ 123,450                         │
│ 📈 Conversion rate: 3.6% (vs 2.1% without triggers)        │
└─────────────────────────────────────────────────────────────┘
```

---

## UI Components

### Bubble Trigger (поза чатом)
```
┌──────────────────────────────────────────┐
│ 🎯 Не впевнені з розміром?               │
│    Підберу за 3 питання!                 │
│                                          │
│    [Підібрати]        [✕]               │
└──────────────────────────────────────────┘
```

### In-Chat Trigger (якщо чат відкритий)
```
┌──────────────────────────────────────────┐
│ 💡 Бачу, дивитесь куртки вже хвилину.    │
│    Показати хіти в наявності?            │
│                                          │
│    [Так, покажи]                         │
└──────────────────────────────────────────┘
```

---

## Implementation Phases

### Phase 1 (Week 1): Core Infrastructure ✅ COMPLETED
- [x] DB migration for `proactive_trigger_rules` and `proactive_trigger_events`
- [x] API endpoints for rules (GET /api/triggers/rules, POST /api/triggers/event, POST /api/triggers/check, GET /api/triggers/stats)
- [x] Basic widget.js trigger engine (ProactiveTriggers object)
- [x] Exit-intent detection
- [x] Seeder with 16 default rules (all UTM sources)

### Phase 2 (Week 2): Admin UI + More Triggers ✅ COMPLETED
- [x] Livewire admin page for trigger management (`/admin/triggers`)
- [x] Time-on-page trigger
- [x] UTM campaign triggers (Google CPC, Shopping, Facebook, Instagram, TikTok, Telegram, Email)
- [x] Analytics tracking (shown_count, clicked_count, converted_count)
- [x] Returning visitor trigger
- [x] PDP no variant trigger

### Phase 3 (Week 3): Advanced Features 🔄 IN PROGRESS
- [ ] A/B testing for trigger messages
- [ ] Performance dashboard with charts
- [ ] Trigger scheduling (time-of-day, day-of-week)
- [ ] Advanced targeting (geo, device type)

## Admin UI

Access admin panel at `/admin/triggers`:

- **List View**: See all triggers with type, priority, statistics (shown, clicked, CTR)
- **Create/Edit**: Configure trigger type, conditions, message, button, action
- **Toggle**: Enable/disable triggers with one click
- **Duplicate**: Clone existing triggers for A/B testing
- **Reset Stats**: Clear statistics for a trigger
- **Filter**: By trigger type or enabled status

# Polymarket Prediction Bot — Project Specification

## Що це

Python-бот який автоматично сканує маркети на Polymarket, оцінює ймовірності через LLM (Claude Opus 4.6 / GPT-5.4), знаходить маркети де ринкова ціна відрізняється від реальної ймовірності (edge), і робить ставки за формулою Kelly Criterion. Має Streamlit дашборд для аналітики.

## Архітектура

```
polymarket-bot/
├── bot.py                # Головний цикл: scan → evaluate → bet
├── config.py             # Конфігурація (API ключі через env vars)
├── db.py                 # SQLite: створення таблиць, CRUD операції
├── evaluator.py          # LLM запити для оцінки ймовірностей
├── market_scanner.py     # Отримання маркетів з Polymarket API
├── kelly.py              # Kelly Criterion розрахунки
├── analytics.py          # Brier score, калібрація, P&L аналітика
├── dashboard.py          # Streamlit веб-дашборд
├── requirements.txt      # Python залежності
├── Procfile              # Railway: які процеси запускати
├── railway.toml          # Railway конфігурація
├── .env.example          # Приклад env-файлу
├── .gitignore            # Ігнор файли
└── README.md             # Документація
```

## Технічний стек

- **Python 3.11+**
- **py-clob-client** — офіційний Polymarket CLOB API клієнт
- **anthropic** — Claude Opus 4.6 API
- **openai** — GPT-5.4 API (опційно, для ensemble)
- **streamlit** — веб-дашборд (zero HTML/CSS/JS)
- **pandas** — аналітика, таблиці
- **plotly** — графіки в дашборді
- **schedule** — планувальник задач
- **httpx** — HTTP клієнт для додаткових запитів
- **python-dotenv** — .env файли
- **sqlite3** — вбудована БД (стандартна бібліотека Python)

## Деплой

- **Платформа:** Railway.app
- **Два процеси:** bot (background worker) + web (Streamlit dashboard)
- **БД:** SQLite у Railway volume (persistent storage)
- **Змінні:** всі API ключі через Railway environment variables

---

## Детальна специфікація кожного файлу

### 1. `config.py`

Конфігурація завантажується з environment variables через python-dotenv.

```python
"""
Змінні середовища (обов'язкові):
- ANTHROPIC_API_KEY — ключ Anthropic API для Claude
- POLYMARKET_API_KEY — API ключ Polymarket (з акаунта)
- POLYMARKET_SECRET — секрет Polymarket
- POLYMARKET_PASSPHRASE — passphrase Polymarket

Змінні середовища (опційні):
- OPENAI_API_KEY — для GPT-5.4 ensemble (якщо використовується)
- DRY_RUN — "true" для симуляції без реальних ставок (default: true)
- KELLY_FRACTION — частка Kelly для консервативності (default: 0.25)
- MIN_EDGE — мінімальний edge для ставки (default: 0.05 = 5%)
- MAX_BET — максимальна ставка в USD (default: 10.0)
- SCAN_INTERVAL_MINUTES — як часто сканувати маркети (default: 60)
- LLM_MODEL — яку модель використовувати: "opus" або "gpt" або "ensemble" (default: "opus")
- LOG_LEVEL — рівень логування (default: "INFO")
- DATABASE_PATH — шлях до SQLite файлу (default: "predictions.db")
"""
```

**Важливо:**
- `DRY_RUN = true` по дефолту — бот НІКОЛИ не ставить реальні гроші поки явно не перемкнути
- Валідація: якщо обов'язкові ключі відсутні — бот не стартує, кидає зрозумілу помилку

---

### 2. `db.py`

SQLite база з 3 таблицями + 1 view.

**Таблиці:**

```sql
-- Маркети які ми проаналізували
CREATE TABLE IF NOT EXISTS markets (
    id TEXT PRIMARY KEY,              -- Polymarket condition_id
    question TEXT NOT NULL,           -- "Who will win the 2025 Polish election?"
    description TEXT,                 -- повний опис маркету
    category TEXT,                    -- politics / sports / crypto / science / other
    outcomes TEXT,                    -- JSON список outcomes: ["Yes", "No"] або ["Trump", "Biden", "DeSantis"]
    end_date TEXT,                    -- ISO datetime коли маркет закриється
    outcome TEXT,                     -- NULL поки відкритий, потім результат
    resolved_at TEXT,                 -- коли маркет був вирішений
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
);

-- Кожна оцінка від LLM
CREATE TABLE IF NOT EXISTS predictions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    market_id TEXT NOT NULL REFERENCES markets(id),
    model TEXT NOT NULL,              -- "claude-opus-4.6" / "gpt-5.4" / "ensemble"
    our_probability REAL NOT NULL,    -- наша оцінка: 0.65
    market_probability REAL NOT NULL, -- ринкова ціна на момент оцінки: 0.40
    edge REAL NOT NULL,               -- різниця: 0.25 (our - market)
    confidence TEXT,                  -- "high" / "medium" / "low" — самооцінка моделі
    reasoning TEXT,                   -- повне пояснення моделі (зберігаємо для аналізу!)
    raw_response TEXT,                -- сирий JSON відповіді LLM
    tokens_used INTEGER,              -- скільки токенів витратили
    cost_usd REAL,                    -- вартість запиту в USD
    created_at TEXT DEFAULT (datetime('now'))
);

-- Ставки (реальні або симуляції)
CREATE TABLE IF NOT EXISTS bets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    prediction_id INTEGER NOT NULL REFERENCES predictions(id),
    market_id TEXT NOT NULL REFERENCES markets(id),
    side TEXT NOT NULL,               -- "Yes" / "No" або конкретний outcome
    amount REAL NOT NULL,             -- скільки поставили (USD)
    entry_price REAL NOT NULL,        -- за якою ціною купили: 0.40
    kelly_fraction REAL NOT NULL,     -- який Kelly fraction використали
    kelly_raw REAL,                   -- raw Kelly без обмежень (для аналітики)
    is_simulation INTEGER DEFAULT 1,  -- 1 = dry run, 0 = реальна ставка
    result TEXT,                      -- NULL (відкрита) / "win" / "loss"
    pnl REAL,                         -- прибуток/збиток після resolution
    resolved_at TEXT,                 -- коли маркет закрився
    created_at TEXT DEFAULT (datetime('now'))
);
```

**View для аналітики:**

```sql
CREATE VIEW IF NOT EXISTS model_performance AS
SELECT 
    p.model,
    COUNT(*) as total_predictions,
    SUM(CASE WHEN b.result = 'win' THEN 1 ELSE 0 END) as wins,
    SUM(CASE WHEN b.result = 'loss' THEN 1 ELSE 0 END) as losses,
    ROUND(AVG(CASE WHEN b.result IS NOT NULL THEN 
        CASE WHEN b.result = 'win' THEN 1.0 ELSE 0.0 END 
    END) * 100, 1) as win_rate_pct,
    ROUND(SUM(CASE WHEN b.pnl IS NOT NULL THEN b.pnl ELSE 0 END), 2) as total_pnl,
    ROUND(AVG(p.edge) * 100, 1) as avg_edge_pct,
    ROUND(AVG(p.cost_usd), 4) as avg_cost_per_prediction,
    ROUND(SUM(p.cost_usd), 2) as total_llm_cost
FROM predictions p
LEFT JOIN bets b ON b.prediction_id = p.id
GROUP BY p.model;
```

**Функції модуля `db.py`:**
- `init_db()` — створити таблиці якщо не існують
- `save_market(market_data)` — upsert маркету
- `save_prediction(prediction_data)` — зберегти оцінку
- `save_bet(bet_data)` — зберегти ставку
- `get_open_bets()` — ставки без результату (треба чекати resolution)
- `resolve_bet(bet_id, result, pnl)` — записати результат ставки
- `get_model_performance(model=None)` — статистика по моделі
- `get_calibration_data(model)` — дані для калібраційного графіку
- `get_pnl_by_day()` — P&L по днях для графіку
- `get_category_stats()` — статистика по категоріях маркетів
- `get_recent_bets(limit=50)` — останні ставки для таблиці

---

### 3. `market_scanner.py`

Отримує активні маркети з Polymarket CLOB API.

**Логіка:**
1. Запит до Polymarket API: отримати всі активні маркети
2. Фільтрація:
   - Тільки маркети що закриваються через 1-90 днів (не надто скоро, не надто далеко)
   - Тільки з достатньою ліквідністю (volume > $1000)
   - Виключити маркети які ми вже оцінили менше ніж 24 години тому (перевірка в БД)
3. Категоризація: визначити категорію маркету (politics/sports/crypto/science/other) за ключовими словами в назві
4. Повернути список маркетів для оцінки

**Polymarket CLOB API endpoints:**
- Base URL: `https://clob.polymarket.com`
- `GET /markets` — список маркетів
- `GET /book` — order book для конкретного маркету (ціна = ймовірність)

**Формат маркету (що зберігаємо):**
```python
{
    "id": "0x...",           # condition_id
    "question": "Will X happen?",
    "description": "Full description...",
    "outcomes": ["Yes", "No"],
    "end_date": "2025-06-01T00:00:00Z",
    "market_prices": {"Yes": 0.42, "No": 0.58},  # поточні ціни
    "volume": 50000,
    "liquidity": 12000
}
```

---

### 4. `evaluator.py`

Модуль для оцінки ймовірностей через LLM.

**Промпт-стратегія (КРИТИЧНО ВАЖЛИВО):**

Кожен маркет оцінюється ОКРЕМИМ запитом. Промпт НЕ згадує ставки, гроші, Polymarket чи букмекерів. Це чисте аналітичне питання.

**Шаблон промпту:**

```
You are a world-class forecaster and probability estimator. Your task is to 
estimate the probability of specific outcomes for a given question.

QUESTION: {market_question}

ADDITIONAL CONTEXT: {market_description}

POSSIBLE OUTCOMES: {outcomes}

DEADLINE: {end_date}

TODAY'S DATE: {current_date}

Instructions:
1. Consider all available evidence, historical precedents, and current trends
2. Think about base rates for similar events
3. Consider factors that could go either way
4. Be well-calibrated: when you say 70%, events should actually happen ~70% of the time
5. Express genuine uncertainty — don't be overconfident

For each outcome, provide:
- Your estimated probability (must sum to 1.0 across all outcomes)
- Confidence level: "high" (you have strong evidence), "medium" (reasonable basis), "low" (mostly guessing)
- Key reasoning (2-3 bullet points per outcome)

Respond in JSON format:
{
  "outcomes": {
    "Yes": {
      "probability": 0.65,
      "confidence": "medium",
      "reasoning": ["point 1", "point 2"]
    },
    "No": {
      "probability": 0.35,
      "confidence": "medium", 
      "reasoning": ["point 1", "point 2"]
    }
  },
  "key_uncertainties": ["uncertainty 1", "uncertainty 2"],
  "information_quality": "medium"
}
```

**Моделі:**

1. **Claude Opus 4.6** (primary) — через anthropic SDK, з extended thinking увімкненим
2. **GPT-5.4** (secondary) — через openai SDK
3. **Ensemble** — обидві моделі, результат = середнє зважене:
   - Якщо обидві "high" confidence → просте середнє
   - Якщо одна "low" → більша вага іншій
   - Якщо різниця > 0.20 — флаг "disagreement", не ставити

**Парсинг відповіді:**
- Витягнути JSON з тексту (regex для ```json...``` блоку)
- Валідація: ймовірності сумуються до 1.0 (±0.02)
- Якщо не парситься — retry один раз
- Підрахунок tokens_used і cost_usd для аналітики

**Вартість (приблизна на березень 2026):**
- Claude Opus 4.6: ~$15/M input, ~$75/M output tokens
- GPT-5.4: перевірити актуальну ціну на openai.com

---

### 5. `kelly.py`

Kelly Criterion для розрахунку розміру ставки.

**Формула:**
```
f* = (b * p - q) / b

де:
  f* = частка банкролу для ставки
  b  = odds (виплата / ставка). На Polymarket: (1 - entry_price) / entry_price
  p  = наша оцінка ймовірності
  q  = 1 - p
```

**Приклад:**
- Ринкова ціна: $0.40 (ринок думає 40%)
- Наша оцінка: 0.65 (ми думаємо 65%)
- b = (1 - 0.40) / 0.40 = 1.5
- f* = (1.5 * 0.65 - 0.35) / 1.5 = 0.4167

**Обмеження (ОБОВ'ЯЗКОВІ):**
1. `kelly_fraction` — множник для консервативності (default 0.25, тобто ¼ Kelly)
2. `max_bet` — абсолютний максимум в USD (default $10)
3. `min_edge` — мінімальний edge для ставки (default 5%)
4. Якщо Kelly від'ємний → не ставити (edge на іншу сторону)
5. Якщо confidence = "low" → пропустити

**Функції:**
- `calculate_kelly(our_prob, market_price, fraction=0.25)` → kelly_raw, kelly_adjusted, bet_amount
- `should_bet(edge, confidence, kelly)` → bool + причина якщо ні

---

### 6. `bot.py`

Головний цикл бота.

**Алгоритм:**

```
Кожні SCAN_INTERVAL_MINUTES хвилин:
1. SCAN: market_scanner.get_active_markets()
   → Отримати активні маркети з Polymarket
   → Фільтрувати по ліквідності, часу, дублікатам
   
2. EVALUATE: для кожного маркету
   → evaluator.evaluate_market(market) 
   → Отримати ймовірності від LLM
   → Зберегти prediction в БД
   
3. DECIDE: для кожного prediction з edge > MIN_EDGE
   → kelly.calculate_kelly(our_prob, market_price)
   → kelly.should_bet(edge, confidence, kelly)
   
4. BET (або DRY RUN):
   → Якщо DRY_RUN=true: зберегти bet з is_simulation=1
   → Якщо DRY_RUN=false: виконати ордер через py-clob-client, зберегти з is_simulation=0
   
5. RESOLVE: перевірити всі відкриті ставки
   → Чи маркет вже resolved?
   → Якщо так → записати result (win/loss) і pnl

6. LOG: написати в лог summary раунду
   → Скільки маркетів просканували
   → Скільки оцінили
   → Скільки ставок зробили
   → Поточний P&L
```

**Важливо:**
- Graceful shutdown: обробити SIGTERM/SIGINT
- Error handling: якщо LLM API впав — пропустити маркет, не крашити бот
- Rate limiting: не більше 10 запитів до LLM за хвилину
- Логування: кожна дія логується з timestamp

---

### 7. `analytics.py`

Функції для розрахунку метрик якості моделі.

**Метрики:**

1. **Brier Score** — головна метрика калібрації:
   ```
   BS = (1/N) * Σ(predicted_prob - actual_outcome)²
   
   де actual_outcome = 1 якщо виграв, 0 якщо програв
   Ідеально: 0.0, Випадково: 0.25, Жахливо: > 0.3
   ```

2. **Калібрація по бакетах:**
   ```
   Групуємо predictions по діапазонах (0.5-0.6, 0.6-0.7, ..., 0.9-1.0)
   Для кожного: середній predicted vs фактичний win rate
   Ідеально: діагональна лінія на графіку
   ```

3. **ROI (Return on Investment):**
   ```
   ROI = total_pnl / total_wagered * 100%
   ```

4. **Sharpe Ratio** (спрощений):
   ```
   SR = mean(daily_returns) / std(daily_returns)
   > 1.0 = добре, > 2.0 = відмінно
   ```

5. **Edge accuracy:**
   ```
   Коли ми бачили edge 10%+ → скільки разів справді виграли?
   ```

**Функції:**
- `brier_score(model=None)` → float
- `calibration_data(model=None)` → DataFrame з бакетами 
- `roi(model=None)` → float
- `daily_pnl()` → DataFrame
- `category_breakdown()` → DataFrame
- `model_comparison()` → DataFrame (якщо ensemble)

---

### 8. `dashboard.py`

Streamlit веб-дашборд. Запускається окремим процесом.

**Сторінки/секції:**

```
📊 POLYMARKET BOT DASHBOARD

┌─ HEADER ────────────────────────────────────────┐
│ 💰 Total P&L: +$340    📈 ROI: +12.3%          │
│ 🎯 Win Rate: 61%       📊 Brier: 0.18          │
│ 🤖 Active Bets: 23     💸 LLM Cost: $45.20     │
│ 🔴/🟢 Mode: DRY RUN / LIVE                     │
└─────────────────────────────────────────────────┘

┌─ P&L CHART ─────────────────────────────────────┐
│ Лінійний графік кумулятивного P&L по днях       │
│ (plotly line chart)                              │
└─────────────────────────────────────────────────┘

┌─ CALIBRATION CHART ─────────────────────────────┐
│ Scatter plot: predicted probability vs actual    │
│ Діагональна лінія = ідеальна калібрація         │
│ (plotly scatter)                                 │
└─────────────────────────────────────────────────┘

┌─ MODEL COMPARISON ──────────────────────────────┐
│ Таблиця: model | brier | win_rate | pnl | cost  │
│ (якщо ensemble — показати всі 3)                │
└─────────────────────────────────────────────────┘

┌─ CATEGORY BREAKDOWN ────────────────────────────┐
│ Bar chart: P&L по категоріях                    │
│ politics: +$400 | crypto: -$200 | sports: +$50  │
└─────────────────────────────────────────────────┘

┌─ RECENT BETS ───────────────────────────────────┐
│ Таблиця з фільтрами:                            │
│ - Дата, маркет, side, amount, entry_price       │
│ - result (win/loss/pending), pnl                │
│ - reasoning (expandable)                        │
│ Фільтри: по статусу, категорії, моделі         │
└─────────────────────────────────────────────────┘

┌─ SETTINGS (sidebar) ────────────────────────────┐
│ Toggle DRY_RUN on/off (тільки показ, не змінює) │
│ Kelly fraction slider                           │
│ Min edge slider                                 │
│ Refresh interval                                │
└─────────────────────────────────────────────────┘
```

**Auto-refresh:** `st.rerun()` кожні 30 секунд або по кнопці.

---

### 9. `requirements.txt`

```
py-clob-client>=0.5.0
anthropic>=0.40.0
openai>=1.50.0
streamlit>=1.40.0
pandas>=2.0.0
plotly>=5.20.0
schedule>=1.2.0
httpx>=0.27.0
python-dotenv>=1.0.0
```

---

### 10. `.env.example`

```bash
# === ОБОВ'ЯЗКОВІ ===
ANTHROPIC_API_KEY=sk-ant-...
POLYMARKET_API_KEY=your-key
POLYMARKET_SECRET=your-secret
POLYMARKET_PASSPHRASE=your-passphrase

# === ОПЦІЙНІ ===
OPENAI_API_KEY=sk-...
DRY_RUN=true
KELLY_FRACTION=0.25
MIN_EDGE=0.05
MAX_BET=10.0
SCAN_INTERVAL_MINUTES=60
LLM_MODEL=opus
LOG_LEVEL=INFO
DATABASE_PATH=predictions.db
```

---

### 11. `Procfile`

```
bot: python bot.py
web: streamlit run dashboard.py --server.port ${PORT:-8501} --server.headless true
```

---

### 12. `railway.toml`

```toml
[build]
builder = "nixpacks"

[deploy]
startCommand = "python bot.py & streamlit run dashboard.py --server.port ${PORT:-8501} --server.headless true"
healthcheckPath = "/"
restartPolicyType = "on_failure"
restartPolicyMaxRetries = 3

[[volumes]]
mount = "/data"
name = "bot-data"
```

Примітка: `DATABASE_PATH` в Railway встановити на `/data/predictions.db` щоб БД зберігалась у persistent volume.

---

### 13. `.gitignore`

```
.env
*.db
__pycache__/
*.pyc
.venv/
venv/
.streamlit/secrets.toml
```

---

## Фази розробки

### Фаза 1: MVP (dry run only)
1. ✅ Всі файли структури
2. ✅ SQLite БД працює
3. ✅ Сканер отримує маркети з Polymarket API
4. ✅ Evaluator надсилає запити до Claude Opus 4.6
5. ✅ Kelly рахує розмір ставки
6. ✅ Bot loop працює в dry run (зберігає ставки як симуляцію)
7. ✅ Dashboard показує базову статистику

### Фаза 2: Аналітика (2+ тижні dry run даних)
1. Brier score і калібрація
2. Порівняння моделей (якщо ensemble)
3. Аналіз по категоріях — де модель сильна/слабка
4. Тюнінг промпту на основі помилок

### Фаза 3: Live (тільки після підтвердженого edge)
1. Переключити DRY_RUN=false
2. Мінімальні ставки ($1-5)
3. Моніторинг 24/7
4. Поступове збільшення якщо prибуток стабільний

---

## Безпека

1. **API ключі ТІЛЬКИ через env vars** — ніколи не в коді
2. **DRY_RUN=true по дефолту** — реальні ставки тільки при явному перемиканні
3. **MAX_BET обмеження** — навіть при DRY_RUN=false не більше ліміту
4. **Логування всіх дій** — для аудиту
5. **.env в .gitignore** — ніколи не комітити ключі

---

## Як запустити локально

```bash
# 1. Клонувати репо
git clone https://github.com/YOUR_USERNAME/polymarket-bot.git
cd polymarket-bot

# 2. Створити віртуальне середовище
python3 -m venv venv
source venv/bin/activate  # Linux/Mac
# або: venv\Scripts\activate  # Windows

# 3. Встановити залежності
pip install -r requirements.txt

# 4. Налаштувати env
cp .env.example .env
# Відредагувати .env — вставити API ключі

# 5. Запустити бота (dry run)
python bot.py

# 6. В іншому терміналі — дашборд
streamlit run dashboard.py
# Відкриється на http://localhost:8501
```

## Як задеплоїти на Railway

```bash
# 1. Створити акаунт на railway.app
# 2. Підключити GitHub репо
# 3. Додати env vars в Railway dashboard
# 4. Додати volume для /data (для SQLite)
# 5. Deploy — автоматично по git push
```

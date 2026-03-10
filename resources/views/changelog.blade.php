<!DOCTYPE html>
<html lang="uk" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Changelog — AIntento</title>
    <meta name="description" content="Журнал оновлень AIntento — AI Асистент для e-commerce. Нові функції, покращення та виправлення.">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🤖</text></svg>">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|space-grotesk:600,700" rel="stylesheet" />
    <style>
        :root {
            --primary: #10b981;
            --primary-dark: #059669;
            --primary-light: #d1fae5;
            --secondary: #065f46;
            --bg-light: #f0fdf4;
            --text-dark: #111827;
            --text-gray: #6b7280;
            --border-color: #e5e7eb;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: var(--text-dark);
            background: linear-gradient(180deg, var(--bg-light) 0%, #ffffff 100%);
            min-height: 100vh;
        }

        .container { max-width: 800px; margin: 0 auto; padding: 0 24px; }

        /* Header */
        header {
            padding: 16px 0;
            backdrop-filter: blur(20px);
            background: rgba(255,255,255,0.85);
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--border-color);
        }
        header .container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 800px;
        }
        .logo {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 22px;
            font-weight: 700;
            color: var(--primary);
            text-decoration: none;
        }
        .logo:hover { color: var(--primary-dark); }
        .back-link {
            color: var(--text-gray);
            text-decoration: none;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: color 0.2s;
        }
        .back-link:hover { color: var(--primary); }

        /* Hero */
        .hero {
            padding: 60px 0 40px;
            text-align: center;
        }
        .hero h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 12px;
        }
        .hero p {
            color: var(--text-gray);
            font-size: 16px;
            max-width: 500px;
            margin: 0 auto;
        }

        /* Timeline */
        .timeline { padding: 0 0 80px; }

        .version-block {
            position: relative;
            padding-left: 36px;
            margin-bottom: 48px;
        }
        .version-block::before {
            content: '';
            position: absolute;
            left: 11px;
            top: 8px;
            bottom: -48px;
            width: 2px;
            background: var(--border-color);
        }
        .version-block:last-child::before { display: none; }

        .version-dot {
            position: absolute;
            left: 4px;
            top: 6px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: var(--primary);
            border: 3px solid white;
            box-shadow: 0 0 0 2px var(--primary);
        }
        .version-block:not(:first-child) .version-dot {
            background: white;
            box-shadow: 0 0 0 2px var(--border-color);
        }

        .version-header {
            display: flex;
            align-items: baseline;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .version-tag {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 20px;
            font-weight: 700;
        }
        .version-date {
            font-size: 13px;
            color: var(--text-gray);
        }

        .change-list { list-style: none; }
        .change-list li {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            font-size: 15px;
            line-height: 1.5;
            align-items: baseline;
        }

        .tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 6px;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .tag-new { background: #d1fae5; color: #065f46; }
        .tag-improved { background: #dbeafe; color: #1e40af; }
        .tag-fix { background: #fef3c7; color: #92400e; }
        .tag-widget { background: #ede9fe; color: #5b21b6; }
        .tag-admin { background: #fce7f3; color: #9d174d; }

        /* Footer */
        footer {
            border-top: 1px solid var(--border-color);
            padding: 24px 0;
            text-align: center;
            color: var(--text-gray);
            font-size: 13px;
        }
        footer a { color: var(--text-gray); text-decoration: none; }
        footer a:hover { color: var(--primary); }

        @media (max-width: 640px) {
            .hero h1 { font-size: 28px; }
            .version-tag { font-size: 18px; }
            .change-list li { font-size: 14px; }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <a href="/" class="logo">🤖 AIntento</a>
            <a href="/" class="back-link">← На головну</a>
        </div>
    </header>

    <section class="hero">
        <div class="container">
            <h1>📋 Changelog</h1>
            <p>Що нового в AIntento — нові функції, покращення та виправлення</p>
        </div>
    </section>

    <section class="timeline">
        <div class="container">

            {{-- ===== v1.9.0 ===== --}}
            <div class="version-block">
                <div class="version-dot"></div>
                <div class="version-header">
                    <span class="version-tag">v1.9.0</span>
                    <span class="version-date">10 березня 2026</span>
                </div>
                <ul class="change-list">
                    <li>
                        <span class="tag tag-fix">🐛 Фікс</span>
                        <span>Чат більше не каже «передам спеціалісту» — замість цього одразу дає контакти менеджера (Telegram, Instagram)</span>
                    </li>
                    <li>
                        <span class="tag tag-fix">🐛 Фікс</span>
                        <span>Пошук за кольором працює — «рожева футболка» тепер повертає рожеві товари першими, а не випадкові</span>
                    </li>
                    <li>
                        <span class="tag tag-improved">⬆ Покращено</span>
                        <span>Контекстне інтро замість «Ось що я знайшов» — тепер «Ось шоломи:», «Ось футболки:», «Ось конструктори:» тощо</span>
                    </li>
                    <li>
                        <span class="tag tag-fix">🐛 Фікс</span>
                        <span>Сезонні запити («що беруть взимку») тепер працюють для всіх магазинів, а не лише для військових</span>
                    </li>
                    <li>
                        <span class="tag tag-improved">⬆ Покращено</span>
                        <span>Релевантність пошуку — товари з ключовим словом у назві показуються першими (спальник → спальна система, а не стропи)</span>
                    </li>
                    <li>
                        <span class="tag tag-improved">⬆ Покращено</span>
                        <span>Розширено палітру кольорів з 6 до 18 — додано рожевий, бордовий, фіолетовий, оранжевий, блакитний та інші</span>
                    </li>
                </ul>
            </div>

            {{-- ===== v1.8.0 ===== --}}
            <div class="version-block">
                <div class="version-dot"></div>
                <div class="version-header">
                    <span class="version-tag">v1.8.0</span>
                    <span class="version-date">4 березня 2026</span>
                </div>
                <ul class="change-list">
                    <li>
                        <span class="tag tag-new">✨ Нове</span>
                        <span>Кастомізація іконки віджета — розмір, форма (коло / квадрат / squircle), анімація появи та ефект привернення уваги</span>
                    </li>
                    <li>
                        <span class="tag tag-admin">🎛 Адмін</span>
                        <span>Нова секція «Іконка чату» в налаштуваннях віджета з візуальним превʼю</span>
                    </li>
                    <li>
                        <span class="tag tag-widget">💬 Віджет</span>
                        <span>Кнопка «Погоджуюсь» для повідомлення про обробку даних — після прийняття зникає і не займає місце</span>
                    </li>
                    <li>
                        <span class="tag tag-improved">⬆ Покращено</span>
                        <span>Позиція іконки адаптується до розміру — великі іконки не перекривають кнопки сайту</span>
                    </li>
                </ul>
            </div>

            {{-- ===== v1.7.0 ===== --}}
            <div class="version-block">
                <div class="version-dot"></div>
                <div class="version-header">
                    <span class="version-tag">v1.7.0</span>
                    <span class="version-date">3 березня 2026</span>
                </div>
                <ul class="change-list">
                    <li>
                        <span class="tag tag-new">✨ Нове</span>
                        <span>Шарувата система промптів — базовий пресет + контекстні оверлеї (категорії, FAQ, кампанії)</span>
                    </li>
                    <li>
                        <span class="tag tag-admin">🎛 Адмін</span>
                        <span>Менеджер промпт-пресетів з підтримкою змінних, пріоритетів та контекстних фільтрів</span>
                    </li>
                    <li>
                        <span class="tag tag-improved">⬆ Покращено</span>
                        <span>Мультитенантна ізоляція кешу пресетів — кожен магазин має власний набір</span>
                    </li>
                </ul>
            </div>

            {{-- ===== v1.6.0 ===== --}}
            <div class="version-block">
                <div class="version-dot"></div>
                <div class="version-header">
                    <span class="version-tag">v1.6.0</span>
                    <span class="version-date">2 березня 2026</span>
                </div>
                <ul class="change-list">
                    <li>
                        <span class="tag tag-improved">⬆ Покращено</span>
                        <span>Виправлення дублювання повідомлень асистента в історії чату</span>
                    </li>
                    <li>
                        <span class="tag tag-improved">⬆ Покращено</span>
                        <span>Сортування товарів за популярністю (orders_count, views_count, added_to_cart_count)</span>
                    </li>
                    <li>
                        <span class="tag tag-new">✨ Нове</span>
                        <span>Розпізнавання брендів та транслітерації (опс кор → Ops-Core, саломон → Salomon)</span>
                    </li>
                    <li>
                        <span class="tag tag-improved">⬆ Покращено</span>
                        <span>Інструкції по негативному фідбеку та сезонності в системному промпті</span>
                    </li>
                    <li>
                        <span class="tag tag-fix">🐛 Виправлено</span>
                        <span>Фільтрація упаковок та комплектуючих у результатах пошуку</span>
                    </li>
                </ul>
            </div>

            {{-- ===== v1.5.0 ===== --}}
            <div class="version-block">
                <div class="version-dot"></div>
                <div class="version-header">
                    <span class="version-tag">v1.5.0</span>
                    <span class="version-date">28 лютого 2026</span>
                </div>
                <ul class="change-list">
                    <li>
                        <span class="tag tag-new">✨ Нове</span>
                        <span>Онбординг нових тенантів — автоматичний імпорт каталогу, AI-збагачення та індексація в Meilisearch</span>
                    </li>
                    <li>
                        <span class="tag tag-admin">🎛 Адмін</span>
                        <span>Прогрес-бар онбордингу в реальному часі з Livewire</span>
                    </li>
                    <li>
                        <span class="tag tag-new">✨ Нове</span>
                        <span>Мультитенантна архітектура — кожен магазин має ізольований каталог, налаштування та аналітику</span>
                    </li>
                </ul>
            </div>

            {{-- ===== v1.4.0 ===== --}}
            <div class="version-block">
                <div class="version-dot"></div>
                <div class="version-header">
                    <span class="version-tag">v1.4.0</span>
                    <span class="version-date">25 лютого 2026</span>
                </div>
                <ul class="change-list">
                    <li>
                        <span class="tag tag-widget">💬 Віджет</span>
                        <span>SSE-стрімінг відповідей — текст зʼявляється посимвольно як у ChatGPT</span>
                    </li>
                    <li>
                        <span class="tag tag-new">✨ Нове</span>
                        <span>Картки товарів з зображеннями, ціною та кнопкою «Детальніше»</span>
                    </li>
                    <li>
                        <span class="tag tag-improved">⬆ Покращено</span>
                        <span>Контекст розмови — бот памʼятає попередні запити та фільтри</span>
                    </li>
                </ul>
            </div>

            {{-- ===== v1.3.0 ===== --}}
            <div class="version-block">
                <div class="version-dot"></div>
                <div class="version-header">
                    <span class="version-tag">v1.3.0</span>
                    <span class="version-date">20 лютого 2026</span>
                </div>
                <ul class="change-list">
                    <li>
                        <span class="tag tag-new">✨ Нове</span>
                        <span>AI-збагачення каталогу — автоматичне визначення категорій, ключових слів та синонімів через GPT-4o-mini</span>
                    </li>
                    <li>
                        <span class="tag tag-improved">⬆ Покращено</span>
                        <span>Повнотекстовий пошук Meilisearch з фільтрами по категорії, бренду, ціні та наявності</span>
                    </li>
                </ul>
            </div>

            {{-- ===== v1.2.0 ===== --}}
            <div class="version-block">
                <div class="version-dot"></div>
                <div class="version-header">
                    <span class="version-tag">v1.2.0</span>
                    <span class="version-date">15 лютого 2026</span>
                </div>
                <ul class="change-list">
                    <li>
                        <span class="tag tag-new">✨ Нове</span>
                        <span>Інтеграція з Horoshop — автоматична синхронізація каталогу товарів</span>
                    </li>
                    <li>
                        <span class="tag tag-widget">💬 Віджет</span>
                        <span>Кастомізація кольорів, аватара бота та привітального повідомлення</span>
                    </li>
                    <li>
                        <span class="tag tag-admin">🎛 Адмін</span>
                        <span>Адмін-панель з налаштуваннями віджета, аналітикою та управлінням каталогом</span>
                    </li>
                </ul>
            </div>

            {{-- ===== v1.1.0 ===== --}}
            <div class="version-block">
                <div class="version-dot"></div>
                <div class="version-header">
                    <span class="version-tag">v1.1.0</span>
                    <span class="version-date">10 лютого 2026</span>
                </div>
                <ul class="change-list">
                    <li>
                        <span class="tag tag-new">✨ Нове</span>
                        <span>Function Calling Agent — GPT самостійно вирішує коли шукати товари</span>
                    </li>
                    <li>
                        <span class="tag tag-improved">⬆ Покращено</span>
                        <span>Фоллбек на Eloquent-пошук при недоступності Meilisearch</span>
                    </li>
                </ul>
            </div>

            {{-- ===== v1.0.0 ===== --}}
            <div class="version-block">
                <div class="version-dot"></div>
                <div class="version-header">
                    <span class="version-tag">v1.0.0</span>
                    <span class="version-date">1 лютого 2026</span>
                </div>
                <ul class="change-list">
                    <li>
                        <span class="tag tag-new">✨ Нове</span>
                        <span>Перший реліз — AI чат-бот для e-commerce з підбором товарів</span>
                    </li>
                    <li>
                        <span class="tag tag-widget">💬 Віджет</span>
                        <span>Вбудований віджет для сайту з адаптивним дизайном</span>
                    </li>
                    <li>
                        <span class="tag tag-new">✨ Нове</span>
                        <span>Діагностичний API для моніторингу та налагодження</span>
                    </li>
                </ul>
            </div>

        </div>
    </section>

    <footer>
        <div class="container">
            <p>© {{ date('Y') }} <a href="/">AIntento</a> — зроблено в 🇺🇦 Україні</p>
        </div>
    </footer>
</body>
</html>

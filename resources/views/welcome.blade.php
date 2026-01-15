<!DOCTYPE html>
<html lang="uk" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>AIntento — AI Асистент для e-commerce</title>
        <meta name="description" content="Розумний AI-асистент для інтернет-магазинів. Підбір товарів, консультації, персоналізовані рекомендації та глибока аналітика продажів.">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|space-grotesk:600,700" rel="stylesheet" />

        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            :root {
                --primary: #2563eb;
                --primary-dark: #1d4ed8;
                --text-dark: #0f172a;
                --text-gray: #64748b;
                --bg-light: #f8fafc;
                --border-color: #e2e8f0;
            }
            
            body {
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
                line-height: 1.6;
                color: var(--text-dark);
                background: #ffffff;
            }
            
            h1, h2, h3 {
                font-family: 'Space Grotesk', sans-serif;
                font-weight: 700;
                line-height: 1.2;
            }
            
            .container {
                max-width: 1200px;
                margin: 0 auto;
                padding: 0 24px;
            }
            
            /* Header */
            header {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                background: rgba(255, 255, 255, 0.95);
                backdrop-filter: blur(12px);
                border-bottom: 1px solid var(--border-color);
                z-index: 100;
            }
            
            header .container {
                display: flex;
                justify-content: space-between;
                align-items: center;
                height: 72px;
            }
            
            .logo {
                font-size: 24px;
                font-weight: 700;
                font-family: 'Space Grotesk', sans-serif;
                color: var(--primary);
                text-decoration: none;
            }
            
            nav {
                display: flex;
                gap: 32px;
                align-items: center;
            }
            
            nav a {
                color: var(--text-dark);
                text-decoration: none;
                font-weight: 500;
                font-size: 15px;
                transition: color 0.2s;
            }
            
            nav a:hover {
                color: var(--primary);
            }
            
            .btn {
                display: inline-flex;
                align-items: center;
                padding: 10px 20px;
                border-radius: 8px;
                font-weight: 600;
                font-size: 15px;
                text-decoration: none;
                transition: all 0.2s;
                cursor: pointer;
                border: none;
            }
            
            .btn-primary {
                background: var(--primary);
                color: white;
            }
            
            .btn-primary:hover {
                background: var(--primary-dark);
                transform: translateY(-1px);
            }
            
            .btn-outline {
                border: 2px solid var(--border-color);
                color: var(--text-dark);
                background: transparent;
            }
            
            .btn-outline:hover {
                border-color: var(--primary);
                color: var(--primary);
            }
            
            /* Hero */
            .hero {
                padding: 140px 0 80px;
                text-align: center;
            }
            
            .hero h1 {
                font-size: clamp(36px, 5vw, 64px);
                margin-bottom: 24px;
                background: linear-gradient(135deg, var(--primary) 0%, #7c3aed 100%);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                background-clip: text;
            }
            
            .hero p {
                font-size: 20px;
                color: var(--text-gray);
                max-width: 700px;
                margin: 0 auto 40px;
            }
            
            .hero-actions {
                display: flex;
                gap: 16px;
                justify-content: center;
                flex-wrap: wrap;
            }
            
            /* Features Grid */
            .features {
                padding: 80px 0;
                background: var(--bg-light);
            }
            
            .section-title {
                text-align: center;
                font-size: clamp(32px, 4vw, 48px);
                margin-bottom: 16px;
            }
            
            .section-subtitle {
                text-align: center;
                font-size: 18px;
                color: var(--text-gray);
                max-width: 600px;
                margin: 0 auto 64px;
            }
            
            .features-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 32px;
            }
            
            .feature-card {
                background: white;
                border-radius: 16px;
                padding: 32px;
                border: 1px solid var(--border-color);
                transition: transform 0.2s, box-shadow 0.2s;
            }
            
            .feature-card:hover {
                transform: translateY(-4px);
                box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
            }
            
            .feature-icon {
                width: 56px;
                height: 56px;
                border-radius: 12px;
                background: linear-gradient(135deg, var(--primary), #7c3aed);
                display: flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 20px;
                font-size: 28px;
            }
            
            .feature-card h3 {
                font-size: 22px;
                margin-bottom: 12px;
            }
            
            .feature-card p {
                color: var(--text-gray);
                line-height: 1.7;
            }
            
            .badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
                margin-top: 12px;
            }
            
            .badge-success {
                background: #dcfce7;
                color: #16a34a;
            }
            
            .badge-soon {
                background: #fef3c7;
                color: #d97706;
            }
            
            /* Demo Section */
            .demo {
                padding: 80px 0;
            }
            
            .demo-window {
                background: white;
                border-radius: 16px;
                border: 1px solid var(--border-color);
                box-shadow: 0 24px 48px rgba(0, 0, 0, 0.1);
                overflow: hidden;
                max-width: 900px;
                margin: 0 auto;
            }
            
            .demo-header {
                background: var(--bg-light);
                padding: 16px 24px;
                border-bottom: 1px solid var(--border-color);
                display: flex;
                gap: 8px;
                align-items: center;
            }
            
            .demo-dot {
                width: 12px;
                height: 12px;
                border-radius: 50%;
            }
            
            .demo-dot:nth-child(1) { background: #ef4444; }
            .demo-dot:nth-child(2) { background: #f59e0b; }
            .demo-dot:nth-child(3) { background: #10b981; }
            
            .demo-content {
                padding: 40px;
                min-height: 400px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-size: 18px;
            }
            
            /* Coming Soon */
            .coming-soon {
                padding: 80px 0;
                background: var(--bg-light);
            }
            
            .coming-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 24px;
                max-width: 1000px;
                margin: 0 auto;
            }
            
            .coming-card {
                background: white;
                border-radius: 12px;
                padding: 24px;
                border: 2px dashed var(--border-color);
            }
            
            .coming-card h4 {
                font-size: 18px;
                margin-bottom: 8px;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .coming-card p {
                color: var(--text-gray);
                font-size: 14px;
            }
            
            /* Footer */
            footer {
                padding: 48px 0;
                border-top: 1px solid var(--border-color);
                text-align: center;
            }
            
            footer p {
                color: var(--text-gray);
                margin-bottom: 16px;
            }
            
            .footer-links {
                display: flex;
                gap: 24px;
                justify-content: center;
                flex-wrap: wrap;
            }
            
            .footer-links a {
                color: var(--text-gray);
                text-decoration: none;
                font-size: 14px;
            }
            
            .footer-links a:hover {
                color: var(--primary);
            }
            
            @media (max-width: 768px) {
                nav {
                    gap: 16px;
                }
                
                .hero {
                    padding: 100px 0 60px;
                }
                
                .features, .demo, .coming-soon {
                    padding: 60px 0;
                }
            }
        </style>
    </head>
    <body>
        <!-- Header -->
        <header>
            <div class="container">
                <a href="/" class="logo">AIntento</a>
                <nav>
                    @if (Route::has('login'))
                        @auth
                            <a href="{{ url('/dashboard') }}" class="btn btn-primary">Dashboard</a>
                        @else
                            <a href="{{ route('login') }}">Увійти</a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="btn btn-primary">Реєстрація</a>
                            @endif
                        @endauth
                    @endif
                </nav>
            </div>
        </header>

        <!-- Hero Section -->
        <section class="hero">
            <div class="container">
                <h1>AI Асистент для e-commerce</h1>
                <p>Розумний чат-бот для вашого інтернет-магазину. Підбір товарів, консультації 24/7, персоналізовані рекомендації та глибока аналітика продажів.</p>
                <div class="hero-actions">
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="btn btn-primary">Спробувати безкоштовно</a>
                    @endif
                    <a href="#demo" class="btn btn-outline">Дивитись демо</a>
                </div>
            </div>
        </section>

        <!-- Features Section -->
        <section class="features" id="features">
            <div class="container">
                <h2 class="section-title">Реалізовані можливості</h2>
                <p class="section-subtitle">Вже працюють і допомагають продавати більше</p>
                
                <div class="features-grid">
                    <div class="feature-card">
                        <div class="feature-icon">🤖</div>
                        <h3>Розумний підбір товарів</h3>
                        <p>GPT-4 аналізує запит клієнта і підбирає найбільш відповідні товари з урахуванням контексту розмови, бюджету та попередніх переглядів.</p>
                        <span class="badge badge-success">✓ Працює</span>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon">⚡</div>
                        <h3>Streaming відповіді</h3>
                        <p>Відповіді з'являються миттєво — клієнт бачить текст у реальному часі через SSE, без затримок.</p>
                        <span class="badge badge-success">✓ Працює</span>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon">🎯</div>
                        <h3>Контекстна пам'ять</h3>
                        <p>Бот запам'ятовує всю історію діалогу, категорії, розміри та бюджет — можна продовжувати розмову природно.</p>
                        <span class="badge badge-success">✓ Працює</span>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon">🔍</div>
                        <h3>Meilisearch пошук</h3>
                        <p>Блискавичний пошук товарів з підтримкою фільтрів, синонімів та typo-tolerance. Fallback на SQL при необхідності.</p>
                        <span class="badge badge-success">✓ Працює</span>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon">🎨</div>
                        <h3>Кастомізація віджета</h3>
                        <p>Повна персоналізація: кольори, шрифти, положення, привітання, аватар бота — все налаштовується під ваш бренд.</p>
                        <span class="badge badge-success">✓ Працює</span>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon">📊</div>
                        <h3>Базова аналітика</h3>
                        <p>Трекінг взаємодій, популярних запитів, додавання в кошик та конверсій. Dashboard з основними метриками.</p>
                        <span class="badge badge-success">✓ Працює</span>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon">🔗</div>
                        <h3>Інтеграція з Horoshop</h3>
                        <p>Готова інтеграція з Horoshop через API: синхронізація товарів, залишків, цін та статусів замовлень.</p>
                        <span class="badge badge-success">✓ Працює</span>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon">💬</div>
                        <h3>Кастомні промпти</h3>
                        <p>Гнучка система промптів з підтримкою змінних, тональності та контексту (мова, кампанія, категорії).</p>
                        <span class="badge badge-success">✓ Працює</span>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon">📱</div>
                        <h3>Адаптивний віджет</h3>
                        <p>Ідеально працює на мобільних, планшетах та десктопі. Зручний інтерфейс і швидка робота на всіх пристроях.</p>
                        <span class="badge badge-success">✓ Працює</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Demo Section -->
        <section class="demo" id="demo">
            <div class="container">
                <h2 class="section-title">Як це виглядає</h2>
                <p class="section-subtitle">Інтерактивний чат з вашими товарами</p>
                
                <div class="demo-window">
                    <div class="demo-header">
                        <div class="demo-dot"></div>
                        <div class="demo-dot"></div>
                        <div class="demo-dot"></div>
                    </div>
                    <div class="demo-content">
                        <p>📺 Тут буде інтерактивне демо віджета<br><small style="opacity: 0.8; font-size: 14px;">(Coming soon)</small></p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Coming Soon Section -->
        <section class="coming-soon">
            <div class="container">
                <h2 class="section-title">В планах</h2>
                <p class="section-subtitle">Майбутні фічі, що перетворять AIntento на повноцінний центр продажів</p>
                
                <div class="coming-grid">
                    <div class="coming-card">
                        <h4>
                            <span>🧠</span>
                            AI Рекомендації
                            <span class="badge badge-soon">Soon</span>
                        </h4>
                        <p>Автоматичні персоналізовані рекомендації товарів на основі історії переглядів, покупок та поведінки.</p>
                    </div>

                    <div class="coming-card">
                        <h4>
                            <span>📊</span>
                            Повна Аналітика
                            <span class="badge badge-soon">Soon</span>
                        </h4>
                        <p>Глибокий аналіз продажів, конверсій, популярних запитів. AI-інсайти для покращення асортименту.</p>
                    </div>

                    <div class="coming-card">
                        <h4>
                            <span>🎯</span>
                            Міні-квіз для підбору
                            <span class="badge badge-soon">Soon</span>
                        </h4>
                        <p>Інтерактивний квіз для швидкого підбору товарів за відповідями клієнта. Конверсія з першого кліку.</p>
                    </div>

                    <div class="coming-card">
                        <h4>
                            <span>💡</span>
                            AI Оптимізація каталогу
                            <span class="badge badge-soon">Soon</span>
                        </h4>
                        <p>Бот аналізує запити і пропонує що додати/змінити в каталозі для збільшення продажів.</p>
                    </div>

                    <div class="coming-card">
                        <h4>
                            <span>🔔</span>
                            Розумні нотифікації
                            <span class="badge badge-soon">Soon</span>
                        </h4>
                        <p>Пуши про нові товари, знижки та спецпропозиції на основі інтересів кожного клієнта.</p>
                    </div>

                    <div class="coming-card">
                        <h4>
                            <span>🎨</span>
                            AI Генерація контенту
                            <span class="badge badge-soon">Soon</span>
                        </h4>
                        <p>Автоматичне створення описів товарів, SEO-текстів та контенту для соцмереж.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer>
            <div class="container">
                <p>&copy; 2025 AIntento. Розумний AI для вашого e-commerce.</p>
                <div class="footer-links">
                    <a href="/admin">Адмін-панель</a>
                    <a href="https://laravel.com/docs" target="_blank">Документація</a>
                    <a href="mailto:support@aimbot.laravel.cloud">Підтримка</a>
                </div>
            </div>
        </footer>
    </body>
</html>

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
                --primary: #10b981;
                --primary-dark: #059669;
                --primary-light: #d1fae5;
                --secondary: #065f46;
                --text-dark: #0f172a;
                --text-gray: #64748b;
                --bg-light: #f0fdf4;
                --border-color: #d1fae5;
            }
            
            body {
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
                line-height: 1.6;
                color: var(--text-dark);
                background: #ffffff;
            }
            
            h1, h2, h3, h4 {
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
                padding: 12px 24px;
                border-radius: 10px;
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
                box-shadow: 0 4px 14px rgba(16, 185, 129, 0.3);
            }
            
            .btn-primary:hover {
                background: var(--primary-dark);
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
            }
            
            .btn-outline {
                border: 2px solid var(--primary);
                color: var(--primary);
                background: transparent;
            }
            
            .btn-outline:hover {
                background: var(--primary);
                color: white;
            }
            
            .btn-small {
                padding: 8px 16px;
                font-size: 13px;
            }
            
            /* Hero */
            .hero {
                padding: 140px 0 80px;
                text-align: center;
                background: linear-gradient(180deg, var(--bg-light) 0%, #ffffff 100%);
            }
            
            .hero h1 {
                font-size: clamp(40px, 5vw, 72px);
                margin-bottom: 24px;
                background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
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
                padding: 100px 0;
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
                grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
                gap: 24px;
            }
            
            .feature-card {
                background: white;
                border-radius: 20px;
                padding: 32px;
                border: 2px solid var(--border-color);
                transition: all 0.3s;
                cursor: pointer;
                position: relative;
                overflow: hidden;
            }
            
            .feature-card:hover {
                border-color: var(--primary);
                box-shadow: 0 12px 40px rgba(16, 185, 129, 0.15);
            }
            
            .feature-card.expanded {
                border-color: var(--primary);
                box-shadow: 0 12px 40px rgba(16, 185, 129, 0.2);
            }
            
            .feature-header {
                display: flex;
                align-items: flex-start;
                gap: 16px;
            }
            
            .feature-icon {
                width: 56px;
                height: 56px;
                border-radius: 14px;
                background: linear-gradient(135deg, var(--primary), var(--secondary));
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 28px;
                flex-shrink: 0;
            }
            
            .feature-info {
                flex: 1;
            }
            
            .feature-card h3 {
                font-size: 20px;
                margin-bottom: 8px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .feature-card p {
                color: var(--text-gray);
                line-height: 1.7;
                font-size: 15px;
            }
            
            .badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .badge-success {
                background: var(--primary-light);
                color: var(--primary-dark);
            }
            
            .badge-soon {
                background: #fef3c7;
                color: #d97706;
            }
            
            .feature-expand {
                color: var(--primary);
                font-size: 13px;
                font-weight: 600;
                margin-top: 16px;
                display: flex;
                align-items: center;
                gap: 6px;
            }
            
            .feature-expand svg {
                transition: transform 0.3s;
            }
            
            .feature-card.expanded .feature-expand svg {
                transform: rotate(180deg);
            }
            
            /* Feature Demo */
            .feature-demo {
                max-height: 0;
                overflow: hidden;
                transition: max-height 0.4s ease, margin-top 0.4s ease, padding-top 0.4s ease;
                margin-top: 0;
                padding-top: 0;
                border-top: 0 solid var(--border-color);
            }
            
            .feature-card.expanded .feature-demo {
                max-height: 500px;
                margin-top: 24px;
                padding-top: 24px;
                border-top-width: 1px;
            }
            
            .demo-chat {
                background: var(--bg-light);
                border-radius: 12px;
                padding: 16px;
                max-height: 300px;
                overflow-y: auto;
            }
            
            .demo-message {
                margin-bottom: 12px;
                display: flex;
                gap: 10px;
                align-items: flex-start;
            }
            
            .demo-message.user {
                flex-direction: row-reverse;
            }
            
            .demo-avatar {
                width: 32px;
                height: 32px;
                border-radius: 50%;
                background: var(--primary);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 14px;
                flex-shrink: 0;
            }
            
            .demo-message.user .demo-avatar {
                background: #6366f1;
            }
            
            .demo-bubble {
                background: white;
                padding: 10px 14px;
                border-radius: 12px;
                font-size: 14px;
                max-width: 80%;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            
            .demo-message.user .demo-bubble {
                background: #6366f1;
                color: white;
            }
            
            .demo-product {
                display: flex;
                gap: 12px;
                background: white;
                padding: 12px;
                border-radius: 10px;
                margin-top: 8px;
                border: 1px solid var(--border-color);
            }
            
            .demo-product-img {
                width: 60px;
                height: 60px;
                background: linear-gradient(135deg, #e0e7ff, #c7d2fe);
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 24px;
            }
            
            .demo-product-info h5 {
                font-size: 13px;
                margin-bottom: 4px;
            }
            
            .demo-product-info span {
                color: var(--primary);
                font-weight: 700;
                font-size: 15px;
            }
            
            /* Interactive Demo Section */
            .demo-section {
                padding: 100px 0;
                background: var(--bg-light);
            }
            
            .demo-container {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 48px;
                align-items: center;
                max-width: 1100px;
                margin: 0 auto;
            }
            
            .demo-info h2 {
                font-size: 36px;
                margin-bottom: 16px;
            }
            
            .demo-info p {
                color: var(--text-gray);
                font-size: 18px;
                margin-bottom: 32px;
            }
            
            .scenario-buttons {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
            }
            
            .scenario-btn {
                padding: 12px 20px;
                border-radius: 10px;
                border: 2px solid var(--border-color);
                background: white;
                font-size: 14px;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.2s;
            }
            
            .scenario-btn:hover {
                border-color: var(--primary);
                color: var(--primary);
            }
            
            .scenario-btn.active {
                background: var(--primary);
                border-color: var(--primary);
                color: white;
            }
            
            /* Chat Widget Demo */
            .chat-widget-demo {
                background: white;
                border-radius: 24px;
                box-shadow: 0 25px 80px rgba(0, 0, 0, 0.15);
                overflow: hidden;
                max-width: 400px;
                margin: 0 auto;
            }
            
            .chat-widget-header {
                background: linear-gradient(135deg, var(--primary), var(--secondary));
                padding: 20px 24px;
                color: white;
            }
            
            .chat-widget-header h4 {
                font-size: 18px;
                margin-bottom: 4px;
            }
            
            .chat-widget-header span {
                font-size: 13px;
                opacity: 0.9;
            }
            
            .chat-widget-body {
                padding: 20px;
                min-height: 350px;
                max-height: 350px;
                overflow-y: auto;
                background: #fafafa;
            }
            
            .chat-widget-input {
                padding: 16px 20px;
                border-top: 1px solid var(--border-color);
                display: flex;
                gap: 12px;
                background: white;
            }
            
            .chat-widget-input input {
                flex: 1;
                padding: 12px 16px;
                border: 2px solid var(--border-color);
                border-radius: 10px;
                font-size: 14px;
                outline: none;
                transition: border-color 0.2s;
            }
            
            .chat-widget-input input:focus {
                border-color: var(--primary);
            }
            
            .chat-widget-input button {
                padding: 12px 20px;
                background: var(--primary);
                color: white;
                border: none;
                border-radius: 10px;
                font-weight: 600;
                cursor: pointer;
                transition: background 0.2s;
            }
            
            .chat-widget-input button:hover {
                background: var(--primary-dark);
            }
            
            .typing-indicator {
                display: flex;
                gap: 4px;
                padding: 12px 16px;
                background: white;
                border-radius: 12px;
                width: fit-content;
            }
            
            .typing-indicator span {
                width: 8px;
                height: 8px;
                background: var(--primary);
                border-radius: 50%;
                animation: typing 1.4s infinite;
            }
            
            .typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
            .typing-indicator span:nth-child(3) { animation-delay: 0.4s; }
            
            @keyframes typing {
                0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
                30% { transform: translateY(-4px); opacity: 1; }
            }
            
            /* Coming Soon */
            .coming-soon {
                padding: 100px 0;
            }
            
            .coming-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 24px;
                max-width: 1000px;
                margin: 0 auto;
            }
            
            .coming-card {
                background: white;
                border-radius: 16px;
                padding: 28px;
                border: 2px dashed var(--border-color);
                transition: all 0.2s;
            }
            
            .coming-card:hover {
                border-color: var(--primary);
                transform: translateY(-4px);
            }
            
            .coming-card h4 {
                font-size: 18px;
                margin-bottom: 12px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .coming-card p {
                color: var(--text-gray);
                font-size: 14px;
                line-height: 1.7;
            }
            
            /* Footer */
            footer {
                padding: 60px 0;
                background: var(--bg-light);
                border-top: 1px solid var(--border-color);
            }
            
            .footer-content {
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 24px;
            }
            
            footer p {
                color: var(--text-gray);
            }
            
            .footer-links {
                display: flex;
                gap: 32px;
            }
            
            .footer-links a {
                color: var(--text-gray);
                text-decoration: none;
                font-size: 14px;
                font-weight: 500;
                transition: color 0.2s;
            }
            
            .footer-links a:hover {
                color: var(--primary);
            }
            
            @media (max-width: 900px) {
                .demo-container {
                    grid-template-columns: 1fr;
                }
                
                .chat-widget-demo {
                    max-width: 100%;
                }
            }
            
            @media (max-width: 768px) {
                nav {
                    gap: 16px;
                }
                
                .hero {
                    padding: 100px 0 60px;
                }
                
                .features, .demo-section, .coming-soon {
                    padding: 60px 0;
                }
                
                .features-grid {
                    grid-template-columns: 1fr;
                }
                
                .footer-content {
                    flex-direction: column;
                    text-align: center;
                }
            }
        </style>
    </head>
    <body>
        <!-- Header -->
        <header>
            <div class="container">
                <a href="/" class="logo">🤖 AIntento</a>
                <nav>
                    @if (Route::has('login'))
                        @auth
                            <a href="{{ url('/dashboard') }}" class="btn btn-primary btn-small">Dashboard</a>
                        @else
                            <a href="{{ route('login') }}">Увійти</a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="btn btn-primary btn-small">Реєстрація</a>
                            @endif
                        @endauth
                    @endif
                </nav>
            </div>
        </header>

        <!-- Hero Section -->
        <section class="hero">
            <div class="container">
                <h1>AI Асистент для<br>e-commerce</h1>
                <p>Розумний чат-бот для вашого інтернет-магазину. Підбір товарів, консультації 24/7, персоналізовані рекомендації — все на автопілоті.</p>
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
                <h2 class="section-title">Що вміє AIntento</h2>
                <p class="section-subtitle">Натисніть на картку, щоб побачити демонстрацію</p>
                
                <div class="features-grid">
                    <!-- Feature 1: Smart Product Search -->
                    <div class="feature-card" onclick="toggleFeature(this)">
                        <div class="feature-header">
                            <div class="feature-icon">🤖</div>
                            <div class="feature-info">
                                <h3>Розумний підбір товарів <span class="badge badge-success">Працює</span></h3>
                                <p>GPT аналізує запит клієнта і підбирає найкращі товари з урахуванням контексту, бюджету та вподобань.</p>
                            </div>
                        </div>
                        <div class="feature-expand">
                            Показати демо
                            <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                                <path d="M2 4L6 8L10 4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div class="feature-demo">
                            <div class="demo-chat">
                                <div class="demo-message user">
                                    <div class="demo-avatar">👤</div>
                                    <div class="demo-bubble">Шукаю тактичний рюкзак до 3000 грн</div>
                                </div>
                                <div class="demo-message">
                                    <div class="demo-avatar">🤖</div>
                                    <div class="demo-bubble">
                                        Ось що підібрав для вас:
                                        <div class="demo-product">
                                            <div class="demo-product-img">🎒</div>
                                            <div class="demo-product-info">
                                                <h5>Рюкзак M-TAC Assault Pack</h5>
                                                <span>2 450 ₴</span>
                                            </div>
                                        </div>
                                        <div class="demo-product">
                                            <div class="demo-product-img">🎒</div>
                                            <div class="demo-product-info">
                                                <h5>Рюкзак Helikon-Tex EDC</h5>
                                                <span>2 890 ₴</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Feature 2: Streaming -->
                    <div class="feature-card" onclick="toggleFeature(this)">
                        <div class="feature-header">
                            <div class="feature-icon">⚡</div>
                            <div class="feature-info">
                                <h3>Streaming відповіді <span class="badge badge-success">Працює</span></h3>
                                <p>Відповіді з'являються миттєво — клієнт бачить текст у реальному часі через SSE, без затримок.</p>
                            </div>
                        </div>
                        <div class="feature-expand">
                            Показати демо
                            <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                                <path d="M2 4L6 8L10 4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div class="feature-demo">
                            <div class="demo-chat">
                                <div class="demo-message user">
                                    <div class="demo-avatar">👤</div>
                                    <div class="demo-bubble">Розкажи про цей товар детальніше</div>
                                </div>
                                <div class="demo-message">
                                    <div class="demo-avatar">🤖</div>
                                    <div class="demo-bubble" id="streaming-demo">
                                        <span class="typing-text"></span>
                                        <span class="cursor">|</span>
                                    </div>
                                </div>
                            </div>
                            <p style="font-size: 12px; color: var(--text-gray); margin-top: 12px; text-align: center;">
                                ⚡ Текст з'являється посимвольно в реальному часі
                            </p>
                        </div>
                    </div>

                    <!-- Feature 3: Context Memory -->
                    <div class="feature-card" onclick="toggleFeature(this)">
                        <div class="feature-header">
                            <div class="feature-icon">🎯</div>
                            <div class="feature-info">
                                <h3>Контекстна пам'ять <span class="badge badge-success">Працює</span></h3>
                                <p>Бот запам'ятовує історію діалогу, категорії, розміри та бюджет — природна розмова.</p>
                            </div>
                        </div>
                        <div class="feature-expand">
                            Показати демо
                            <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                                <path d="M2 4L6 8L10 4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div class="feature-demo">
                            <div class="demo-chat">
                                <div class="demo-message user">
                                    <div class="demo-avatar">👤</div>
                                    <div class="demo-bubble">Покажи футболки розмір L</div>
                                </div>
                                <div class="demo-message">
                                    <div class="demo-avatar">🤖</div>
                                    <div class="demo-bubble">Ось футболки розміру L... [показано 3 товари]</div>
                                </div>
                                <div class="demo-message user">
                                    <div class="demo-avatar">👤</div>
                                    <div class="demo-bubble">А є в чорному кольорі?</div>
                                </div>
                                <div class="demo-message">
                                    <div class="demo-avatar">🤖</div>
                                    <div class="demo-bubble">Так! Ось <b>чорні футболки розміру L</b>: [показано 2 товари]</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Feature 4: Meilisearch -->
                    <div class="feature-card" onclick="toggleFeature(this)">
                        <div class="feature-header">
                            <div class="feature-icon">🔍</div>
                            <div class="feature-info">
                                <h3>Meilisearch пошук <span class="badge badge-success">Працює</span></h3>
                                <p>Блискавичний пошук з підтримкою фільтрів, синонімів та typo-tolerance.</p>
                            </div>
                        </div>
                        <div class="feature-expand">
                            Показати демо
                            <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                                <path d="M2 4L6 8L10 4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div class="feature-demo">
                            <div class="demo-chat">
                                <div class="demo-message user">
                                    <div class="demo-avatar">👤</div>
                                    <div class="demo-bubble">тактічна разгрузка олива</div>
                                </div>
                                <div class="demo-message">
                                    <div class="demo-avatar">🤖</div>
                                    <div class="demo-bubble">
                                        🔍 Знайдено за 12ms:
                                        <div class="demo-product">
                                            <div class="demo-product-img">🦺</div>
                                            <div class="demo-product-info">
                                                <h5>Розвантажувальний жилет Olive</h5>
                                                <span>3 200 ₴</span>
                                            </div>
                                        </div>
                                        <small style="color: var(--text-gray);">✓ Виправлено: "тактічна" → "тактична"</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Feature 5: Widget Customization -->
                    <div class="feature-card" onclick="toggleFeature(this)">
                        <div class="feature-header">
                            <div class="feature-icon">🎨</div>
                            <div class="feature-info">
                                <h3>Кастомізація віджета <span class="badge badge-success">Працює</span></h3>
                                <p>Кольори, шрифти, положення, привітання — все налаштовується під ваш бренд.</p>
                            </div>
                        </div>
                        <div class="feature-expand">
                            Показати демо
                            <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                                <path d="M2 4L6 8L10 4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div class="feature-demo">
                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;">
                                <div style="background: linear-gradient(135deg, #10b981, #059669); padding: 16px; border-radius: 12px; color: white; text-align: center; font-size: 12px;">
                                    🌿 Green Theme
                                </div>
                                <div style="background: linear-gradient(135deg, #6366f1, #4f46e5); padding: 16px; border-radius: 12px; color: white; text-align: center; font-size: 12px;">
                                    💜 Purple Theme
                                </div>
                                <div style="background: linear-gradient(135deg, #1f2937, #111827); padding: 16px; border-radius: 12px; color: white; text-align: center; font-size: 12px;">
                                    🖤 Dark Theme
                                </div>
                            </div>
                            <p style="font-size: 12px; color: var(--text-gray); margin-top: 12px; text-align: center;">
                                + Позиція, аватар, текст привітання, анімації
                            </p>
                        </div>
                    </div>

                    <!-- Feature 6: Analytics -->
                    <div class="feature-card" onclick="toggleFeature(this)">
                        <div class="feature-header">
                            <div class="feature-icon">📊</div>
                            <div class="feature-info">
                                <h3>Базова аналітика <span class="badge badge-success">Працює</span></h3>
                                <p>Трекінг взаємодій, популярних запитів та конверсій. Dashboard з метриками.</p>
                            </div>
                        </div>
                        <div class="feature-expand">
                            Показати демо
                            <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                                <path d="M2 4L6 8L10 4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div class="feature-demo">
                            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
                                <div style="background: var(--bg-light); padding: 16px; border-radius: 12px; text-align: center;">
                                    <div style="font-size: 28px; font-weight: 700; color: var(--primary);">1,247</div>
                                    <div style="font-size: 12px; color: var(--text-gray);">Діалогів сьогодні</div>
                                </div>
                                <div style="background: var(--bg-light); padding: 16px; border-radius: 12px; text-align: center;">
                                    <div style="font-size: 28px; font-weight: 700; color: var(--primary);">23%</div>
                                    <div style="font-size: 12px; color: var(--text-gray);">Конверсія в кошик</div>
                                </div>
                                <div style="background: var(--bg-light); padding: 16px; border-radius: 12px; text-align: center;">
                                    <div style="font-size: 28px; font-weight: 700; color: var(--primary);">4.8s</div>
                                    <div style="font-size: 12px; color: var(--text-gray);">Avg. response time</div>
                                </div>
                                <div style="background: var(--bg-light); padding: 16px; border-radius: 12px; text-align: center;">
                                    <div style="font-size: 28px; font-weight: 700; color: var(--primary);">89%</div>
                                    <div style="font-size: 12px; color: var(--text-gray);">Задоволених</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Interactive Demo Section -->
        <section class="demo-section" id="demo">
            <div class="container">
                <div class="demo-container">
                    <div class="demo-info">
                        <h2>Спробуйте прямо зараз</h2>
                        <p>Оберіть сценарій і подивіться, як працює AIntento у реальному діалозі з клієнтом.</p>
                        <div class="scenario-buttons">
                            <button class="scenario-btn active" onclick="runScenario(0)">🎒 Пошук рюкзака</button>
                            <button class="scenario-btn" onclick="runScenario(1)">👕 Підбір одягу</button>
                            <button class="scenario-btn" onclick="runScenario(2)">❓ Питання про доставку</button>
                        </div>
                    </div>
                    <div class="chat-widget-demo">
                        <div class="chat-widget-header">
                            <h4>🤖 AIntento Assistant</h4>
                            <span>Онлайн • Відповідаю миттєво</span>
                        </div>
                        <div class="chat-widget-body" id="demo-chat-body">
                            <div class="demo-message">
                                <div class="demo-avatar">🤖</div>
                                <div class="demo-bubble">Привіт! Я AI-асистент магазину. Чим можу допомогти?</div>
                            </div>
                        </div>
                        <div class="chat-widget-input">
                            <input type="text" placeholder="Напишіть повідомлення..." id="demo-input" onkeypress="handleDemoInput(event)">
                            <button onclick="sendDemoMessage()">→</button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Coming Soon Section -->
        <section class="coming-soon">
            <div class="container">
                <h2 class="section-title">Скоро в AIntento</h2>
                <p class="section-subtitle">Функції, над якими ми працюємо</p>
                
                <div class="coming-grid">
                    <div class="coming-card">
                        <h4>
                            <span>🧠</span>
                            AI Рекомендації
                            <span class="badge badge-soon">Soon</span>
                        </h4>
                        <p>Персоналізовані рекомендації на основі історії переглядів та покупок кожного клієнта.</p>
                    </div>

                    <div class="coming-card">
                        <h4>
                            <span>🎯</span>
                            Міні-квіз
                            <span class="badge badge-soon">Soon</span>
                        </h4>
                        <p>Інтерактивний квіз для швидкого підбору товарів за відповідями клієнта.</p>
                    </div>

                    <div class="coming-card">
                        <h4>
                            <span>📊</span>
                            Розширена аналітика
                            <span class="badge badge-soon">Soon</span>
                        </h4>
                        <p>Глибокий аналіз продажів, AI-інсайти для покращення асортименту.</p>
                    </div>

                    <div class="coming-card">
                        <h4>
                            <span>💡</span>
                            Оптимізація каталогу
                            <span class="badge badge-soon">Soon</span>
                        </h4>
                        <p>AI аналізує запити і пропонує що додати/змінити для збільшення продажів.</p>
                    </div>

                    <div class="coming-card">
                        <h4>
                            <span>🔔</span>
                            Push-нотифікації
                            <span class="badge badge-soon">Soon</span>
                        </h4>
                        <p>Розумні пуші про знижки та новинки на основі інтересів клієнта.</p>
                    </div>

                    <div class="coming-card">
                        <h4>
                            <span>🎨</span>
                            AI-контент
                            <span class="badge badge-soon">Soon</span>
                        </h4>
                        <p>Автоматична генерація описів товарів та SEO-текстів.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer>
            <div class="container">
                <div class="footer-content">
                    <p>© 2025 AIntento — AI асистент для вашого e-commerce</p>
                    <div class="footer-links">
                        <a href="/admin">Адмін-панель</a>
                        <a href="https://laravel.com/docs" target="_blank">Документація</a>
                        <a href="mailto:support@aimbot.laravel.cloud">Підтримка</a>
                    </div>
                </div>
            </div>
        </footer>

        <script>
            // Toggle feature cards
            function toggleFeature(card) {
                const wasExpanded = card.classList.contains('expanded');
                
                // Close all
                document.querySelectorAll('.feature-card').forEach(c => {
                    c.classList.remove('expanded');
                });
                
                // Toggle clicked
                if (!wasExpanded) {
                    card.classList.add('expanded');
                    
                    // Start streaming animation if it's the streaming demo
                    if (card.querySelector('#streaming-demo')) {
                        startStreamingDemo();
                    }
                }
            }
            
            // Streaming demo animation
            function startStreamingDemo() {
                const text = "Цей рюкзак має об'єм 35 літрів, виготовлений з водостійкого нейлону 1000D. Є MOLLE-система для кріплення додаткових підсумків. Відмінно підходить для походів та щоденного використання.";
                const element = document.querySelector('#streaming-demo .typing-text');
                if (!element) return;
                
                element.textContent = '';
                let i = 0;
                
                const interval = setInterval(() => {
                    if (i < text.length) {
                        element.textContent += text[i];
                        i++;
                    } else {
                        clearInterval(interval);
                    }
                }, 30);
            }
            
            // Demo scenarios
            const scenarios = [
                // Scenario 0: Backpack search
                [
                    { role: 'user', text: 'Привіт, шукаю рюкзак для походів' },
                    { role: 'bot', text: 'Вітаю! 🎒 Який об\'єм вам потрібен? І є якийсь бюджет?' },
                    { role: 'user', text: 'Десь 30-40 літрів, до 4000 грн' },
                    { role: 'bot', text: 'Чудово! Ось що підібрав для вас:', products: [
                        { name: 'Рюкзак M-TAC Large', price: '3 450 ₴', emoji: '🎒' },
                        { name: 'Helikon-Tex Raccoon', price: '3 890 ₴', emoji: '🎒' }
                    ]}
                ],
                // Scenario 1: Clothing
                [
                    { role: 'user', text: 'Потрібна тактична футболка' },
                    { role: 'bot', text: 'Який розмір носите? І є переваги по кольору?' },
                    { role: 'user', text: 'L, краще олива або койот' },
                    { role: 'bot', text: 'Маю варіанти в обох кольорах:', products: [
                        { name: 'Футболка CoolMax Olive L', price: '890 ₴', emoji: '👕' },
                        { name: 'Футболка M-TAC Coyote L', price: '750 ₴', emoji: '👕' }
                    ]}
                ],
                // Scenario 2: Delivery question
                [
                    { role: 'user', text: 'Як швидко доставите у Львів?' },
                    { role: 'bot', text: 'До Львова доставка займає 1-2 дні 🚚\n\n• Нова Пошта — 1-2 дні\n• УкрПошта — 3-5 днів\n• Самовивіз — безкоштовно\n\nВідправляємо в день замовлення до 15:00!' },
                    { role: 'user', text: 'А безкоштовна доставка є?' },
                    { role: 'bot', text: 'Так! 🎁 Безкоштовна доставка при замовленні від 2000 грн. У вас в кошику поки що товарів немає — давайте підберемо щось?' }
                ]
            ];
            
            let currentScenario = 0;
            let messageIndex = 0;
            let isPlaying = false;
            
            function runScenario(index) {
                // Update buttons
                document.querySelectorAll('.scenario-btn').forEach((btn, i) => {
                    btn.classList.toggle('active', i === index);
                });
                
                currentScenario = index;
                messageIndex = 0;
                
                // Clear chat
                const chatBody = document.getElementById('demo-chat-body');
                chatBody.innerHTML = '<div class="demo-message"><div class="demo-avatar">🤖</div><div class="demo-bubble">Привіт! Я AI-асистент магазину. Чим можу допомогти?</div></div>';
                
                // Start scenario
                playNextMessage();
            }
            
            function playNextMessage() {
                if (isPlaying) return;
                
                const scenario = scenarios[currentScenario];
                if (messageIndex >= scenario.length) return;
                
                isPlaying = true;
                const message = scenario[messageIndex];
                const chatBody = document.getElementById('demo-chat-body');
                
                // Add typing indicator for bot
                if (message.role === 'bot') {
                    const typingDiv = document.createElement('div');
                    typingDiv.className = 'demo-message';
                    typingDiv.id = 'typing-indicator';
                    typingDiv.innerHTML = '<div class="demo-avatar">🤖</div><div class="typing-indicator"><span></span><span></span><span></span></div>';
                    chatBody.appendChild(typingDiv);
                    chatBody.scrollTop = chatBody.scrollHeight;
                    
                    setTimeout(() => {
                        document.getElementById('typing-indicator')?.remove();
                        addMessage(message);
                    }, 1000);
                } else {
                    setTimeout(() => addMessage(message), 500);
                }
            }
            
            function addMessage(message) {
                const chatBody = document.getElementById('demo-chat-body');
                const div = document.createElement('div');
                div.className = 'demo-message ' + (message.role === 'user' ? 'user' : '');
                
                let content = '<div class="demo-avatar">' + (message.role === 'user' ? '👤' : '🤖') + '</div><div class="demo-bubble">' + (message.text || '');
                
                if (message.products) {
                    message.products.forEach(p => {
                        content += '<div class="demo-product"><div class="demo-product-img">' + p.emoji + '</div><div class="demo-product-info"><h5>' + p.name + '</h5><span>' + p.price + '</span></div></div>';
                    });
                }
                
                content += '</div>';
                div.innerHTML = content;
                chatBody.appendChild(div);
                chatBody.scrollTop = chatBody.scrollHeight;
                
                messageIndex++;
                isPlaying = false;
                
                // Auto-play next after delay
                setTimeout(playNextMessage, 1500);
            }
            
            function handleDemoInput(event) {
                if (event.key === 'Enter') {
                    sendDemoMessage();
                }
            }
            
            function sendDemoMessage() {
                const input = document.getElementById('demo-input');
                const text = input.value.trim();
                if (!text) return;
                
                const chatBody = document.getElementById('demo-chat-body');
                
                // Add user message
                const userDiv = document.createElement('div');
                userDiv.className = 'demo-message user';
                userDiv.innerHTML = '<div class="demo-avatar">👤</div><div class="demo-bubble">' + text + '</div>';
                chatBody.appendChild(userDiv);
                
                input.value = '';
                chatBody.scrollTop = chatBody.scrollHeight;
                
                // Add typing indicator
                const typingDiv = document.createElement('div');
                typingDiv.className = 'demo-message';
                typingDiv.id = 'typing-indicator';
                typingDiv.innerHTML = '<div class="demo-avatar">🤖</div><div class="typing-indicator"><span></span><span></span><span></span></div>';
                chatBody.appendChild(typingDiv);
                chatBody.scrollTop = chatBody.scrollHeight;
                
                // Bot response
                setTimeout(() => {
                    document.getElementById('typing-indicator')?.remove();
                    
                    const responses = [
                        'Цікаве питання! Давайте підберемо для вас найкращі варіанти. Який у вас бюджет?',
                        'Зрозумів! У нас є чудові варіанти. Є якісь особливі вимоги?',
                        'Дякую за запитання! Я можу показати вам декілька відмінних товарів.',
                        'Чудово! Підкажіть, будь ласка, який розмір вам потрібен?'
                    ];
                    
                    const botDiv = document.createElement('div');
                    botDiv.className = 'demo-message';
                    botDiv.innerHTML = '<div class="demo-avatar">🤖</div><div class="demo-bubble">' + responses[Math.floor(Math.random() * responses.length)] + '</div>';
                    chatBody.appendChild(botDiv);
                    chatBody.scrollTop = chatBody.scrollHeight;
                }, 1500);
            }
            
            // Auto-start first scenario on page load
            document.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => runScenario(0), 1000);
            });
        </script>
    </body>
</html>

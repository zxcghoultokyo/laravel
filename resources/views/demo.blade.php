<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AIntento — AI Chat для інтернет-магазинів</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />
    <style>
        :root {
            --primary: #22c55e;
            --primary-dark: #16a34a;
            --bg: #0a0a0a;
            --surface: #161616;
            --surface-hover: #1f1f1f;
            --border: #2a2a2a;
            --text: #fafafa;
            --text-muted: #a1a1aa;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 2rem;
            border-bottom: 1px solid var(--border);
            background: var(--surface);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.25rem;
            font-weight: 700;
        }

        .logo-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, var(--primary), #10b981);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .nav-links {
            display: flex;
            gap: 1.5rem;
        }

        .nav-links a {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.875rem;
            transition: color 0.2s;
        }

        .nav-links a:hover {
            color: var(--text);
        }

        .hero {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 3rem 1.5rem;
            background: radial-gradient(ellipse at center top, rgba(34, 197, 94, 0.1), transparent 60%);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 9999px;
            font-size: 0.75rem;
            color: var(--primary);
            margin-bottom: 1.5rem;
        }

        .badge::before {
            content: '';
            width: 6px;
            height: 6px;
            background: var(--primary);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        h1 {
            font-size: clamp(2rem, 5vw, 3.5rem);
            font-weight: 700;
            line-height: 1.1;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, var(--text) 0%, var(--text-muted) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        h1 span {
            background: linear-gradient(135deg, var(--primary), #10b981);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .subtitle {
            font-size: 1.125rem;
            color: var(--text-muted);
            max-width: 600px;
            margin-bottom: 2.5rem;
            line-height: 1.6;
        }

        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            max-width: 800px;
            margin-bottom: 3rem;
            transition: all 0.5s ease;
        }

        .features.shifted {
            max-width: 400px;
            grid-template-columns: 1fr;
        }

        .features-container {
            display: flex;
            align-items: flex-start;
            gap: 2rem;
            justify-content: center;
            width: 100%;
            max-width: 1000px;
        }

        .feature {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            transition: all 0.3s;
            cursor: pointer;
            user-select: none;
        }

        .feature:hover {
            background: var(--surface-hover);
            border-color: rgba(34, 197, 94, 0.3);
            transform: translateX(-4px);
        }

        .feature.active {
            background: rgba(34, 197, 94, 0.15);
            border-color: var(--primary);
            box-shadow: 0 0 20px rgba(34, 197, 94, 0.2);
        }

        .feature-icon {
            font-size: 1.5rem;
        }

        .feature-text {
            font-size: 0.875rem;
            color: var(--text-muted);
        }

        .feature.active .feature-text {
            color: var(--text);
        }

        /* Feature Demo Panel */
        .feature-demo {
            width: 0;
            opacity: 0;
            overflow: hidden;
            transition: all 0.5s ease;
            flex-shrink: 0;
        }

        .feature-demo.visible {
            width: 380px;
            opacity: 1;
        }

        .feature-demo-inner {
            width: 380px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }

        .feature-demo-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1rem;
            background: linear-gradient(135deg, var(--primary), #10b981);
        }

        .feature-demo-header h4 {
            font-size: 0.875rem;
            color: white;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .feature-demo-close {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }

        .feature-demo-close:hover {
            background: rgba(255,255,255,0.3);
        }

        .feature-demo-chat {
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            max-height: 350px;
            overflow-y: auto;
        }

        .chat-msg {
            padding: 0.75rem 1rem;
            border-radius: 12px;
            font-size: 0.8125rem;
            line-height: 1.5;
            animation: msgFadeIn 0.3s ease-out;
            animation-fill-mode: both;
        }

        .chat-msg:nth-child(1) { animation-delay: 0.1s; }
        .chat-msg:nth-child(2) { animation-delay: 0.3s; }
        .chat-msg:nth-child(3) { animation-delay: 0.5s; }
        .chat-msg:nth-child(4) { animation-delay: 0.7s; }
        .chat-msg:nth-child(5) { animation-delay: 0.9s; }

        @keyframes msgFadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .chat-msg.user {
            background: var(--border);
            margin-left: 2rem;
            border-bottom-right-radius: 4px;
        }

        .chat-msg.bot {
            background: rgba(34, 197, 94, 0.1);
            margin-right: 2rem;
            border-bottom-left-radius: 4px;
        }

        .chat-msg .product-card {
            margin-top: 0.5rem;
            padding: 0.5rem;
            background: var(--surface-hover);
            border-radius: 8px;
            border: 1px solid var(--border);
        }

        .chat-msg .product-card strong {
            color: var(--primary);
            font-size: 0.75rem;
        }

        .chat-msg .product-card p {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }

        .cta-group {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            justify-content: center;
            margin-top: 2rem;
            position: relative;
            z-index: 1;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.875rem 1.75rem;
            border-radius: 12px;
            font-size: 0.9375rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            cursor: pointer;
            border: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 4px 14px rgba(34, 197, 94, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(34, 197, 94, 0.4);
        }

        .btn-secondary {
            background: var(--surface);
            color: var(--text);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--surface-hover);
            border-color: var(--text-muted);
        }

        .demo-preview {
            margin-top: 3rem;
            padding: 1rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            max-width: 400px;
            width: 100%;
        }

        .demo-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1rem;
        }

        .demo-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary), #10b981);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .demo-info h3 {
            font-size: 0.875rem;
            font-weight: 600;
        }

        .demo-info p {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .demo-message {
            background: rgba(34, 197, 94, 0.1);
            padding: 0.875rem 1rem;
            border-radius: 12px;
            font-size: 0.875rem;
            line-height: 1.5;
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Coming Soon Section */
        .coming-soon {
            margin-top: 3rem;
            max-width: 900px;
            width: 100%;
        }

        .coming-soon-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            cursor: pointer;
            user-select: none;
        }

        .coming-soon-header h3 {
            font-size: 1rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .coming-soon-header .badge-coming {
            padding: 0.25rem 0.75rem;
            background: rgba(251, 191, 36, 0.15);
            border: 1px solid rgba(251, 191, 36, 0.3);
            border-radius: 9999px;
            font-size: 0.625rem;
            color: #fbbf24;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .coming-soon-header .arrow {
            transition: transform 0.3s ease;
            color: var(--text-muted);
        }

        .coming-soon.expanded .coming-soon-header .arrow {
            transform: rotate(180deg);
        }

        .coming-soon-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.75rem;
            max-height: 0;
            overflow: hidden;
            opacity: 0;
            transition: all 0.4s ease;
        }

        .coming-soon.expanded .coming-soon-grid {
            max-height: 600px;
            opacity: 1;
            padding-top: 0.5rem;
        }

        .coming-feature {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1rem;
            background: var(--surface);
            border: 1px dashed var(--border);
            border-radius: 10px;
            opacity: 0.7;
            transition: all 0.2s;
        }

        .coming-feature:hover {
            opacity: 1;
            border-color: rgba(251, 191, 36, 0.4);
            background: rgba(251, 191, 36, 0.05);
        }

        .coming-feature-icon {
            font-size: 1.25rem;
            filter: grayscale(0.3);
        }

        .coming-feature-text {
            font-size: 0.8125rem;
            color: var(--text-muted);
        }

        .coming-feature-badge {
            margin-left: auto;
            padding: 0.125rem 0.5rem;
            background: rgba(251, 191, 36, 0.1);
            border-radius: 4px;
            font-size: 0.625rem;
            color: #fbbf24;
        }

        .tech-stack {
            margin-top: 2rem;
            margin-left: auto;
            margin-right: auto;
            padding: 1.5rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            max-width: 500px;
            width: 100%;
        }

        .tech-stack h3 {
            font-size: 0.875rem;
            margin-bottom: 1rem;
            color: var(--text-muted);
        }

        .tech-items {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .tech-item {
            padding: 0.375rem 0.75rem;
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 6px;
            font-size: 0.75rem;
            color: var(--primary);
        }

        .footer {
            padding: 1.5rem;
            text-align: center;
            border-top: 1px solid var(--border);
            background: var(--surface);
        }

        .footer p {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .footer a {
            color: var(--primary);
            text-decoration: none;
        }

        /* Mobile demo containers - hidden by default */
        .feature-demo-mobile {
            display: none;
            grid-column: 1 / -1;
            width: 100%;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease, padding 0.3s ease;
        }

        .feature-demo-mobile.visible {
            display: block;
            max-height: 500px;
            padding: 1rem 0;
        }

        @media (max-width: 768px) {
            .navbar {
                padding: 1rem;
            }
            .nav-links {
                display: none;
            }
            .hero {
                padding: 2rem 1rem;
            }
            .features-container {
                flex-direction: column;
                align-items: center;
            }
            .features {
                grid-template-columns: 1fr;
                max-width: 400px;
                width: 100%;
                margin: 0 auto;
                gap: 0.5rem;
            }
            .feature {
                justify-content: flex-start;
            }
            /* Hide desktop demo panel on mobile */
            .feature-demo {
                display: none !important;
            }
            .features.shifted {
                max-width: 400px;
            }
            /* Mobile demo styling */
            .feature-demo-mobile.visible {
                display: block;
            }
            .feature-demo-mobile .feature-demo-inner {
                background: var(--card-bg);
                border: 1px solid var(--border-color);
                border-radius: 12px;
                overflow: hidden;
            }
            .feature-demo-mobile .feature-demo-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 0.75rem 1rem;
                background: rgba(0, 122, 255, 0.1);
                border-bottom: 1px solid var(--border-color);
            }
            .feature-demo-mobile .feature-demo-header h4 {
                margin: 0;
                font-size: 0.9rem;
            }
            .feature-demo-mobile .feature-demo-close {
                background: none;
                border: none;
                font-size: 1.5rem;
                color: var(--text-secondary);
                cursor: pointer;
                padding: 0;
                line-height: 1;
            }
            .feature-demo-mobile .feature-demo-chat {
                padding: 1rem;
                max-height: 350px;
                overflow-y: auto;
            }
            .cta-group {
                margin-top: 1.5rem;
            }
            .tech-stack {
                flex-direction: column;
                align-items: center;
            }
            .tech-item {
                width: 100%;
                max-width: 300px;
            }
        }

        @media (min-width: 769px) {
            /* Hide mobile demos on desktop */
            .feature-demo-mobile {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="logo">
            <div class="logo-icon">🤖</div>
            <span>AIntento</span>
        </div>
        <div class="nav-links">
            <a href="https://contractor.kiev.ua" target="_blank">Contractor</a>
            <a href="/admin">Admin</a>
            <a href="/chat">Test Chat</a>
        </div>
    </nav>

    <main class="hero">
        <div class="badge">
            🚀 AI на базі OpenAI (GPT-4o/5) + Meilisearch
        </div>

        <h1>
            AI-асистент для<br>
            <span>інтернет-магазинів</span>
        </h1>

        <p class="subtitle">
            Розумний чат-бот для платформи Хорошоп. Допомагає клієнтам знайти товари, відстежити замовлення та отримати консультацію. 
            Демо на базі <a href="https://contractor.kiev.ua" target="_blank" style="color: var(--primary);">contractor.kiev.ua</a>
        </p>

        <div class="features-container">
            <div class="features" id="featuresGrid">
                <div class="feature" data-feature="search" onclick="showFeatureDemo('search', this)">
                    <span class="feature-icon">🔍</span>
                    <span class="feature-text">Пошук товарів з AI-ранжуванням</span>
                </div>
                <div class="feature-demo-mobile" data-for="search"></div>
                
                <div class="feature" data-feature="tracking" onclick="showFeatureDemo('tracking', this)">
                    <span class="feature-icon">📦</span>
                    <span class="feature-text">Відстеження замовлень НП</span>
                </div>
                <div class="feature-demo-mobile" data-for="tracking"></div>
                
                <div class="feature" data-feature="faq" onclick="showFeatureDemo('faq', this)">
                    <span class="feature-icon">💬</span>
                    <span class="feature-text">FAQ та консультації</span>
                </div>
                <div class="feature-demo-mobile" data-for="faq"></div>
                
                <div class="feature" data-feature="crosssell" onclick="showFeatureDemo('crosssell', this)">
                    <span class="feature-icon">🛒</span>
                    <span class="feature-text">Cross-sell пропозиції</span>
                </div>
                <div class="feature-demo-mobile" data-for="crosssell"></div>
                
                <div class="feature" data-feature="sizes" onclick="showFeatureDemo('sizes', this)">
                    <span class="feature-icon">📐</span>
                    <span class="feature-text">Розміри та наявність</span>
                </div>
                <div class="feature-demo-mobile" data-for="sizes"></div>
                
                <div class="feature" data-feature="multilang" onclick="showFeatureDemo('multilang', this)">
                    <span class="feature-icon">🌍</span>
                    <span class="feature-text">Мультимовність (UA/EN/RU)</span>
                </div>
                <div class="feature-demo-mobile" data-for="multilang"></div>
                
                <div class="feature" data-feature="streaming" onclick="showFeatureDemo('streaming', this)">
                    <span class="feature-icon">⚡</span>
                    <span class="feature-text">SSE Streaming відповіді</span>
                </div>
                <div class="feature-demo-mobile" data-for="streaming"></div>
                
                <div class="feature" data-feature="context" onclick="showFeatureDemo('context', this)">
                    <span class="feature-icon">🧠</span>
                    <span class="feature-text">Контекст сесії</span>
                </div>
                <div class="feature-demo-mobile" data-for="context"></div>
            </div>

            <div class="feature-demo" id="featureDemo">
                <div class="feature-demo-inner">
                    <div class="feature-demo-header">
                        <h4><span id="demoPanelIcon">🔍</span> <span id="demoPanelTitle">Демо</span></h4>
                        <button class="feature-demo-close" onclick="hideFeatureDemo()">×</button>
                    </div>
                    <div class="feature-demo-chat" id="demoChatMessages">
                        <!-- Messages will be inserted here -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Coming Soon Section -->
        <div class="coming-soon" id="comingSoon">
            <div class="coming-soon-header" onclick="toggleComingSoon()">
                <h3>
                    <span>🚀</span>
                    Що ще буде
                    <span class="badge-coming">Coming Soon</span>
                </h3>
                <span class="arrow">▼</span>
            </div>
            <div class="coming-soon-grid">
                <div class="coming-feature">
                    <span class="coming-feature-icon">🎯</span>
                    <span class="coming-feature-text">UTM/поведінкові тригери чату</span>
                    <span class="coming-feature-badge">Q1</span>
                </div>
                <div class="coming-feature">
                    <span class="coming-feature-icon">🛒</span>
                    <span class="coming-feature-text">Add to Cart із чату</span>
                    <span class="coming-feature-badge">Q1</span>
                </div>
                <div class="coming-feature">
                    <span class="coming-feature-icon">🧩</span>
                    <span class="coming-feature-text">Міні-квіз для підбору</span>
                    <span class="coming-feature-badge">Q1</span>
                </div>
                <div class="coming-feature">
                    <span class="coming-feature-icon">🏷️</span>
                    <span class="coming-feature-text">Промокоди в чаті</span>
                    <span class="coming-feature-badge">Q1</span>
                </div>
                <div class="coming-feature">
                    <span class="coming-feature-icon">🎙️</span>
                    <span class="coming-feature-text">Speech-to-Text</span>
                    <span class="coming-feature-badge">Q2</span>
                </div>
                <div class="coming-feature">
                    <span class="coming-feature-icon">📅</span>
                    <span class="coming-feature-text">Запис/бронювання</span>
                    <span class="coming-feature-badge">Q2</span>
                </div>
                <div class="coming-feature">
                    <span class="coming-feature-icon">📞</span>
                    <span class="coming-feature-text">Phone/Voice Agent</span>
                    <span class="coming-feature-badge">Q2</span>
                </div>
                <div class="coming-feature">
                    <span class="coming-feature-icon">⚙️</span>
                    <span class="coming-feature-text">Пресети атрибутів пошуку</span>
                    <span class="coming-feature-badge">Q1</span>
                </div>
                <div class="coming-feature">
                    <span class="coming-feature-icon">🎨</span>
                    <span class="coming-feature-text">Кастомізація UI та персони</span>
                    <span class="coming-feature-badge">Q1</span>
                </div>
                <div class="coming-feature">
                    <span class="coming-feature-icon">🔥</span>
                    <span class="coming-feature-text">Social proof «щойно купили»</span>
                    <span class="coming-feature-badge">Q2</span>
                </div>
                <div class="coming-feature">
                    <span class="coming-feature-icon">🔌</span>
                    <span class="coming-feature-text">MCP Protocol для AI-клієнтів</span>
                    <span class="coming-feature-badge">Q2</span>
                </div>
            </div>
        </div>

        <div class="cta-group">
            <button class="btn btn-primary" onclick="openChat()">
                💬 Спробувати чат
            </button>
        </div>

        <div class="demo-preview">
            <div class="demo-header">
                <div class="demo-avatar">🤖</div>
                <div class="demo-info">
                    <h3>AI Асистент</h3>
                    <p>Онлайн • Streaming SSE</p>
                </div>
            </div>
            <div class="demo-message">
                👋 Привіт! Я AI-помічник магазину тактичного спорядження. 
                <br><br>
                <strong>Що я вмію:</strong><br>
                • Знайти товар: "плитоноска до 5000 грн"<br>
                • Перевірити розмір: "а є в 44?"<br>
                • Відстежити посилку: "ТТН 20450..."<br>
                • Відповісти про доставку та оплату
            </div>
        </div>

        <div class="tech-stack">
            <h3>🛠 Технології</h3>
            <div class="tech-items">
                <span class="tech-item">Laravel 12</span>
                <span class="tech-item">OpenAI GPT (chat)</span>
                <span class="tech-item">GPT-4o-mini (analyze)</span>
                <span class="tech-item">Meilisearch</span>
                <span class="tech-item">SSE Streaming</span>
                <span class="tech-item">Horoshop API</span>
                <span class="tech-item">Nova Poshta API</span>
            </div>
        </div>
    </main>

    <footer class="footer">
        <p>
            Powered by <a href="https://laravel.com" target="_blank">Laravel Cloud</a> • 
            Built for <a href="https://horoshop.ua" target="_blank">Хорошоп</a> • 
            Demo: <a href="https://contractor.kiev.ua" target="_blank">Contractor</a>
        </p>
        <p style="margin-top: 8px; font-size: 11px; opacity: 0.7;">
            <a href="/privacy">Конфіденційність</a> • 
            <a href="/terms">Умови</a> • 
            <a href="/refund">Повернення</a> • 
            <a href="/offer">Оферта</a> •
            ФОП Цяцько В.О. • ІПН 3547513490
        </p>
    </footer>

    <!-- AIntento Chat Widget (production version) -->
    <div id="aintento-chat" data-token="demo"></div>
    <script src="/widget.js?v={{ time() }}"></script>

    <script>
        // Feature demo data
        const featureDemos = {
            search: {
                icon: '🔍',
                title: 'Пошук товарів',
                messages: [
                    { type: 'user', text: 'плитоноска до 5000 грн' },
                    { type: 'bot', text: '🎯 Ось що знайшов для тебе:', product: { name: 'Плитоноска АТАКА Quick Release', price: '4,850 грн', desc: 'Швидкоскидна, Мультикам, в наявності' } },
                    { type: 'bot', text: 'Всі 3 варіанти в наявності та підходять під твій бюджет. Перший — найпопулярніший!' }
                ]
            },
            tracking: {
                icon: '📦',
                title: 'Відстеження НП',
                messages: [
                    { type: 'user', text: 'ТТН 20450678901234' },
                    { type: 'bot', text: '📦 <strong>Статус посилки:</strong><br><br>✅ Відправлення прибуло у відділення<br>📍 Київ, відділення №125<br>📅 Очікує отримання з 28.12' },
                    { type: 'bot', text: '💡 Відділення працює до 20:00. Не забудьте паспорт!' }
                ]
            },
            faq: {
                icon: '💬',
                title: 'FAQ',
                messages: [
                    { type: 'user', text: 'як оплатити замовлення?' },
                    { type: 'bot', text: '💳 <strong>Способи оплати:</strong><br><br>• Карткою онлайн (Visa/MC)<br>• Накладений платіж НП<br>• Безготівковий для ФОП/ТОВ<br>• Apple Pay / Google Pay' },
                    { type: 'user', text: 'а безкоштовна доставка є?' },
                    { type: 'bot', text: '🚚 Так! Безкоштовна доставка при замовленні від 2000 грн' }
                ]
            },
            crosssell: {
                icon: '🛒',
                title: 'Cross-sell',
                messages: [
                    { type: 'bot', text: '✨ До твоєї плитоноски рекомендую:', product: { name: 'Підсумок під магазини', price: '890 грн', desc: 'Ідеально підходить до АТАКА' } },
                    { type: 'bot', text: '🎯 Також часто беруть:', product: { name: 'Гідратор 2.5L', price: '650 грн', desc: 'Сумісний з твоєю плитоноскою' } },
                    { type: 'user', text: 'давай обидва' },
                    { type: 'bot', text: '✅ Додав до кошика! Загалом: 6,390 грн' }
                ]
            },
            sizes: {
                icon: '📐',
                title: 'Розміри',
                messages: [
                    { type: 'user', text: 'берці Salomon' },
                    { type: 'bot', text: '🥾 Знайшов Salomon Quest 4D:', product: { name: 'Salomon Quest 4D GTX', price: '8,200 грн', desc: 'Розмір: 44, Чорні' } },
                    { type: 'user', text: 'а є в 42?' },
                    { type: 'bot', text: '✅ Так, є! Розмір 42 в наявності.<br><br>Доступні розміри: <strong>41, 42, 43, 45</strong>' }
                ]
            },
            multilang: {
                icon: '🌍',
                title: 'Мультимовність',
                messages: [
                    { type: 'user', text: 'Do you have tactical gloves?' },
                    { type: 'bot', text: '🧤 Yes! Here are our tactical gloves:', product: { name: 'Mechanix M-Pact', price: '1,450 UAH', desc: 'Black, Size M, in stock' } },
                    { type: 'user', text: 'Есть ли доставка в Польшу?' },
                    { type: 'bot', text: '🌍 Да, доставляем в Польшу через Meest. Срок 5-7 дней, стоимость от 350 грн.' }
                ]
            },
            streaming: {
                icon: '⚡',
                title: 'SSE Streaming',
                messages: [
                    { type: 'user', text: 'розкажи про шолом балістичний' },
                    { type: 'bot', text: '⚡ <em style="color:var(--text-muted)">[відповідь друкується в реальному часі...]</em>' },
                    { type: 'bot', text: '🪖 <strong>Шолом АТАКА Aegis II:</strong><br><br>• Клас захисту: NIJ IIIA<br>• Вага: 1.4 кг<br>• Матеріал: арамід<br>• Рейки та NVG mount' },
                    { type: 'bot', text: '💡 Текст з\'являється поступово — користувач бачить відповідь одразу!' }
                ]
            },
            context: {
                icon: '🧠',
                title: 'Контекст сесії',
                messages: [
                    { type: 'user', text: 'покажи рюкзаки' },
                    { type: 'bot', text: '🎒 Ось тактичні рюкзаки:', product: { name: 'Рюкзак 45L АТАКА', price: '2,800 грн', desc: 'Олива, Molle система' } },
                    { type: 'user', text: 'а більший є?' },
                    { type: 'bot', text: '🧠 Зрозумів — шукаєш рюкзак більше 45L:', product: { name: 'Рюкзак 65L Tactical', price: '3,400 грн', desc: 'Найбільший у лінійці' } },
                    { type: 'user', text: 'ще варіанти' },
                    { type: 'bot', text: '📋 Показую ще 3 рюкзаки 50-70L, яких ще не бачив...' }
                ]
            }
        };

        let currentFeature = null;

        function isMobile() {
            return window.innerWidth <= 768;
        }

        function showFeatureDemo(featureKey, clickedElement) {
            const demo = featureDemos[featureKey];
            if (!demo) return;

            // If same feature clicked - hide it
            if (currentFeature === featureKey) {
                hideFeatureDemo();
                return;
            }

            // Update active state
            document.querySelectorAll('.feature').forEach(f => f.classList.remove('active'));
            document.querySelector(`[data-feature="${featureKey}"]`).classList.add('active');

            // Generate demo content HTML
            const demoHTML = `
                <div class="feature-demo-inner">
                    <div class="feature-demo-header">
                        <h4><span>${demo.icon}</span> ${demo.title}</h4>
                        <button class="feature-demo-close" onclick="hideFeatureDemo()">×</button>
                    </div>
                    <div class="feature-demo-chat">
                        ${demo.messages.map(msg => {
                            let html = `<div class="chat-msg ${msg.type}">${msg.text}`;
                            if (msg.product) {
                                html += `<div class="product-card">
                                    <strong>${msg.product.name}</strong>
                                    <p>${msg.product.price} • ${msg.product.desc}</p>
                                </div>`;
                            }
                            html += '</div>';
                            return html;
                        }).join('')}
                    </div>
                </div>
            `;

            if (isMobile()) {
                // Hide all mobile demos first
                document.querySelectorAll('.feature-demo-mobile').forEach(d => {
                    d.classList.remove('visible');
                    d.innerHTML = '';
                });

                // Show demo below clicked card
                const mobileDemo = document.querySelector(`.feature-demo-mobile[data-for="${featureKey}"]`);
                if (mobileDemo) {
                    mobileDemo.innerHTML = demoHTML;
                    mobileDemo.classList.add('visible');
                    // Smooth scroll to demo
                    setTimeout(() => {
                        mobileDemo.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 100);
                }
            } else {
                // Desktop - shift grid and show side panel
                document.getElementById('featuresGrid').classList.add('shifted');
                document.getElementById('featureDemo').classList.add('visible');
                document.getElementById('demoPanelIcon').textContent = demo.icon;
                document.getElementById('demoPanelTitle').textContent = demo.title;

                // Render messages
                const chatContainer = document.getElementById('demoChatMessages');
                chatContainer.innerHTML = demo.messages.map(msg => {
                    let html = `<div class="chat-msg ${msg.type}">${msg.text}`;
                    if (msg.product) {
                        html += `<div class="product-card">
                            <strong>${msg.product.name}</strong>
                            <p>${msg.product.price} • ${msg.product.desc}</p>
                        </div>`;
                    }
                    html += '</div>';
                    return html;
                }).join('');
            }

            currentFeature = featureKey;
        }

        function hideFeatureDemo() {
            document.querySelectorAll('.feature').forEach(f => f.classList.remove('active'));
            document.getElementById('featuresGrid').classList.remove('shifted');
            document.getElementById('featureDemo').classList.remove('visible');
            
            // Hide mobile demos
            document.querySelectorAll('.feature-demo-mobile').forEach(d => {
                d.classList.remove('visible');
                d.innerHTML = '';
            });
            
            currentFeature = null;
        }

        // Coming Soon toggle
        function toggleComingSoon() {
            const section = document.getElementById('comingSoon');
            section.classList.toggle('expanded');
        }

        // Handle window resize - only react to WIDTH changes (not height)
        // Mobile browsers change viewport height when scrolling (hiding address bar)
        let lastWidth = window.innerWidth;
        window.addEventListener('resize', function() {
            const newWidth = window.innerWidth;
            // Only handle actual width changes (orientation change, etc.)
            if (Math.abs(newWidth - lastWidth) > 50 && currentFeature) {
                lastWidth = newWidth;
                const feature = currentFeature;
                hideFeatureDemo();
                setTimeout(() => {
                    showFeatureDemo(feature, null);
                }, 100);
            }
        });
    </script>
</body>
</html>
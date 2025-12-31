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
        }

        .feature {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            transition: all 0.2s;
        }

        .feature:hover {
            background: var(--surface-hover);
            border-color: rgba(34, 197, 94, 0.3);
        }

        .feature-icon {
            font-size: 1.5rem;
        }

        .feature-text {
            font-size: 0.875rem;
            color: var(--text-muted);
        }

        .cta-group {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            justify-content: center;
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

        .tech-stack {
            margin-top: 2rem;
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

        @media (max-width: 640px) {
            .navbar {
                padding: 1rem;
            }
            .nav-links {
                display: none;
            }
            .hero {
                padding: 2rem 1rem;
            }
            .features {
                grid-template-columns: 1fr;
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
            🚀 GPT-4.1 + Meilisearch
        </div>

        <h1>
            AI-асистент для<br>
            <span>інтернет-магазинів</span>
        </h1>

        <p class="subtitle">
            Розумний чат-бот для платформи Хорошоп. Допомагає клієнтам знайти товари, відстежити замовлення та отримати консультацію. 
            Демо на базі <a href="https://contractor.kiev.ua" target="_blank" style="color: var(--primary);">contractor.kiev.ua</a>
        </p>

        <div class="features">
            <div class="feature">
                <span class="feature-icon">🔍</span>
                <span class="feature-text">Пошук товарів з AI-ранжуванням</span>
            </div>
            <div class="feature">
                <span class="feature-icon">📦</span>
                <span class="feature-text">Відстеження замовлень НП</span>
            </div>
            <div class="feature">
                <span class="feature-icon">💬</span>
                <span class="feature-text">FAQ та консультації</span>
            </div>
            <div class="feature">
                <span class="feature-icon">🛒</span>
                <span class="feature-text">Cross-sell пропозиції</span>
            </div>
            <div class="feature">
                <span class="feature-icon">📐</span>
                <span class="feature-text">Розміри та наявність</span>
            </div>
            <div class="feature">
                <span class="feature-icon">🌍</span>
                <span class="feature-text">Мультимовність (UA/EN/RU)</span>
            </div>
            <div class="feature">
                <span class="feature-icon">⚡</span>
                <span class="feature-text">SSE Streaming відповіді</span>
            </div>
            <div class="feature">
                <span class="feature-icon">🧠</span>
                <span class="feature-text">Контекст сесії</span>
            </div>
        </div>

        <div class="cta-group">
            <button class="btn btn-primary" onclick="openChat()">
                💬 Спробувати чат
            </button>
            <a href="/chat" class="btn btn-secondary">
                🧪 Тестова версія
            </a>
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
                <span class="tech-item">GPT-4.1</span>
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
    </footer>

    <!-- AIntento Chat Widget (production version) -->
    <div id="aintento-chat" data-token="demo"></div>
    <script src="/widget.js?v={{ time() }}"></script>
</body>
</html>

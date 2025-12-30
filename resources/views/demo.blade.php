<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Chat Assistant Demo | АТАКА Store</title>
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
            <span>ATAKA AI</span>
        </div>
        <div class="nav-links">
            <a href="/admin">Admin</a>
            <a href="/chat">Test Chat</a>
        </div>
    </nav>

    <main class="hero">
        <div class="badge">
            🚀 GPT-4.1 Function Calling
        </div>

        <h1>
            AI-асистент для<br>
            <span>тактичного магазину</span>
        </h1>

        <p class="subtitle">
            Розумний чат-бот, який допомагає клієнтам знайти товари, відстежити замовлення та отримати консультацію. Інтегрується на будь-який сайт за 5 хвилин.
        </p>

        <div class="features">
            <div class="feature">
                <span class="feature-icon">🔍</span>
                <span class="feature-text">Пошук товарів з AI-ранжуванням</span>
            </div>
            <div class="feature">
                <span class="feature-icon">📦</span>
                <span class="feature-text">Відстеження замовлень</span>
            </div>
            <div class="feature">
                <span class="feature-icon">💬</span>
                <span class="feature-text">FAQ та консультації</span>
            </div>
            <div class="feature">
                <span class="feature-icon">🛒</span>
                <span class="feature-text">Cross-sell пропозиції</span>
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
                    <p>Онлайн • Відповідає миттєво</p>
                </div>
            </div>
            <div class="demo-message">
                👋 Привіт! Я AI-помічник магазину тактичного спорядження. Допоможу знайти товар, відстежити замовлення або відповім на питання.
            </div>
        </div>
    </main>

    <footer class="footer">
        <p>
            Powered by <a href="https://laravel.com" target="_blank">Laravel Cloud</a> • 
            Built with ❤️ for <a href="https://ataka.ua" target="_blank">АТАКА</a>
        </p>
    </footer>

    <!-- Chat Widget -->
    <script>
        (function() {
            const CHAT_API = '{{ url("/api/chat") }}';
            
            let widgetContainer = null;
            let isOpen = false;
            let sessionId = localStorage.getItem('chat_session_id') || 'demo_' + Math.random().toString(36).substr(2, 9);
            localStorage.setItem('chat_session_id', sessionId);
            
            // Create widget button
            const button = document.createElement('div');
            button.id = 'chat-widget-btn';
            button.innerHTML = '💬';
            button.style.cssText = `
                position: fixed;
                bottom: 24px;
                right: 24px;
                width: 60px;
                height: 60px;
                background: linear-gradient(135deg, #22c55e, #16a34a);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 28px;
                cursor: pointer;
                box-shadow: 0 4px 20px rgba(34, 197, 94, 0.4);
                transition: transform 0.2s, box-shadow 0.2s;
                z-index: 9999;
            `;
            button.onmouseover = () => button.style.transform = 'scale(1.1)';
            button.onmouseout = () => button.style.transform = 'scale(1)';
            button.onclick = toggleChat;
            document.body.appendChild(button);
            
            function toggleChat() {
                if (isOpen) {
                    closeChat();
                } else {
                    openChat();
                }
            }
            
            window.openChat = function() {
                if (widgetContainer) {
                    widgetContainer.style.display = 'flex';
                    isOpen = true;
                    button.innerHTML = '✕';
                    return;
                }
                
                widgetContainer = document.createElement('div');
                widgetContainer.id = 'chat-widget';
                widgetContainer.style.cssText = `
                    position: fixed;
                    bottom: 100px;
                    right: 24px;
                    width: 380px;
                    height: 560px;
                    background: #161616;
                    border-radius: 16px;
                    border: 1px solid #2a2a2a;
                    display: flex;
                    flex-direction: column;
                    overflow: hidden;
                    box-shadow: 0 10px 40px rgba(0,0,0,0.5);
                    z-index: 9998;
                    font-family: 'Inter', system-ui, sans-serif;
                `;
                
                widgetContainer.innerHTML = `
                    <div style="display: flex; align-items: center; gap: 12px; padding: 16px; background: #1f1f1f; border-bottom: 1px solid #2a2a2a;">
                        <div style="width: 40px; height: 40px; background: linear-gradient(135deg, #22c55e, #16a34a); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px;">🤖</div>
                        <div>
                            <div style="font-weight: 600; color: #fafafa; font-size: 14px;">AI Асистент</div>
                            <div style="font-size: 12px; color: #71717a;">Онлайн</div>
                        </div>
                    </div>
                    <div id="chat-messages" style="flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 12px;">
                        <div style="background: rgba(34,197,94,0.1); padding: 12px 16px; border-radius: 12px; font-size: 14px; color: #fafafa; line-height: 1.5;">
                            👋 Привіт! Я AI-помічник магазину. Чим можу допомогти?
                        </div>
                        <div id="quick-actions" style="display: flex; flex-wrap: wrap; gap: 8px;">
                            <button onclick="sendQuickAction('Підбери товар')" style="padding: 8px 12px; background: #1f1f1f; border: 1px solid #3a3a3a; border-radius: 20px; color: #fafafa; font-size: 13px; cursor: pointer;">🎯 Підбери товар</button>
                            <button onclick="sendQuickAction('Моє замовлення')" style="padding: 8px 12px; background: #1f1f1f; border: 1px solid #3a3a3a; border-radius: 20px; color: #fafafa; font-size: 13px; cursor: pointer;">📦 Моє замовлення</button>
                            <button onclick="sendQuickAction('Про магазин')" style="padding: 8px 12px; background: #1f1f1f; border: 1px solid #3a3a3a; border-radius: 20px; color: #fafafa; font-size: 13px; cursor: pointer;">ℹ️ Про магазин</button>
                        </div>
                    </div>
                    <div style="padding: 12px; border-top: 1px solid #2a2a2a; display: flex; gap: 8px;">
                        <input type="text" id="chat-input" placeholder="Напишіть повідомлення..." style="flex: 1; padding: 12px 16px; background: #1f1f1f; border: 1px solid #3a3a3a; border-radius: 12px; color: #fafafa; font-size: 14px; outline: none;" onkeypress="if(event.key==='Enter')sendMessage()">
                        <button onclick="sendMessage()" style="padding: 12px 16px; background: linear-gradient(135deg, #22c55e, #16a34a); border: none; border-radius: 12px; color: white; font-weight: 600; cursor: pointer;">→</button>
                    </div>
                `;
                
                document.body.appendChild(widgetContainer);
                isOpen = true;
                button.innerHTML = '✕';
            };
            
            function closeChat() {
                if (widgetContainer) {
                    widgetContainer.style.display = 'none';
                }
                isOpen = false;
                button.innerHTML = '💬';
            }
            
            window.sendQuickAction = function(text) {
                document.getElementById('quick-actions').style.display = 'none';
                document.getElementById('chat-input').value = text;
                sendMessage();
            };
            
            window.sendMessage = async function() {
                const input = document.getElementById('chat-input');
                const messages = document.getElementById('chat-messages');
                const text = input.value.trim();
                
                if (!text) return;
                
                // Add user message
                messages.innerHTML += `
                    <div style="align-self: flex-end; background: #22c55e; padding: 12px 16px; border-radius: 12px; font-size: 14px; color: white; max-width: 80%;">
                        ${text}
                    </div>
                `;
                input.value = '';
                messages.scrollTop = messages.scrollHeight;
                
                // Show typing indicator
                const typingId = 'typing-' + Date.now();
                messages.innerHTML += `
                    <div id="${typingId}" style="background: rgba(34,197,94,0.1); padding: 12px 16px; border-radius: 12px; font-size: 14px; color: #71717a;">
                        <span style="animation: pulse 1s infinite;">●</span> Друкує...
                    </div>
                `;
                messages.scrollTop = messages.scrollHeight;
                
                try {
                    const response = await fetch(CHAT_API, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ message: text, session_id: sessionId })
                    });
                    
                    const data = await response.json();
                    document.getElementById(typingId).remove();
                    
                    // Add bot response
                    let botHtml = `<div style="background: rgba(34,197,94,0.1); padding: 12px 16px; border-radius: 12px; font-size: 14px; color: #fafafa; line-height: 1.5;">`;
                    
                    if (data.data?.text) {
                        botHtml += data.data.text.replace(/\n/g, '<br>');
                    }
                    
                    botHtml += '</div>';
                    messages.innerHTML += botHtml;
                    
                    // Add products if any
                    if (data.data?.products && data.data.products.length > 0) {
                        let productsHtml = '<div style="display: flex; flex-direction: column; gap: 8px; margin-top: 8px;">';
                        data.data.products.slice(0, 3).forEach(p => {
                            productsHtml += `
                                <a href="${p.link || '#'}" target="_blank" style="display: flex; gap: 12px; padding: 12px; background: #1f1f1f; border-radius: 12px; text-decoration: none; border: 1px solid #2a2a2a;">
                                    ${p.image ? `<img src="${p.image}" style="width: 60px; height: 60px; object-fit: cover; border-radius: 8px;">` : ''}
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="font-size: 13px; color: #fafafa; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${p.title}</div>
                                        <div style="font-size: 14px; color: #22c55e; font-weight: 600; margin-top: 4px;">${p.price} грн</div>
                                    </div>
                                </a>
                            `;
                        });
                        productsHtml += '</div>';
                        messages.innerHTML += productsHtml;
                    }
                    
                    messages.scrollTop = messages.scrollHeight;
                    
                } catch (error) {
                    document.getElementById(typingId).remove();
                    messages.innerHTML += `
                        <div style="background: rgba(239,68,68,0.1); padding: 12px 16px; border-radius: 12px; font-size: 14px; color: #ef4444;">
                            Помилка з'єднання. Спробуйте ще раз.
                        </div>
                    `;
                }
            };
        })();
    </script>
</body>
</html>

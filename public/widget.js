(function() {
    'use strict';

    console.log('AILure Chat: скрипт завантажено');

    // Чекаємо на завантаження DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWidget);
    } else {
        initWidget();
    }

    function initWidget() {
        console.log('AILure Chat: ініціалізація віджета...');
        
        const container = document.getElementById('ailure-chat');
        if (!container) {
            console.error('AILure Chat: контейнер #ailure-chat не знайдено');
            return;
        }

        console.log('AILure Chat: контейнер знайдено');

        const token = container.dataset.token;
        if (!token) {
            console.error('AILure Chat: не вказано data-token');
            return;
        }

        console.log('AILure Chat: токен отримано, завантаження налаштувань...');

        // ЗАВЖДИ використовуємо повний URL до нашого сервера
        const apiUrl = 'https://aimbot.laravel.cloud/api/widget/settings';

        fetch(apiUrl, {
            headers: {
                'X-Widget-Token': token,
                'Content-Type': 'application/json'
            }
        })
        .then(res => {
            console.log('AILure Chat: відповідь від сервера отримано', res.status);
            return res.json();
        })
        .then(settings => {
            console.log('AILure Chat: налаштування завантажено', settings);
            renderWidget(container, settings, token);
        })
        .catch(err => {
            console.error('AILure Chat: помилка завантаження налаштувань', err);
            console.log('AILure Chat: використовуються дефолтні налаштування');
            // Рендеримо з дефолтними налаштуваннями
            renderWidget(container, getDefaultSettings(), token);
        });
    }

    function getDefaultSettings() {
        return {
            primary_color: '#2563eb',
            text_color: '#ffffff',
            position: 'right',
            border_radius: 12,
            welcome_message: 'Вітаю! 👋 Я AILure асистент. Чим можу допомогти?',
            input_placeholder: 'Напишіть повідомлення...',
            consent_notice: null,
            enabled: true
        };
    }

    function renderWidget(container, settings, token) {
        console.log('AILure Chat: рендеринг віджета...', settings);
        
        if (!settings.enabled) {
            console.log('AILure Chat: віджет вимкнено в налаштуваннях');
            return;
        }

        const sessionId = getOrCreateSessionId();
        console.log('AILure Chat: session_id:', sessionId);

        // Завантажуємо збережені повідомлення
        const savedMessages = loadMessages(sessionId);

        // Створюємо HTML структуру
        container.innerHTML = `
            <div class="ailure-widget" style="
                position: fixed;
                bottom: 20px;
                ${settings.position === 'right' ? 'right: 20px;' : 'left: 20px;'}
                z-index: 9999;
                font-family: system-ui, -apple-system, sans-serif;
            ">
                <!-- Кнопка відкриття -->
                <button id="ailure-toggle" class="ailure-toggle" style="
                    width: 60px;
                    height: 60px;
                    border-radius: 50%;
                    background: ${settings.primary_color};
                    color: ${settings.text_color};
                    border: none;
                    cursor: pointer;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 24px;
                    transition: transform 0.2s;
                " onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                    💬
                </button>
${settings.start_state === 'open' ? 'flex' : 'none'}
                <!-- Віконце чату -->
                <div id="ailure-window" class="ailure-window" style="
                    display: none;
                    position: absolute;
                    bottom: 70px;
                    ${settings.position === 'right' ? 'right: 0;' : 'left: 0;'}
                    width: 380px;
                    max-height: 600px;
                    background: white;
                    border-radius: ${settings.border_radius}px;
                    box-shadow: 0 8px 24px rgba(0,0,0,0.2);
                    display: flex;
                    flex-direction: column;
                    overflow: hidden;
                ">
                    <!-- Header -->
                    <div class="ailure-header" style="
                        background: ${settings.primary_color};
                        color: ${settings.text_color};
                        padding: 16px;
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                    ">
                        <span style="font-weight: 600;">AILure Асистент</span>
                        <button id="ailure-close" style="
                            background: transparent;
                            border: none;
                            color: ${settings.text_color};
                            font-size: 20px;
                            cursor: pointer;
                            padding: 0;
                            width: 24px;
                            height: 24px;
                        ">✕</button>
                    </div>

                    <!-- Messages -->
                    <div id="ailure-messages" class="ailure-messages" style="
                        flex: 1;
                        overflow-y: auto;
                        padding: 16px;
                        background: #f9fafb;
                        max-height: 450px;
                    ">
                    </div>

                    <!-- Input -->
                    <div class="ailure-input-container" style="
                        padding: 12px;
                        background: white;
                        border-top: 1px solid #e5e7eb;
                    ">
                        <div style="display: flex; gap: 8px;">
                            <input 
                                type="text" 
                                id="ailure-input" 
                                placeholder="${settings.input_placeholder}"
                                style="
                                    flex: 1;
                                    padding: 10px 12px;
                                    border: 1px solid #d1d5db;
                                    border-radius: 8px;
                                    font-size: 14px;
                                    outline: none;
                                "
                            >
                            <button id="ailure-send" style="
                                background: ${settings.primary_color};
                                color: ${settings.text_color};
                                border: none;
                                padding: 10px 16px;
                                border-radius: 8px;
                                cursor: pointer;
                                font-size: 18px;
                                transition: opacity 0.2s;
                            " onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
                                ➤
                            </button>
                        </div>
                        ${settings.consent_notice ? `<p style="margin-top: 8px; font-size: 11px; color: #6b7280;">${settings.consent_notice}</p>` : ''}
                    </div>
                </div>
            </div>
        `;

        // Додаємо обробники подій
        const toggle = document.getElementById('ailure-toggle');
        const close = document.getElementById('ailure-close');
        const window = document.getElementById('ailure-window');
        const input = document.getElementById('ailure-input');
        const send = document.getElementById('ailure-send');
        const messages = document.getElementById('ailure-messages');

        let isOpen = false;

        toggle.addEventListener('click', () => {
            isOpen = !isOpen;
            window.style.display = isOpen ? 'flex' : 'none';
            if (isOpen) {
                input.focus();
            }
        });

        close.addEventListener('click', () => {
            isOpen = false;
            window.style.display = 'none';
        });
settings.start_state === 'open';

        // Відновлюємо історію або показуємо вітальне повідомлення
        if (savedMessages.length > 0) {
            savedMessages.forEach(msg => {
                if (msg.role === 'user') {
                    addMessage(msg.content, 'user', false);
                } else if (msg.role === 'assistant') {
                    addMessage(msg.content, 'assistant', false);
                } else if (msg.role === 'products' && msg.products) {
                    addProducts(msg.products, false);
                }
            });
        } else {
            addMessage(settings.welcome_message, 'assistant', true);
        }
        send.addEventListener('click', sendMessage);
        input.addEventListener('keypress', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        function sendMessage() {
            const message = input.value.trim();
            if (!message) return;

            // Додаємо повідомлення користувача
            addMessage(message, 'user');
            input.value = '';

            // Показуємо індикатор завантаження
            const loader = addLoader();

            // ЗАВЖДИ використовуємо повний URL до нашого сервера
            const chatApiUrl = 'https://aimbot.laravel.cloud/api/chat';

            // Відправляємо на сервер
            fetch(chatApiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Widget-Token': token
                },
                body: JSON.stringify({
                    message: message,
                    session_id: sessionId
                })
            })
            .then(res => res.json())
            .then(data => {
                removeLoader(loader);
                
                // Оновлюємо session_id якщо отримали новий
                if (data.session_id) {
                    saveSessionId(data.session_id);
                }

                // Додаємо відповідь
                addMessage(data.text || 'Вибачте, сталася помилка', 'assistant', true);

                // Якщо є товари - показуємо їх
                if (data.data && data.data.products && data.data.products.length > 0) {
                    addProducts(data.data.products, true);
                }
            })
            .catch(err => {
                removeLoader(loader);
                addMessage('Вибачте, не вдалося надіслати повідомлення. Спробуйте ще раз.', 'assistant');
                console.error('AILure Chat:', err);
            });
        }

        function addMessage(text, role, save = true) {
            const div = document.createElement('div');
            div.className = `ailure-message ailure-${role}`;
            div.style.cssText = `
                margin-bottom: 12px;
                display: flex;
                justify-content: ${role === 'user' ? 'flex-end' : 'flex-start'};
            `;

            const bubble = document.createElement('div');
            bubble.style.cssText = `
                background: ${role === 'user' ? settings.primary_color : '#e5e7eb'};
                color: ${role === 'user' ? settings.text_color : '#1f2937'};
                padding: 10px 14px;
                border-radius: 12px;
                max-width: 80%;
                font-size: 14px;
                line-height: 1.4;
                white-space: pre-wrap;
            `;
            bubble.textContent = text;
            div.appendChild(bubble);
            messages.appendChild(div);
            messages.scrollTop = messages.scrollHeight;

            if (save) {
                saveMessage(sessionId, save = true, { role, content: text });
            }
            messages.scrollTop = messages.scrollHeight;
        }

        function addProducts(products) {
            const container = document.createElement('div');
            container.style.cssText = 'margin-bottom: 12px;';
            
            products.slice(0, 3).forEach(product => {
                const card = document.createElement('a');
                card.href = product.link || '#';
                card.target = '_blank';
                card.style.cssText = `
                    display: block;
                    background: white;
                    border: 1px solid #e5e7eb;
                    border-radius: 8px;
                    padding: 12px;
                    margin-bottom: 8px;
                    text-decoration: none;
                    color: inherit;
                    transition: box-shadow 0.2s;
                `;
                card.onmouseover = () => card.style.boxShadow = '0 2px 8px rgba(0,0,0,0.1)';
                card.onmouseout = () => card.style.boxShadow = 'none';

                card.innerHTML = `
                    <div style="display: flex; gap: 12px;">
                        ${product.images && product.images[0] ? `
                            <img src="${product.images[0]}" style="width: 60px; height: 60px; object-fit: cover; border-radius: 4px;" />
                        ` : ''}
                        <div style="flex: 1;">
                            <div style="font-weight: 600; font-size: 13px; margin-bottom: 4px;">${product.title}</div>
                            <div style="color: ${settings.primary_color}; font-weight: 700; font-size: 15px;">${product.price} ₴</div>
                        </div>
                    </div>
                `;

            if (save) {
                saveMessage(sessionId, { role: 'products', products: products.slice(0, 3) });
            }
                container.appendChild(card);
            });

            messages.appendChild(container);
            messages.scrollTop = messages.scrollHeight;
        }

        function addLoader() {
            const div = document.createElement('div');
            div.className = 'ailure-loader';
            div.style.cssText = 'margin-bottom: 12px; display: flex; justify-content: flex-start;';
            div.innerHTML = `
                <div style="background: #e5e7eb; padding: 10px 14px; border-radius: 12px;">
                    <span style="display: inline-block; animation: ailure-pulse 1.4s infinite;">●</span>
                    <span style="display: inline-block; animation: ailure-pulse 1.4s 0.2s infinite;">●</span>
                    <span style="display: inline-block; animation: ailure-pulse 1.4s 0.4s infinite;">●</span>
                </div>
            `;
            messages.appendChild(div);
            messages.scrollTop = messages.scrollHeight;

            // Додаємо CSS анімацію
            if (!document.getElementById('ailure-animations')) {
                const style = document.createElement('style');
                style.id = 'ailure-animations';
                style.textContent = `
                    @keyframes ailure-pulse {
                        0%, 60%, 100% { opacity: 0.3; }
                        30% { opacity: 1; }
                    }
                `;
                document.head.appendChild(style);
            }

            return div;
        }

        function removeLoader(loader) {
            if (loader && loader.parentNode) {
                loader.parentNode.removeChild(loader);
            }
        }

        function getOrCreateSessionId() {
            let sessionId = localStorage.getItem('ailure_session_id');
            if (!sessionId) {

        function saveMessage(sessionId, message) {
            const key = `ailure_messages_${sessionId}`;
            const messages = JSON.parse(localStorage.getItem(key) || '[]');
            messages.push(message);
            // Зберігаємо максимум 50 повідомлень
            if (messages.length > 50) {
                messages.shift();
            }
            localStorage.setItem(key, JSON.stringify(messages));
        }

        function loadMessages(sessionId) {
            const key = `ailure_messages_${sessionId}`;
            return JSON.parse(localStorage.getItem(key) || '[]');
        }
                sessionId = 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                localStorage.setItem('ailure_session_id', sessionId);
            }
            return sessionId;
        }

        function saveSessionId(sessionId) {
            localStorage.setItem('ailure_session_id', sessionId);
        }
    }
})();

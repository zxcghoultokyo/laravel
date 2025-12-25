(function() {
    'use strict';

    console.log('AILure Chat: скрипт завантажено');

    // Допоміжна функція для зміни яскравості кольору
    function adjustBrightness(color, amount) {
        const usePound = color[0] === '#';
        const col = usePound ? color.slice(1) : color;
        const num = parseInt(col, 16);
        let r = (num >> 16) + amount;
        let g = ((num >> 8) & 0x00FF) + amount;
        let b = (num & 0x0000FF) + amount;
        r = Math.max(Math.min(255, r), 0);
        g = Math.max(Math.min(255, g), 0);
        b = Math.max(Math.min(255, b), 0);
        return (usePound ? '#' : '') + (r << 16 | g << 8 | b).toString(16).padStart(6, '0');
    }

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
            enabled: true,
            start_state: 'closed'
        };
    }

    function renderWidget(container, settings, token) {
        console.log('AILure Chat: рендеринг віджета...', settings);
        
        if (!settings.enabled) {
            console.log('AILure Chat: віджет вимкнено в налаштуваннях');
            return;
        }

        // Зберігаємо settings глобально для доступу з функцій
        window.ailureSettings = settings;

        // Додаємо CSS анімації
        if (!document.getElementById('ailure-animations')) {
            const style = document.createElement('style');
            style.id = 'ailure-animations';
            style.textContent = `
                @keyframes fadeInUp {
                    from {
                        opacity: 0;
                        transform: translateY(10px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }
                @keyframes ailure-pulse {
                    0%, 80%, 100% { opacity: 0.3; }
                    40% { opacity: 1; }
                }
                .ailure-messages::-webkit-scrollbar {
                    width: 6px;
                }
                .ailure-messages::-webkit-scrollbar-track {
                    background: #f1f1f1;
                }
                .ailure-messages::-webkit-scrollbar-thumb {
                    background: #cbd5e1;
                    border-radius: 3px;
                }
                .ailure-messages::-webkit-scrollbar-thumb:hover {
                    background: #94a3b8;
                }
                #ailure-overlay {
                    transition: opacity 0.3s ease;
                }
                @media (max-width: 480px) {
                    .ailure-widget {
                        position: fixed !important;
                        bottom: 0 !important;
                        left: 0 !important;
                        right: 0 !important;
                        width: 100% !important;
                    }
                    .ailure-window {
                        position: fixed !important;
                        bottom: 0 !important;
                        left: 0 !important;
                        right: 0 !important;
                        width: 100% !important;
                        max-width: 100% !important;
                        height: auto !important;
                        max-height: 85vh !important;
                        border-radius: 16px 16px 0 0 !important;
                    }
                    .ailure-toggle {
                        position: fixed !important;
                        bottom: 20px !important;
                        right: 20px !important;
                        z-index: 10000 !important;
                    }
                }
            `;
            document.head.appendChild(style);
        }

        const sessionId = getOrCreateSessionId();
        console.log('AILure Chat: session_id:', sessionId);

        // Завантажуємо збережені повідомлення
        const savedMessages = loadMessages(sessionId);

        // Створюємо HTML структуру
        container.innerHTML = `            <!-- Overlay для закриття на мобільних -->
            <div id="ailure-overlay" style="
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 9998;
            "></div>
                        <div class="ailure-widget" style="
                position: fixed;
                bottom: 20px;
                ${settings.position === 'right' ? 'right: 20px;' : 'left: 20px;'}
                z-index: 9999;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            ">
                <!-- Кнопка відкриття -->
                <button id="ailure-toggle" class="ailure-toggle" style="
                    width: 60px;
                    height: 60px;
                    border-radius: 50%;
                    background: ${settings.primary_color};
                    color: white;
                    border: none;
                    cursor: pointer;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 28px;
                    transition: all 0.3s ease;
                " onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'">
                    💬
                </button>

                <!-- Віконце чату -->
                <div id="ailure-window" class="ailure-window" style="
                    display: none;
                    position: fixed;
                    bottom: 90px;
                    ${settings.position === 'right' ? 'right: 20px;' : 'left: 20px;'}
                    width: min(400px, calc(100vw - 40px));
                    max-width: 400px;
                    height: min(600px, calc(100vh - 120px));
                    background: white;
                    border-radius: ${settings.border_radius}px;
                    box-shadow: 0 12px 48px rgba(0,0,0,0.25);
                    display: flex;
                    flex-direction: column;
                    overflow: hidden;
                ">
                    <!-- Header -->
                    <div class="ailure-header" style="
                        background: linear-gradient(135deg, ${settings.primary_color} 0%, ${adjustBrightness(settings.primary_color, -15)} 100%);
                        color: white;
                        padding: 20px 16px;
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                    ">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 700;">
                                A
                            </div>
                            <div style="display: flex; flex-direction: column;">
                                <span style="font-weight: 600; font-size: 15px;">AILure Асистент</span>
                                <span style="font-size: 12px; opacity: 0.9;">Завжди онлайн</span>
                            </div>
                        </div>
                        <button id="ailure-close" style="
                            background: rgba(255,255,255,0.2);
                            border: none;
                            color: white;
                            font-size: 18px;
                            cursor: pointer;
                            padding: 4px;
                            width: 28px;
                            height: 28px;
                            border-radius: 50%;
                            transition: all 0.2s;
                        " onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.2)'">✕</button>
                    </div>

                    <!-- Messages -->
                    <div id="ailure-messages" class="ailure-messages" style="
                        flex: 1;
                        overflow-y: auto;
                        padding: 16px;
                        background: #f9fafb;
                        min-height: 300px;
                    ">
                    </div>

                    <!-- Input -->
                    <div class="ailure-input-container" style="
                        padding: 16px;
                        background: white;
                        border-top: 1px solid #e5e7eb;
                        box-shadow: 0 -2px 8px rgba(0,0,0,0.05);
                    ">
                        ${settings.consent_notice ? `
                        <div style="font-size: 11px; color: #6b7280; margin-bottom: 12px; line-height: 1.4;">
                            ${settings.consent_notice}
                        </div>
                        ` : ''}
                        <div style="display: flex; gap: 8px;">
                            <input 
                                type="text" 
                                id="ailure-input" 
                                placeholder="${settings.input_placeholder}"
                                style="
                                    flex: 1;
                                    padding: 12px 16px;
                                    border: 1.5px solid #d1d5db;
                                    border-radius: 24px;
                                    font-size: 14px;
                                    outline: none;
                                    transition: all 0.2s;
                                "
                                onfocus="this.style.borderColor='${settings.primary_color}'; this.style.boxShadow='0 0 0 3px ${settings.primary_color}20'"
                                onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none'"
                            >
                            <button id="ailure-send" style="
                                background: ${settings.primary_color};
                                color: white;
                                border: none;
                                padding: 0;
                                width: 44px;
                                height: 44px;
                                border-radius: 50%;
                                cursor: pointer;
                                font-size: 18px;
                                transition: all 0.2s;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                box-shadow: 0 2px 8px ${settings.primary_color}40;
                            " onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                                ➤
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Додаємо обробники подій
        const toggle = document.getElementById('ailure-toggle');
        const close = document.getElementById('ailure-close');
        const chatWindow = document.getElementById('ailure-window');
        const overlay = document.getElementById('ailure-overlay');
        const input = document.getElementById('ailure-input');
        const send = document.getElementById('ailure-send');
        const messages = document.getElementById('ailure-messages');

        let isOpen = false; // ЗАВЖДИ закритий на старті
        let hasShownWelcome = false; // Чи показано вітальне повідомлення
        
        console.log('AILure Chat: початковий стан - isOpen:', isOpen, 'savedMessages:', savedMessages.length);

        function openWidget() {
            console.log('AILure Chat: openWidget викликано');
            isOpen = true;
            chatWindow.style.display = 'flex';
            overlay.style.display = 'block';
            toggle.style.display = 'none';
            if (input) input.focus();
            
            // Показуємо вітальне повідомлення тільки при ПЕРШОМУ відкритті
            if (!hasShownWelcome && savedMessages.length === 0) {
                console.log('AILure Chat: додаємо вітальне повідомлення');
                addMessage(settings.welcome_message, 'assistant', true);
                hasShownWelcome = true;
            }
        }

        function closeWidget() {
            console.log('AILure Chat: closeWidget викликано');
            isOpen = false;
            chatWindow.style.display = 'none';
            overlay.style.display = 'none';
            toggle.style.display = 'flex';
        }

        toggle.addEventListener('click', openWidget);
        close.addEventListener('click', closeWidget);
        overlay.addEventListener('click', closeWidget);

        // Закриття по ESC
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && isOpen) {
                closeWidget();
            }
        });

        // Відновлюємо історію (БЕЗ автоматичного відкриття)
        if (savedMessages.length > 0) {
            console.log('AILure Chat: відновлюємо історію, кількість повідомлень:', savedMessages.length);
            hasShownWelcome = true; // Якщо є історія - вітальне повідомлення вже було
            savedMessages.forEach(msg => {
                if (msg.role === 'user') {
                    addMessage(msg.content, 'user', false);
                } else if (msg.role === 'assistant') {
                    addMessage(msg.content, 'assistant', false);
                } else if (msg.role === 'product_cards' && msg.cards) {
                    // NEW: restore product cards format
                    addProductCards(msg.cards, false);
                } else if (msg.role === 'products' && msg.products) {
                    addProducts(msg.products, false);
                }
            });
        } else {
            console.log('AILure Chat: немає збереженої історії');
        }
        
        console.log('AILure Chat: перевіряємо стан після відновлення - chatWindow.style.display:', chatWindow.style.display);

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

                // Додаємо вступне повідомлення
                if (data.text) {
                    addMessage(data.text, 'assistant', true);
                }

                // NEW: Показуємо товари з описами (product_cards)
                if (data.data && data.data.product_cards && data.data.product_cards.length > 0) {
                    addProductCards(data.data.product_cards, true);
                }
                // Fallback: старий формат без описів
                else if (data.data && data.data.products && data.data.products.length > 0) {
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
            console.log('AILure Chat: addMessage викликано -', role, ':', text.substring(0, 50));
            const s = window.ailureSettings || { primary_color: '#2563eb' };
            const div = document.createElement('div');
            div.className = `ailure-message ailure-${role}`;
            div.style.cssText = `
                margin-bottom: 12px;
                display: flex;
                justify-content: ${role === 'user' ? 'flex-end' : 'flex-start'};
                animation: fadeInUp 0.3s ease-out;
            `;

            const bubble = document.createElement('div');
            bubble.style.cssText = `
                background: ${role === 'user' ? s.primary_color : 'white'};
                color: ${role === 'user' ? 'white' : '#374151'};
                padding: 12px 16px;
                border-radius: ${role === 'user' ? '18px 18px 4px 18px' : '18px 18px 18px 4px'};
                max-width: 75%;
                font-size: 14px;
                line-height: 1.6;
                white-space: pre-wrap;
                box-shadow: ${role === 'user' ? '0 2px 8px ' + s.primary_color + '30' : '0 2px 8px rgba(0,0,0,0.08)'};
            `;
            bubble.textContent = text;
            div.appendChild(bubble);
            messages.appendChild(div);
            console.log('AILure Chat: повідомлення додано до DOM');
            messages.scrollTop = messages.scrollHeight;

            if (save) {
                saveMessage(sessionId, { role, content: text });
            }
            messages.scrollTop = messages.scrollHeight;
        }

        // NEW: Add product cards with individual descriptions
        function addProductCards(productCards, save = true) {
            const s = window.ailureSettings || { primary_color: '#2563eb' };
            
            productCards.slice(0, 3).forEach((card, index) => {
                const product = card.product;
                const description = card.description;
                
                // Add description as small assistant message (if not empty)
                if (description && description.trim()) {
                    const descDiv = document.createElement('div');
                    descDiv.className = 'ailure-message ailure-assistant';
                    descDiv.style.cssText = `
                        margin-bottom: 8px;
                        display: flex;
                        justify-content: flex-start;
                        animation: fadeInUp 0.3s ease-out;
                        animation-delay: ${index * 0.1}s;
                    `;
                    const bubble = document.createElement('div');
                    bubble.style.cssText = `
                        background: #f8fafc;
                        color: #64748b;
                        padding: 8px 12px;
                        border-radius: 12px;
                        max-width: 85%;
                        font-size: 13px;
                        line-height: 1.4;
                    `;
                    bubble.textContent = description;
                    descDiv.appendChild(bubble);
                    messages.appendChild(descDiv);
                }
                
                // Add product card
                const cardEl = document.createElement('a');
                cardEl.href = product.link || '#';
                cardEl.target = '_blank';
                cardEl.style.cssText = `
                    display: block;
                    background: white;
                    border: 1px solid #e5e7eb;
                    border-radius: 12px;
                    padding: 12px;
                    margin-bottom: 12px;
                    text-decoration: none;
                    color: inherit;
                    transition: all 0.2s;
                    animation: fadeInUp 0.3s ease-out;
                    animation-delay: ${index * 0.1 + 0.05}s;
                `;
                cardEl.onmouseover = () => {
                    cardEl.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
                    cardEl.style.transform = 'translateY(-2px)';
                    cardEl.style.borderColor = s.primary_color;
                };
                cardEl.onmouseout = () => {
                    cardEl.style.boxShadow = 'none';
                    cardEl.style.transform = 'translateY(0)';
                    cardEl.style.borderColor = '#e5e7eb';
                };

                // Generate image HTML with fallback support
                let imgHtml = '';
                if (product.images && product.images.length > 0) {
                    const imgId = 'img-card-' + product.id + '-' + Date.now();
                    const fallbackImages = product.images.slice(1).map(img => `'${img}'`).join(',');
                    imgHtml = `
                        <img id="${imgId}" 
                             src="${product.images[0]}" 
                             style="width: 70px; height: 70px; object-fit: cover; border-radius: 8px; flex-shrink: 0;" 
                             onerror="(function(img){
                                 var fallbacks = [${fallbackImages}];
                                 var idx = parseInt(img.dataset.fallbackIdx || '0');
                                 if (idx < fallbacks.length) {
                                     img.dataset.fallbackIdx = idx + 1;
                                     img.src = fallbacks[idx];
                                 } else {
                                     img.style.display = 'none';
                                 }
                             })(this)"
                        />
                    `;
                }

                cardEl.innerHTML = `
                    <div style="display: flex; gap: 12px;">
                        ${imgHtml}
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-weight: 600; font-size: 13px; margin-bottom: 6px; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">${product.title}</div>
                            <div style="color: ${s.primary_color}; font-weight: 700; font-size: 16px;">${product.price} ₴</div>
                        </div>
                    </div>
                `;
                messages.appendChild(cardEl);
            });
            
            if (save) {
                saveMessage(sessionId, { role: 'product_cards', cards: productCards.slice(0, 3) });
            }
            messages.scrollTop = messages.scrollHeight;
        }

        function addProducts(products, save = true) {
            const s = window.ailureSettings || { primary_color: '#2563eb' };
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
                    border-radius: 12px;
                    padding: 12px;
                    margin-bottom: 8px;
                    text-decoration: none;
                    color: inherit;
                    transition: all 0.2s;
                `;
                card.onmouseover = () => {
                    card.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
                    card.style.transform = 'translateY(-2px)';
                    card.style.borderColor = s.primary_color;
                };
                card.onmouseout = () => {
                    card.style.boxShadow = 'none';
                    card.style.transform = 'translateY(0)';
                    card.style.borderColor = '#e5e7eb';
                };

                // Generate image HTML with fallback support
                let imgHtml = '';
                if (product.images && product.images.length > 0) {
                    const imgId = 'img-' + product.id + '-' + Date.now();
                    const fallbackImages = product.images.slice(1).map(img => `'${img}'`).join(',');
                    imgHtml = `
                        <img id="${imgId}" 
                             src="${product.images[0]}" 
                             style="width: 70px; height: 70px; object-fit: cover; border-radius: 8px; flex-shrink: 0;" 
                             onerror="(function(img){
                                 var fallbacks = [${fallbackImages}];
                                 var idx = parseInt(img.dataset.fallbackIdx || '0');
                                 if (idx < fallbacks.length) {
                                     img.dataset.fallbackIdx = idx + 1;
                                     img.src = fallbacks[idx];
                                 } else {
                                     img.style.display = 'none';
                                 }
                             })(this)"
                        />
                    `;
                }

                card.innerHTML = `
                    <div style="display: flex; gap: 12px;">
                        ${imgHtml}
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-weight: 600; font-size: 13px; margin-bottom: 6px; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">${product.title}</div>
                            <div style="color: ${s.primary_color}; font-weight: 700; font-size: 16px;">${product.price} ₴</div>
                        </div>
                    </div>
                `;
                container.appendChild(card);
            });

            messages.appendChild(container);
            
            if (save) {
                saveMessage(sessionId, { role: 'products', products: products.slice(0, 3) });
            }
            messages.scrollTop = messages.scrollHeight;
        }

        function addLoader() {
            const div = document.createElement('div');
            div.className = 'ailure-loader';
            div.style.cssText = 'margin-bottom: 16px; display: flex; justify-content: flex-start;';
            div.innerHTML = `
                <div style="background: white; padding: 12px 16px; border-radius: 18px 18px 18px 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
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
                sessionId = 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                localStorage.setItem('ailure_session_id', sessionId);
            }
            return sessionId;
        }

        function saveSessionId(sessionId) {
            localStorage.setItem('ailure_session_id', sessionId);
        }

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
    }
})();

/**
 * AIntento Chat Widget v2.0.0
 * Embeddable chat widget for e-commerce sites
 * 
 * Usage: <div id="aintento-chat" data-token="YOUR_TOKEN"></div>
 *        <script src="https://aimbot.laravel.cloud/widget.js?v=2.0.0"></script>
 */
(function() {
    'use strict';

    const WIDGET_VERSION = '2.0.0';
    const DEBUG = false; // Set to true only for debugging
    
    // Bot avatar URL
    const BOT_AVATAR = 'https://aimbot.laravel.cloud/images/aintento-avatar.svg';

    function log(...args) {
        if (DEBUG) console.log('[AIntento]', ...args);
    }

    function logError(...args) {
        console.error('[AIntento]', ...args);
    }

    // Helper function to adjust color brightness
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

    // Wait for DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWidget);
    } else {
        initWidget();
    }

    function initWidget() {
        log('Initializing widget v' + WIDGET_VERSION);
        
        // Support both old and new container IDs
        const container = document.getElementById('aintento-chat') || document.getElementById('ailure-chat');
        if (!container) {
            logError('Container #aintento-chat not found');
            return;
        }

        const token = container.dataset.token;
        if (!token) {
            logError('data-token not specified');
            return;
        }

        log('Token received, loading settings...');

        const apiUrl = 'https://aimbot.laravel.cloud/api/widget/settings';

        fetch(apiUrl, {
            headers: {
                'X-Widget-Token': token,
                'Content-Type': 'application/json'
            }
        })
        .then(res => res.json())
        .then(settings => {
            log('Settings loaded', settings);
            renderWidget(container, settings, token);
        })
        .catch(err => {
            logError('Failed to load settings', err);
            renderWidget(container, getDefaultSettings(), token);
        });
    }

    function getDefaultSettings() {
        return {
            primary_color: '#2563eb',
            text_color: '#ffffff',
            position: 'right',
            border_radius: 12,
            welcome_message: 'Вітаю! 👋 Я AIntento — ваш персональний помічник з підбору спорядження. Чим можу допомогти?',
            input_placeholder: 'Напишіть повідомлення...',
            consent_notice: null,
            enabled: true,
            start_state: 'closed'
        };
    }

    function renderWidget(container, settings, token) {
        if (!settings.enabled) {
            log('Widget disabled in settings');
            return;
        }

        // Store settings globally
        window.aintentoSettings = settings;

        // Inject CSS (only once)
        injectStyles(settings);

        const sessionId = getOrCreateSessionId();
        const savedMessages = loadMessages(sessionId);

        // Create HTML structure
        container.innerHTML = createWidgetHTML(settings);

        // Get DOM elements
        const elements = {
            toggle: document.getElementById('aintento-toggle'),
            close: document.getElementById('aintento-close'),
            window: document.getElementById('aintento-window'),
            overlay: document.getElementById('aintento-overlay'),
            input: document.getElementById('aintento-input'),
            send: document.getElementById('aintento-send'),
            messages: document.getElementById('aintento-messages')
        };

        // Widget state
        const state = {
            isOpen: false,
            hasShownWelcome: false,
            sessionId: sessionId,
            eventListeners: [] // Track listeners for cleanup
        };

        // Setup event handlers with cleanup tracking
        setupEventHandlers(elements, state, settings, token, savedMessages);
    }

    function injectStyles(settings) {
        if (document.getElementById('aintento-styles')) return;
        
        const style = document.createElement('style');
        style.id = 'aintento-styles';
        style.textContent = `
            @keyframes aintento-fadeInUp {
                from { opacity: 0; transform: translateY(10px); }
                to { opacity: 1; transform: translateY(0); }
            }
            @keyframes aintento-pulse {
                0%, 80%, 100% { opacity: 0.3; }
                40% { opacity: 1; }
            }
            @keyframes aintento-glow {
                0%, 100% { box-shadow: 0 0 5px rgba(34, 211, 238, 0.5); }
                50% { box-shadow: 0 0 15px rgba(34, 211, 238, 0.8); }
            }
            .aintento-messages::-webkit-scrollbar { width: 6px; }
            .aintento-messages::-webkit-scrollbar-track { background: #f1f1f1; }
            .aintento-messages::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
            .aintento-messages::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
            #aintento-overlay { transition: opacity 0.3s ease; }
            .aintento-avatar { animation: aintento-glow 2s ease-in-out infinite; }
            @media (max-width: 480px) {
                .aintento-widget {
                    position: fixed !important;
                    bottom: 0 !important;
                    left: 0 !important;
                    right: 0 !important;
                    width: 100% !important;
                }
                .aintento-window {
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
                .aintento-toggle {
                    position: fixed !important;
                    bottom: 20px !important;
                    right: 20px !important;
                    z-index: 10000 !important;
                }
            }
        `;
        document.head.appendChild(style);
    }

    function createWidgetHTML(settings) {
        const s = settings;
        return `
            <div id="aintento-overlay" style="
                display: none;
                position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 9998;
            "></div>
            
            <div class="aintento-widget" style="
                position: fixed;
                bottom: 20px;
                ${s.position === 'right' ? 'right: 20px;' : 'left: 20px;'}
                z-index: 9999;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            ">
                <button id="aintento-toggle" class="aintento-toggle" style="
                    width: 60px;
                    height: 60px;
                    border-radius: 50%;
                    background: ${s.primary_color};
                    color: white;
                    border: none;
                    cursor: pointer;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 28px;
                    transition: all 0.3s ease;
                    overflow: hidden;
                ">
                    <img src="${BOT_AVATAR}" alt="Chat" style="width: 40px; height: 40px; border-radius: 50%;">
                </button>

                <div id="aintento-window" class="aintento-window" style="
                    display: none;
                    position: fixed;
                    bottom: 90px;
                    ${s.position === 'right' ? 'right: 20px;' : 'left: 20px;'}
                    width: min(400px, calc(100vw - 40px));
                    max-width: 400px;
                    height: min(600px, calc(100vh - 120px));
                    background: white;
                    border-radius: ${s.border_radius}px;
                    box-shadow: 0 12px 48px rgba(0,0,0,0.25);
                    flex-direction: column;
                    overflow: hidden;
                ">
                    <div class="aintento-header" style="
                        background: linear-gradient(135deg, ${s.primary_color} 0%, ${adjustBrightness(s.primary_color, -15)} 100%);
                        color: white;
                        padding: 16px;
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                    ">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div class="aintento-avatar" style="
                                width: 40px; 
                                height: 40px; 
                                border-radius: 50%; 
                                background: rgba(255,255,255,0.15); 
                                display: flex; 
                                align-items: center; 
                                justify-content: center;
                                border: 2px solid rgba(34, 211, 238, 0.6);
                            ">
                                <img src="${BOT_AVATAR}" alt="AIntento" style="width: 32px; height: 32px; border-radius: 50%;">
                            </div>
                            <div style="display: flex; flex-direction: column;">
                                <span style="font-weight: 600; font-size: 15px;">AIntento</span>
                                <span style="font-size: 12px; opacity: 0.9;">🟢 Завжди онлайн</span>
                            </div>
                        </div>
                        <button id="aintento-close" style="
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
                        ">✕</button>
                    </div>

                    <div id="aintento-messages" class="aintento-messages" style="
                        flex: 1;
                        overflow-y: auto;
                        padding: 16px;
                        background: #f9fafb;
                        min-height: 300px;
                    "></div>

                    <div class="aintento-input-container" style="
                        padding: 16px;
                        background: white;
                        border-top: 1px solid #e5e7eb;
                        box-shadow: 0 -2px 8px rgba(0,0,0,0.05);
                    ">
                        ${s.consent_notice ? `
                        <div style="font-size: 11px; color: #6b7280; margin-bottom: 12px; line-height: 1.4;">
                            ${s.consent_notice}
                        </div>` : ''}
                        <div style="display: flex; gap: 8px;">
                            <input 
                                type="text" 
                                id="aintento-input" 
                                placeholder="${s.input_placeholder}"
                                style="
                                    flex: 1;
                                    padding: 12px 16px;
                                    border: 1.5px solid #d1d5db;
                                    border-radius: 24px;
                                    font-size: 14px;
                                    outline: none;
                                    transition: all 0.2s;
                                "
                            >
                            <button id="aintento-send" style="
                                background: ${s.primary_color};
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
                                box-shadow: 0 2px 8px ${s.primary_color}40;
                            ">➤</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    function setupEventHandlers(elements, state, settings, token, savedMessages) {
        const { toggle, close, window: chatWindow, overlay, input, send, messages } = elements;

        // Quick action responses
        const quickActionResponses = {
            product_help: 'Щоб я міг підібрати найкращий товар, розкажи:\n\n🔹 Що шукаєш? (наприклад: плитоноска, рюкзак, берці)\n🔹 Бюджет? (необов\'язково)\n🔹 Колір/розмір? (якщо важливо)\n\nАбо просто напиши що потрібно — я розберусь! 😊',
            order_info: 'Для пошуку замовлення мені потрібно:\n\n📝 Номер замовлення (наприклад: 12345)\n\nабо\n\n📱 Номер телефону з якого робили замовлення\n\nНапиши будь-що з цього і я знайду твоє замовлення!',
            store_info: null // Will be fetched from settings
        };

        // Handle quick action click
        function handleQuickAction(action) {
            if (action === 'store_info') {
                // Build store info from settings
                const storeInfo = buildStoreInfo(settings);
                addMessage(messages, storeInfo, 'assistant', state.sessionId, true);
            } else {
                const response = quickActionResponses[action];
                if (response) {
                    addMessage(messages, response, 'assistant', state.sessionId, true);
                }
            }
        }

        // Build store info from settings
        function buildStoreInfo(s) {
            let info = '🏪 **ATK.UA — тактичне спорядження**\n\n';
            
            if (s.store_address) {
                info += `📍 ${s.store_address}\n`;
            }
            if (s.store_phone) {
                info += `📞 Телефон: ${s.store_phone}\n`;
            }
            if (s.store_hours) {
                info += `🕐 Графік роботи: ${s.store_hours}\n`;
            }
            if (s.store_about) {
                info += `\n${s.store_about}\n`;
            }
            
            if (!s.store_address && !s.store_phone && !s.store_hours && !s.store_about) {
                info += '📞 Зв\'яжіться з нами через сайт atk.ua\n';
                info += '🕐 Працюємо щодня\n';
            }
            
            info += '\nЧим можу допомогти? 😊';
            return info;
        }

        // Open widget function
        function openWidget() {
            state.isOpen = true;
            chatWindow.style.display = 'flex';
            overlay.style.display = 'block';
            toggle.style.display = 'none';
            input?.focus();
            
            if (!state.hasShownWelcome && savedMessages.length === 0) {
                addMessage(messages, settings.welcome_message, 'assistant', state.sessionId, true);
                // Add quick actions after welcome message
                addQuickActions(messages, settings, handleQuickAction);
                state.hasShownWelcome = true;
            }
        }

        // Close widget function
        function closeWidget() {
            state.isOpen = false;
            chatWindow.style.display = 'none';
            overlay.style.display = 'none';
            toggle.style.display = 'flex';
        }

        // Send message function
        function sendMessage() {
            const message = input.value.trim();
            if (!message) return;

            addMessage(messages, message, 'user', state.sessionId, true);
            input.value = '';

            const loader = addLoader(messages);
            const chatApiUrl = 'https://aimbot.laravel.cloud/api/chat';

            fetch(chatApiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Widget-Token': token
                },
                body: JSON.stringify({
                    message: message,
                    session_id: state.sessionId
                })
            })
            .then(res => res.json())
            .then(data => {
                removeLoader(loader);
                
                if (data.session_id) {
                    state.sessionId = data.session_id;
                    saveSessionId(data.session_id);
                }

                if (data.text) {
                    addMessage(messages, data.text, 'assistant', state.sessionId, true);
                }

                if (data.data?.product_cards?.length > 0) {
                    addProductCards(messages, data.data.product_cards, state.sessionId, true);
                } else if (data.data?.products?.length > 0) {
                    addProducts(messages, data.data.products, state.sessionId, true);
                }
                
                // Show cross-sell suggestions if available
                if (data.data?.cross_sell) {
                    setTimeout(() => {
                        addCrossSell(messages, data.data.cross_sell, settings, state.sessionId);
                    }, 500);
                }
            })
            .catch(err => {
                removeLoader(loader);
                addMessage(messages, 'Вибачте, не вдалося надіслати повідомлення. Спробуйте ще раз.', 'assistant', state.sessionId, false);
                logError('Send error:', err);
            });
        }

        // Keyboard handler
        function handleKeydown(e) {
            if (e.key === 'Escape' && state.isOpen) {
                closeWidget();
            }
        }

        function handleEnter(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        }

        // Input focus/blur handlers
        function handleInputFocus() {
            input.style.borderColor = settings.primary_color;
            input.style.boxShadow = `0 0 0 3px ${settings.primary_color}20`;
        }

        function handleInputBlur() {
            input.style.borderColor = '#d1d5db';
            input.style.boxShadow = 'none';
        }

        // Close button hover
        function handleCloseHover() {
            close.style.background = 'rgba(255,255,255,0.3)';
        }
        function handleCloseLeave() {
            close.style.background = 'rgba(255,255,255,0.2)';
        }

        // Toggle button hover
        function handleToggleHover() {
            toggle.style.transform = 'scale(1.1)';
        }
        function handleToggleLeave() {
            toggle.style.transform = 'scale(1)';
        }

        // Send button hover
        function handleSendHover() {
            send.style.transform = 'scale(1.05)';
        }
        function handleSendLeave() {
            send.style.transform = 'scale(1)';
        }

        // Add event listeners
        toggle.addEventListener('click', openWidget);
        toggle.addEventListener('mouseenter', handleToggleHover);
        toggle.addEventListener('mouseleave', handleToggleLeave);
        
        close.addEventListener('click', closeWidget);
        close.addEventListener('mouseenter', handleCloseHover);
        close.addEventListener('mouseleave', handleCloseLeave);
        
        overlay.addEventListener('click', closeWidget);
        
        send.addEventListener('click', sendMessage);
        send.addEventListener('mouseenter', handleSendHover);
        send.addEventListener('mouseleave', handleSendLeave);
        
        input.addEventListener('keypress', handleEnter);
        input.addEventListener('focus', handleInputFocus);
        input.addEventListener('blur', handleInputBlur);
        
        document.addEventListener('keydown', handleKeydown);

        // Restore message history
        if (savedMessages.length > 0) {
            state.hasShownWelcome = true;
            savedMessages.forEach(msg => {
                if (msg.role === 'user') {
                    addMessage(messages, msg.content, 'user', state.sessionId, false);
                } else if (msg.role === 'assistant') {
                    addMessage(messages, msg.content, 'assistant', state.sessionId, false);
                } else if (msg.role === 'product_cards' && msg.cards) {
                    addProductCards(messages, msg.cards, state.sessionId, false);
                } else if (msg.role === 'products' && msg.products) {
                    addProducts(messages, msg.products, state.sessionId, false);
                }
            });
        }

        // Store cleanup function on window for potential later use
        window.aintentoCleanup = function() {
            toggle.removeEventListener('click', openWidget);
            toggle.removeEventListener('mouseenter', handleToggleHover);
            toggle.removeEventListener('mouseleave', handleToggleLeave);
            close.removeEventListener('click', closeWidget);
            close.removeEventListener('mouseenter', handleCloseHover);
            close.removeEventListener('mouseleave', handleCloseLeave);
            overlay.removeEventListener('click', closeWidget);
            send.removeEventListener('click', sendMessage);
            send.removeEventListener('mouseenter', handleSendHover);
            send.removeEventListener('mouseleave', handleSendLeave);
            input.removeEventListener('keypress', handleEnter);
            input.removeEventListener('focus', handleInputFocus);
            input.removeEventListener('blur', handleInputBlur);
            document.removeEventListener('keydown', handleKeydown);
            log('Widget cleaned up');
        };
    }

    function addQuickActions(messagesContainer, settings, onActionClick) {
        const s = settings || window.aintentoSettings || { primary_color: '#2563eb' };
        
        const quickActions = [
            {
                icon: '🎯',
                label: 'Підбери товар',
                action: 'product_help'
            },
            {
                icon: '📦',
                label: 'Моє замовлення',
                action: 'order_info'
            },
            {
                icon: 'ℹ️',
                label: 'Про магазин',
                action: 'store_info'
            }
        ];

        const container = document.createElement('div');
        container.className = 'aintento-quick-actions';
        container.style.cssText = `
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
            padding: 0 4px;
            animation: aintento-fadeInUp 0.3s ease-out;
            animation-delay: 0.2s;
            opacity: 0;
            animation-fill-mode: forwards;
        `;

        quickActions.forEach(qa => {
            const btn = document.createElement('button');
            btn.className = 'aintento-quick-action';
            btn.dataset.action = qa.action;
            btn.style.cssText = `
                display: flex;
                align-items: center;
                gap: 6px;
                padding: 10px 14px;
                background: white;
                border: 1.5px solid #e5e7eb;
                border-radius: 20px;
                font-size: 13px;
                color: #374151;
                cursor: pointer;
                transition: all 0.2s;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            `;
            btn.innerHTML = `<span style="font-size: 16px;">${qa.icon}</span><span>${qa.label}</span>`;
            
            btn.onmouseenter = () => {
                btn.style.borderColor = s.primary_color;
                btn.style.background = `${s.primary_color}10`;
                btn.style.transform = 'translateY(-1px)';
                btn.style.boxShadow = '0 3px 8px rgba(0,0,0,0.1)';
            };
            btn.onmouseleave = () => {
                btn.style.borderColor = '#e5e7eb';
                btn.style.background = 'white';
                btn.style.transform = 'translateY(0)';
                btn.style.boxShadow = '0 1px 3px rgba(0,0,0,0.05)';
            };
            
            btn.onclick = () => {
                // Remove quick actions after click
                container.remove();
                onActionClick(qa.action);
            };
            
            container.appendChild(btn);
        });

        messagesContainer.appendChild(container);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function addMessage(messagesContainer, text, role, sessionId, save = true) {
        const s = window.aintentoSettings || { primary_color: '#2563eb' };
        const div = document.createElement('div');
        div.className = `aintento-message aintento-${role}`;
        div.style.cssText = `
            margin-bottom: 12px;
            display: flex;
            justify-content: ${role === 'user' ? 'flex-end' : 'flex-start'};
            animation: aintento-fadeInUp 0.3s ease-out;
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
        messagesContainer.appendChild(div);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;

        if (save) {
            saveMessage(sessionId, { role, content: text });
        }
    }

    function addProductCards(messagesContainer, productCards, sessionId, save = true) {
        const s = window.aintentoSettings || { primary_color: '#2563eb' };
        
        productCards.slice(0, 3).forEach((card, index) => {
            const product = card.product;
            const description = card.description;
            
            if (description?.trim()) {
                const descDiv = document.createElement('div');
                descDiv.className = 'aintento-message aintento-assistant';
                descDiv.style.cssText = `
                    margin-bottom: 8px;
                    display: flex;
                    justify-content: flex-start;
                    animation: aintento-fadeInUp 0.3s ease-out;
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
                messagesContainer.appendChild(descDiv);
            }
            
            const cardEl = createProductCard(product, s, index);
            messagesContainer.appendChild(cardEl);
        });
        
        if (save) {
            saveMessage(sessionId, { role: 'product_cards', cards: productCards.slice(0, 3) });
        }
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function addProducts(messagesContainer, products, sessionId, save = true) {
        const s = window.aintentoSettings || { primary_color: '#2563eb' };
        const container = document.createElement('div');
        container.style.cssText = 'margin-bottom: 12px;';
        
        products.slice(0, 3).forEach((product, index) => {
            const card = createProductCard(product, s, index);
            container.appendChild(card);
        });

        messagesContainer.appendChild(container);
        
        if (save) {
            saveMessage(sessionId, { role: 'products', products: products.slice(0, 3) });
        }
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function createProductCard(product, settings, index) {
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
            animation: aintento-fadeInUp 0.3s ease-out;
            animation-delay: ${index * 0.1}s;
        `;

        card.onmouseenter = () => {
            card.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
            card.style.transform = 'translateY(-2px)';
            card.style.borderColor = settings.primary_color;
        };
        card.onmouseleave = () => {
            card.style.boxShadow = 'none';
            card.style.transform = 'translateY(0)';
            card.style.borderColor = '#e5e7eb';
        };

        let imgHtml = '';
        if (product.images?.length > 0) {
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
                    <div style="color: ${settings.primary_color}; font-weight: 700; font-size: 16px;">${product.price} ₴</div>
                </div>
            </div>
        `;

        return card;
    }

    function addCrossSell(messagesContainer, crossSell, settings, sessionId) {
        if (!crossSell || !crossSell.suggestions?.length) return;
        
        const s = settings || window.aintentoSettings || { primary_color: '#2563eb' };
        
        const wrapper = document.createElement('div');
        wrapper.className = 'aintento-cross-sell';
        wrapper.style.cssText = `
            margin-bottom: 16px;
            animation: aintento-fadeInUp 0.3s ease-out;
        `;
        
        const container = document.createElement('div');
        container.style.cssText = `
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            border: 1px solid #fcd34d;
            border-radius: 16px;
            padding: 16px;
        `;
        
        // Header
        const header = document.createElement('div');
        header.style.cssText = 'display: flex; align-items: center; gap: 8px; margin-bottom: 12px;';
        header.innerHTML = `
            <span style="font-size: 18px;">🎯</span>
            <div>
                <div style="font-weight: 600; font-size: 14px; color: #1f2937;">${crossSell.title || 'Разом краще'}</div>
                <div style="font-size: 12px; color: #92400e;">${crossSell.subtitle || 'Часто беруть разом'}</div>
            </div>
        `;
        container.appendChild(header);
        
        // Items carousel
        const carousel = document.createElement('div');
        carousel.style.cssText = `
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 8px;
            margin: 0 -4px;
            padding: 0 4px;
        `;
        
        crossSell.suggestions.forEach((item, index) => {
            const card = document.createElement('div');
            card.style.cssText = `
                flex-shrink: 0;
                width: 110px;
                background: white;
                border-radius: 12px;
                padding: 8px;
                border: 1px solid #e5e7eb;
                cursor: pointer;
                transition: all 0.2s;
                position: relative;
            `;
            
            card.innerHTML = `
                <input type="checkbox" checked style="
                    position: absolute;
                    top: 6px;
                    right: 6px;
                    width: 16px;
                    height: 16px;
                    accent-color: ${s.primary_color};
                    cursor: pointer;
                " data-article="${item.article}">
                ${item.image 
                    ? `<img src="${item.image}" style="width: 100%; height: 60px; object-fit: cover; border-radius: 8px; margin-bottom: 6px;" onerror="this.style.display='none'">`
                    : '<div style="width: 100%; height: 60px; background: #f1f5f9; border-radius: 8px; margin-bottom: 6px; display: flex; align-items: center; justify-content: center; font-size: 20px;">📦</div>'
                }
                <div style="font-size: 11px; font-weight: 500; color: #374151; line-height: 1.3; height: 28px; overflow: hidden;">${item.title}</div>
                <div style="font-size: 10px; color: #92400e; margin: 4px 0;">${item.reason || ''}</div>
                <div style="font-size: 13px; font-weight: 700; color: ${s.primary_color};">${item.price} ₴</div>
            `;
            
            card.onmouseenter = () => {
                card.style.borderColor = s.primary_color;
                card.style.transform = 'translateY(-2px)';
                card.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
            };
            card.onmouseleave = () => {
                card.style.borderColor = '#e5e7eb';
                card.style.transform = 'translateY(0)';
                card.style.boxShadow = 'none';
            };
            
            // Toggle checkbox on card click
            card.onclick = (e) => {
                if (e.target.type !== 'checkbox') {
                    const checkbox = card.querySelector('input[type="checkbox"]');
                    checkbox.checked = !checkbox.checked;
                }
            };
            
            carousel.appendChild(card);
        });
        container.appendChild(carousel);
        
        // Buttons
        const buttons = document.createElement('div');
        buttons.style.cssText = 'display: flex; gap: 8px; margin-top: 12px;';
        
        const addAllBtn = document.createElement('button');
        addAllBtn.style.cssText = `
            flex: 1;
            background: ${s.primary_color};
            color: white;
            padding: 10px 16px;
            border: none;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        `;
        addAllBtn.textContent = 'Додати все';
        addAllBtn.onmouseenter = () => { addAllBtn.style.opacity = '0.9'; };
        addAllBtn.onmouseleave = () => { addAllBtn.style.opacity = '1'; };
        addAllBtn.onclick = () => {
            const selectedArticles = Array.from(container.querySelectorAll('input[type="checkbox"]:checked'))
                .map(cb => cb.dataset.article);
            if (selectedArticles.length > 0) {
                // Here you could call an API to add to cart
                wrapper.style.opacity = '0';
                wrapper.style.transform = 'translateY(-10px)';
                wrapper.style.transition = 'all 0.3s ease';
                setTimeout(() => wrapper.remove(), 300);
                addMessage(messagesContainer, 'Чудово! Ці товари будуть додані до вашого замовлення при оформленні. 🛒', 'assistant', sessionId, true);
            }
        };
        
        const dismissBtn = document.createElement('button');
        dismissBtn.style.cssText = `
            padding: 10px 16px;
            background: transparent;
            border: none;
            color: #6b7280;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        `;
        dismissBtn.textContent = 'Ні, дякую';
        dismissBtn.onmouseenter = () => { dismissBtn.style.color = '#374151'; };
        dismissBtn.onmouseleave = () => { dismissBtn.style.color = '#6b7280'; };
        dismissBtn.onclick = () => {
            wrapper.style.opacity = '0';
            wrapper.style.transform = 'translateY(-10px)';
            wrapper.style.transition = 'all 0.3s ease';
            setTimeout(() => wrapper.remove(), 300);
        };
        
        buttons.appendChild(addAllBtn);
        buttons.appendChild(dismissBtn);
        container.appendChild(buttons);
        
        wrapper.appendChild(container);
        messagesContainer.appendChild(wrapper);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function addLoader(messagesContainer) {
        const div = document.createElement('div');
        div.className = 'aintento-loader';
        div.style.cssText = 'margin-bottom: 16px; display: flex; justify-content: flex-start;';
        div.innerHTML = `
            <div style="background: white; padding: 12px 16px; border-radius: 18px 18px 18px 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                <span style="display: inline-block; animation: aintento-pulse 1.4s infinite;">●</span>
                <span style="display: inline-block; animation: aintento-pulse 1.4s 0.2s infinite;">●</span>
                <span style="display: inline-block; animation: aintento-pulse 1.4s 0.4s infinite;">●</span>
            </div>
        `;
        messagesContainer.appendChild(div);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
        return div;
    }

    function removeLoader(loader) {
        loader?.parentNode?.removeChild(loader);
    }

    // Session management - support both old and new keys for backward compatibility
    function getOrCreateSessionId() {
        let sessionId = localStorage.getItem('aintento_session_id') || localStorage.getItem('ailure_session_id');
        if (!sessionId) {
            sessionId = 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        }
        localStorage.setItem('aintento_session_id', sessionId);
        return sessionId;
    }

    function saveSessionId(sessionId) {
        localStorage.setItem('aintento_session_id', sessionId);
    }

    function saveMessage(sessionId, message) {
        const key = `aintento_messages_${sessionId}`;
        const messages = JSON.parse(localStorage.getItem(key) || '[]');
        messages.push(message);
        if (messages.length > 50) {
            messages.shift();
        }
        localStorage.setItem(key, JSON.stringify(messages));
    }

    function loadMessages(sessionId) {
        // Check both new and old keys
        const newKey = `aintento_messages_${sessionId}`;
        const oldKey = `ailure_messages_${sessionId}`;
        const messages = localStorage.getItem(newKey) || localStorage.getItem(oldKey);
        return JSON.parse(messages || '[]');
    }
})();

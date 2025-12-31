/**
 * AIntento Chat Widget v2.3.8
 * Embeddable chat widget for e-commerce sites
 * SSE Streaming support for real-time responses
 * 
 * Usage: <div id="aintento-chat" data-token="YOUR_TOKEN"></div>
 *        <script src="https://aimbot.laravel.cloud/widget.js?v=2.3.7"></script>
 * 
 * API: window.openChat() - opens the chat widget
 *      window.aintentoClose() - closes the chat widget
 */
(function() {
    'use strict';

    const WIDGET_VERSION = '2.3.10';
    const DEBUG = true; // Enable for troubleshooting
    
    // Capture script reference immediately (before DOMContentLoaded makes it null)
    const CURRENT_SCRIPT = document.currentScript;
    
    // Determine API base URL from script src
    let BASE_URL = 'https://aimbot.laravel.cloud';
    if (CURRENT_SCRIPT && CURRENT_SCRIPT.src) {
        try {
            const scriptUrl = new URL(CURRENT_SCRIPT.src);
            BASE_URL = scriptUrl.origin;
        } catch (e) {
            // fallback to production
        }
    }
    
    // Bot avatar URL
    let BOT_AVATAR = BASE_URL + '/images/aintento-avatar.svg';

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

        const apiUrl = BASE_URL + '/api/widget/settings';

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

        // Bot avatar uses BASE_URL
        BOT_AVATAR = BASE_URL + '/images/aintento-avatar.svg';

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
        
        // Track page view (widget loaded on page)
        sendAnalyticsEvent('page_view', {
            widget_version: WIDGET_VERSION
        });
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
            
            /* Mobile styles */
            @media (max-width: 480px) {
                .aintento-widget {
                    position: fixed !important;
                    bottom: 0 !important;
                    left: 0 !important;
                    right: 0 !important;
                    width: 100% !important;
                    touch-action: manipulation;
                }
                .aintento-window {
                    position: fixed !important;
                    bottom: 0 !important;
                    left: 0 !important;
                    right: 0 !important;
                    top: auto !important;
                    width: 100% !important;
                    max-width: 100% !important;
                    height: 80vh !important;
                    max-height: 80vh !important;
                    border-radius: 20px 20px 0 0 !important;
                    z-index: 10001 !important;
                }
                .aintento-toggle {
                    position: fixed !important;
                    bottom: 16px !important;
                    right: 16px !important;
                    z-index: 10000 !important;
                    width: 56px !important;
                    height: 56px !important;
                }
                .aintento-messages {
                    font-size: 15px !important;
                }
                #aintento-input {
                    font-size: 16px !important; /* Prevents zoom on iOS */
                }
            }
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
                        <div style="text-align: center; margin-top: 8px; font-size: 10px; color: #9ca3af;">
                            Powered by <a href="https://aimbot.laravel.cloud/" target="_blank" style="color: #6b7280; text-decoration: none;">Aintento</a> AI
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
            order_info: 'Для пошуку замовлення мені потрібно:\n\n📝 Номер замовлення (наприклад: 12345)\n\nабо\n\n📱 Номер телефону з якого робили замовлення\n\nНапиши будь-що з цього і я знайду твоє замовлення!',
            store_info: null // Will be fetched from settings
        };

        // Handle quick action click
        function handleQuickAction(action) {
            if (action === 'store_info') {
                // Build store info from settings
                const storeInfo = buildStoreInfo(settings);
                addMessage(messages, storeInfo, 'assistant', state.sessionId, true);
            } else if (action === 'top_products') {
                // Send "Покажи топ товари" to backend to get popular products
                sendMessage('покажи топ товари');
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
            
            // Track chat opened
            sendAnalyticsEvent('chat_opened');
            
            if (!state.hasShownWelcome && savedMessages.length === 0) {
                // Track session start (first time opening)
                sendAnalyticsEvent('session_start');
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
            // Track chat closed
            sendAnalyticsEvent('chat_closed');
        }

        // Send message function with SSE streaming support
        function sendMessage(customMessage = null) {
            // Ignore event objects passed from click handlers
            if (customMessage && typeof customMessage === 'object' && customMessage.target) {
                customMessage = null;
            }
            
            const message = customMessage || input.value.trim();
            if (!message) return;

            addMessage(messages, message, 'user', state.sessionId, true);
            if (!customMessage) input.value = '';

            // Check if streaming is enabled (default: true)
            const useStreaming = settings.enable_streaming !== false;
            
            if (useStreaming) {
                sendMessageStreaming(message);
            } else {
                sendMessageFetch(message);
            }
        }
        
        // Streaming version using SSE (Server-Sent Events)
        function sendMessageStreaming(message) {
            const loader = addLoader(messages);
            let currentTextElement = null;
            let accumulatedText = '';
            let hasReceivedProducts = false;
            let receivedProducts = [];
            
            // Smooth scroll to loader on start
            setTimeout(() => {
                messages.scrollTo({ top: messages.scrollHeight, behavior: 'smooth' });
            }, 100);
            
            const streamUrl = BASE_URL + '/api/chat/stream?message=' + encodeURIComponent(message) + '&session_id=' + encodeURIComponent(state.sessionId);
            
            log('Starting SSE stream:', streamUrl);
            
            const eventSource = new EventSource(streamUrl);
            
            eventSource.onopen = function() {
                log('SSE connection opened');
            };
            
            eventSource.onmessage = function(event) {
                try {
                    const data = JSON.parse(event.data);
                    log('SSE event:', data.type, data);
                    
                    // Update session ID if provided
                    if (data.session_id) {
                        state.sessionId = data.session_id;
                        saveSessionId(data.session_id);
                    }
                    
                    switch (data.type) {
                        case 'status':
                            // Update loader text with status
                            if (loader) {
                                const statusText = loader.querySelector('.aintento-loader-text');
                                if (statusText) {
                                    statusText.textContent = data.text || 'Обробляю...';
                                }
                            }
                            break;
                            
                        case 'chunk':
                            // Remove loader on first content chunk
                            if (loader && loader.parentNode) {
                                removeLoader(loader);
                            }
                            
                            // Accumulate text
                            const chunkText = data.text || '';
                            accumulatedText += chunkText;
                            
                            // Create or update text element
                            if (!currentTextElement) {
                                currentTextElement = createStreamingTextElement(messages);
                            }
                            
                            // Update text content with typing effect
                            updateStreamingText(currentTextElement, accumulatedText);
                            break;
                            
                        case 'products':
                            // Remove loader if still present
                            if (loader && loader.parentNode) {
                                removeLoader(loader);
                            }
                            
                            // Store products for later display
                            hasReceivedProducts = true;
                            receivedProducts = data.products || [];
                            
                            // Display products immediately after text
                            if (receivedProducts.length > 0) {
                                addProducts(messages, receivedProducts, state.sessionId, true);
                                // Smooth scroll after products added
                                setTimeout(() => {
                                    messages.scrollTo({ top: messages.scrollHeight, behavior: 'smooth' });
                                }, 100);
                            }
                            break;
                            
                        case 'error':
                            // Remove loader
                            if (loader && loader.parentNode) {
                                removeLoader(loader);
                            }
                            
                            addMessage(messages, data.text || 'Сталася помилка', 'assistant', state.sessionId, false);
                            eventSource.close();
                            break;
                            
                        case 'done':
                            log('SSE stream done');
                            
                            // Remove loader if still present
                            if (loader && loader.parentNode) {
                                removeLoader(loader);
                            }
                            
                            // Finalize or remove streaming text element
                            if (currentTextElement) {
                                if (accumulatedText.trim()) {
                                    // Has text - finalize (remove cursor)
                                    finalizeStreamingText(currentTextElement, accumulatedText);
                                } else {
                                    // No text - remove empty bubble
                                    currentTextElement.remove();
                                }
                            }
                            
                            // Fetch cross-sell for first product
                            if (receivedProducts.length > 0) {
                                const firstProduct = receivedProducts[0];
                                const productId = firstProduct.id || firstProduct.article;
                                if (productId) {
                                    fetchCrossSellAsync(messages, productId, settings, state.sessionId);
                                }
                            }
                            
                            eventSource.close();
                            break;
                    }
                } catch (err) {
                    logError('SSE parse error:', err, event.data);
                }
            };
            
            eventSource.onerror = function(error) {
                logError('SSE error:', error);
                
                // Remove loader
                if (loader && loader.parentNode) {
                    removeLoader(loader);
                }
                
                // Only show error if we haven't received any content
                if (!accumulatedText && !hasReceivedProducts) {
                    addMessage(messages, 'Вибачте, не вдалося отримати відповідь. Спробуйте ще раз.', 'assistant', state.sessionId, false);
                }
                
                eventSource.close();
            };
        }
        
        // Create a streaming text element (initially empty)
        function createStreamingTextElement(container) {
            const wrapper = document.createElement('div');
            wrapper.className = 'aintento-message assistant';
            wrapper.style.cssText = 'display: flex; gap: 8px; margin-bottom: 8px;';
            
            // Avatar
            const avatar = document.createElement('img');
            avatar.src = BOT_AVATAR;
            avatar.alt = 'Bot';
            avatar.style.cssText = 'width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;';
            
            // Bubble
            const bubble = document.createElement('div');
            bubble.className = 'aintento-bubble streaming';
            bubble.style.cssText = `
                background: #f3f4f6;
                padding: 12px 16px;
                border-radius: 12px;
                max-width: 85%;
                line-height: 1.5;
                color: #374151;
            `;
            
            // Text content with cursor
            const textSpan = document.createElement('span');
            textSpan.className = 'streaming-text';
            
            const cursor = document.createElement('span');
            cursor.className = 'streaming-cursor';
            cursor.style.cssText = `
                display: inline-block;
                width: 8px;
                height: 16px;
                background: #6b7280;
                margin-left: 2px;
                animation: blink 1s infinite;
                vertical-align: middle;
            `;
            
            // Add cursor animation if not exists
            if (!document.querySelector('#aintento-cursor-style')) {
                const style = document.createElement('style');
                style.id = 'aintento-cursor-style';
                style.textContent = '@keyframes blink { 0%, 50% { opacity: 1; } 51%, 100% { opacity: 0; } }';
                document.head.appendChild(style);
            }
            
            bubble.appendChild(textSpan);
            bubble.appendChild(cursor);
            wrapper.appendChild(avatar);
            wrapper.appendChild(bubble);
            container.appendChild(wrapper);
            
            // Scroll to the new element
            wrapper.scrollIntoView({ behavior: 'smooth', block: 'end' });
            
            return wrapper;
        }
        
        // Update streaming text content
        function updateStreamingText(element, text) {
            const textSpan = element.querySelector('.streaming-text');
            if (textSpan) {
                let displayText = '';
                
                // Check if this looks like raw JSON/function call output
                const looksLikeJson = text.trim().startsWith('{') || 
                                      text.includes('```') ||
                                      text.includes('"action"') ||
                                      text.includes('"tool"') ||
                                      text.includes('search_products') ||
                                      text.includes('function_call');
                
                // Try to extract intro from complete JSON response
                if (text.includes('"intro"')) {
                    try {
                        // Find the outermost JSON object
                        const firstBrace = text.indexOf('{');
                        const lastBrace = text.lastIndexOf('}');
                        if (firstBrace !== -1 && lastBrace > firstBrace) {
                            const jsonStr = text.substring(firstBrace, lastBrace + 1);
                            const parsed = JSON.parse(jsonStr);
                            if (parsed.intro) {
                                displayText = parsed.intro;
                            }
                        }
                    } catch (e) {
                        // JSON not complete yet - try regex
                        const introMatch = text.match(/"intro"\s*:\s*"([^"]+)"/);
                        if (introMatch) {
                            displayText = introMatch[1];
                        }
                    }
                } else if (!looksLikeJson) {
                    // Not JSON - show as plain text
                    displayText = text;
                }
                
                // If we have no displayable text but got data, show thinking indicator
                if (!displayText && text.length > 0) {
                    displayText = 'Шукаю для вас...';
                }
                
                textSpan.textContent = displayText;
                
                // Scroll to bottom if we have content
                if (displayText) {
                    element.scrollIntoView({ behavior: 'smooth', block: 'end' });
                }
            }
        }
        
        // Finalize streaming text (remove cursor)
        function finalizeStreamingText(element, text) {
            const cursor = element.querySelector('.streaming-cursor');
            if (cursor) {
                cursor.remove();
            }
            
            // Final text cleanup - extract intro from JSON if needed
            let displayText = '';
            
            // Check if this looks like raw JSON/function call output
            const looksLikeJson = text.trim().startsWith('{') || 
                                  text.includes('```') ||
                                  text.includes('"action"') ||
                                  text.includes('"tool"') ||
                                  text.includes('search_products') ||
                                  text.includes('function_call');
            
            if (text.includes('"intro"')) {
                try {
                    const firstBrace = text.indexOf('{');
                    const lastBrace = text.lastIndexOf('}');
                    if (firstBrace !== -1 && lastBrace > firstBrace) {
                        const jsonStr = text.substring(firstBrace, lastBrace + 1);
                        const parsed = JSON.parse(jsonStr);
                        if (parsed.intro) {
                            displayText = parsed.intro;
                        }
                    }
                } catch (e) {
                    // Try regex fallback
                    const introMatch = text.match(/"intro"\s*:\s*"([^"]+)"/);
                    if (introMatch) {
                        displayText = introMatch[1];
                    }
                }
            } else if (!looksLikeJson) {
                // Not JSON - show as plain text
                displayText = text;
            }
            
            const textSpan = element.querySelector('.streaming-text');
            if (textSpan) {
                textSpan.textContent = displayText;
            }
            
            // If no readable text, hide the element
            if (!displayText || displayText.trim().startsWith('{') || displayText === 'Шукаю для вас...') {
                element.style.display = 'none';
            }
        }
        
        // Fallback fetch version (non-streaming)
        function sendMessageFetch(message) {
            const loader = addLoader(messages);
            const chatApiUrl = BASE_URL + '/api/chat';

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

                // Track if we need to scroll (only for first element)
                let hasScrolled = false;

                if (data.text) {
                    const firstMsg = addMessage(messages, data.text, 'assistant', state.sessionId, true, !hasScrolled);
                    if (!hasScrolled && firstMsg) {
                        hasScrolled = true;
                    }
                }

                if (data.data?.product_cards?.length > 0) {
                    if (!hasScrolled) {
                        // Scroll to first product card area
                        setTimeout(() => {
                            const cards = messages.querySelectorAll('.aintento-message');
                            if (cards.length > 0) {
                                cards[cards.length - data.data.product_cards.length]?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            }
                        }, 100);
                        hasScrolled = true;
                    }
                    addProductCards(messages, data.data.product_cards, state.sessionId, true);
                } else if (data.data?.products?.length > 0) {
                    if (!hasScrolled) {
                        setTimeout(() => {
                            const container = messages.lastElementChild;
                            container?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }, 100);
                        hasScrolled = true;
                    }
                    addProducts(messages, data.data.products, state.sessionId, true);
                }
                
                // Fetch cross-sell asynchronously (doesn't block main response)
                if (data.data?.cross_sell_product_id) {
                    fetchCrossSellAsync(messages, data.data.cross_sell_product_id, settings, state.sessionId);
                }
                // Legacy: show cross-sell if returned directly (for backwards compatibility)
                else if (data.data?.cross_sell) {
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
        
        // Fetch cross-sell suggestions asynchronously
        function fetchCrossSellAsync(messagesContainer, productId, settings, sessionId) {
            const crossSellUrl = BASE_URL + '/api/cross-sell?product_id=' + productId;
            
            fetch(crossSellUrl, {
                headers: {
                    'X-Widget-Token': token,
                    'Content-Type': 'application/json'
                }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.cross_sell) {
                    setTimeout(() => {
                        addCrossSell(messagesContainer, data.cross_sell, settings, sessionId);
                    }, 300);
                }
            })
            .catch(err => {
                log('Cross-sell fetch failed (non-critical):', err);
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

        // Expose openChat function globally for external buttons
        window.openChat = openWidget;
        window.aintentoOpen = openWidget;
        window.aintentoClose = closeWidget;
    }

    function addQuickActions(messagesContainer, settings, onActionClick) {
        const s = settings || window.aintentoSettings || { primary_color: '#2563eb' };
        
        const quickActions = [
            {
                icon: '🔥',
                label: 'Топ товари',
                action: 'top_products'
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
                // Track quick action click
                sendAnalyticsEvent('quick_action_click', {
                    action: qa.action,
                    label: qa.label
                });
                // Remove quick actions after click
                container.remove();
                onActionClick(qa.action);
            };
            
            container.appendChild(btn);
        });

        messagesContainer.appendChild(container);
    }

    function addMessage(messagesContainer, text, role, sessionId, save = true, scrollToView = false) {
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
        
        if (scrollToView) {
            div.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        if (save) {
            saveMessage(sessionId, { role, content: text });
            // Track message event for analytics
            sendAnalyticsEvent('message', {
                message_type: role,
                message_text: text?.substring(0, 200) // Truncate for privacy
            });
        }
        
        return div;
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
        
        // Track products shown for analytics
        trackProductsShown(products.slice(0, 3));
        
        if (save) {
            saveMessage(sessionId, { role: 'products', products: products.slice(0, 3) });
        }
    }

    function createProductCard(product, settings, index) {
        const card = document.createElement('a');
        // Add UTM parameters to product link for attribution
        const sessionId = localStorage.getItem('aintento_session_id') || '';
        const productLink = addUtmToLink(product.link, sessionId, product.id);
        card.href = productLink || '#';
        card.target = '_blank';
        card.style.cssText = `
            display: block;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 8px;
            text-decoration: none;
            color: #374151;
            transition: all 0.2s;
            animation: aintento-fadeInUp 0.3s ease-out;
            animation-delay: ${index * 0.1}s;
        `;
        
        // Track product click for analytics
        card.addEventListener('click', () => {
            trackProductClick(product);
        });

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

        // Build product summary from description and characteristics
        let summaryText = '';
        
        // First try characteristics (object format: {name: value} from backend)
        if (product.characteristics && typeof product.characteristics === 'object') {
            const chars = product.characteristics;
            // Handle both object {key: value} and array [{name, value}] formats
            let charParts = [];
            
            if (Array.isArray(chars)) {
                // Array format: [{name, value}]
                charParts = chars.slice(0, 3).map(c => {
                    const name = c.name || c.n || '';
                    const value = c.value || c.v || '';
                    return name && value ? `${name}: ${value}` : '';
                }).filter(Boolean);
            } else {
                // Object format: {name: value}
                const entries = Object.entries(chars).slice(0, 3);
                charParts = entries.map(([name, value]) => {
                    if (name && value && typeof value === 'string') {
                        return `${name}: ${value}`;
                    }
                    return '';
                }).filter(Boolean);
            }
            
            if (charParts.length > 0) {
                summaryText = charParts.join(' • ');
            }
        }
        
        // If no characteristics, use description
        if (!summaryText && product.description) {
            summaryText = product.description.replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
            if (summaryText.length > 120) {
                summaryText = summaryText.substring(0, 120) + '...';
            }
        }
        
        // Fallback: build from brand, category_path, color
        if (!summaryText) {
            const parts = [];
            if (product.brand) parts.push(product.brand);
            if (product.category_path) {
                // Extract last part of category path
                const cats = product.category_path.split('/');
                if (cats.length > 0) parts.push(cats[cats.length - 1].trim());
            }
            if (parts.length > 0) {
                summaryText = parts.join(' • ');
            }
        }
        
        const cardId = 'card-' + product.id + '-' + Date.now();
        
        // Build color variants HTML (if multiple colors available)
        let colorHtml = '';
        const colorVariants = (product.color_variants || []).filter(cv => cv.color && cv.color !== 'null' && cv.color.trim() !== '');
        const hasMultipleColors = colorVariants.length > 1;
        
        if (hasMultipleColors) {
            // Clickable color buttons
            const colorButtons = colorVariants.map(cv => {
                const isActive = cv.is_current;
                const activeStyle = isActive 
                    ? `background: ${settings.primary_color}; color: white; border-color: ${settings.primary_color};`
                    : 'background: #f3f4f6; color: #374151; border-color: #e5e7eb;';
                return `<button type="button" class="color-btn" 
                    data-color="${cv.color}" 
                    data-sizes='${JSON.stringify(cv.sizes || [])}'
                    style="padding: 2px 8px; font-size: 10px; border-radius: 4px; border: 1px solid; cursor: pointer; ${activeStyle}"
                >${cv.color}</button>`;
            }).join('');
            colorHtml = `<div class="color-variants" style="display: flex; gap: 4px; flex-wrap: wrap; margin-top: 4px; align-items: center;">
                <span style="font-size: 10px; color: #6b7280;">Колір:</span>${colorButtons}
            </div>`;
        } else if (product.color && product.color !== 'null' && !summaryText.includes(product.color)) {
            // Single color - just display
            colorHtml = `<div style="margin-top: 4px;"><span style="font-size: 10px; color: #6b7280;">Колір: </span><span style="font-size: 11px; font-weight: 500;">${product.color}</span></div>`;
        }
        
        // Build size variants HTML (clickable buttons that switch the card)
        // Use current color's sizes if available, otherwise use flat size_variants
        let currentColorSizes = [];
        if (hasMultipleColors) {
            const currentColorVariant = colorVariants.find(cv => cv.is_current);
            currentColorSizes = currentColorVariant?.sizes || [];
        }
        const sizesToShow = currentColorSizes.length > 0 ? currentColorSizes : (product.size_variants || []);
        
        let sizeHtml = '';
        // Filter out variants with null/empty size
        const validSizes = sizesToShow.filter(v => v.size && v.size !== 'null' && v.size !== '-');
        if (validSizes.length > 1) {
            const buttons = validSizes.map(v => {
                const isActive = v.id === product.id || v.article === product.article;
                const activeStyle = isActive 
                    ? `background: ${settings.primary_color}; color: white; border-color: ${settings.primary_color};`
                    : 'background: #f3f4f6; color: #374151; border-color: #e5e7eb;';
                // Store variant data as data attributes
                return `<button type="button" class="size-btn" 
                    data-size="${v.size}" 
                    data-link="${v.link || ''}" 
                    data-id="${v.id}"
                    data-article="${v.article || ''}"
                    style="padding: 2px 6px; font-size: 10px; border-radius: 4px; border: 1px solid; cursor: pointer; ${activeStyle}"
                >${v.size}</button>`;
            }).join('');
            sizeHtml = `<div class="size-variants" style="display: flex; gap: 4px; flex-wrap: wrap; margin-top: 4px;">${buttons}</div>`;
        } else if (product.size && product.size !== '-' && product.size !== 'null') {
            // Single size, just show label
            sizeHtml = `<div style="margin-top: 4px;"><span style="font-size: 10px; color: #6b7280;">Розмір: </span><span style="font-size: 11px; font-weight: 500;">${product.size}</span></div>`;
        }

        // Store all variants data on the card for switching
        card.dataset.variants = JSON.stringify(product.size_variants || []);
        card.dataset.colorVariants = JSON.stringify(product.color_variants || []);
        card.dataset.currentLink = product.link || '#';
        card.id = cardId;

        card.innerHTML = `
            <div style="display: flex; gap: 12px;">
                ${imgHtml}
                <div style="flex: 1; min-width: 0;">
                    <div style="font-weight: 600; font-size: 13px; margin-bottom: 4px; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">${product.title}</div>
                    ${summaryText ? `<div style="font-size: 11px; color: #6b7280; margin-bottom: 4px; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">${summaryText}</div>` : ''}
                    ${colorHtml}
                    <div class="size-container">${sizeHtml}</div>
                    <div style="color: ${settings.primary_color}; font-weight: 700; font-size: 16px; margin-top: 4px;">${product.price} ₴</div>
                </div>
            </div>
        `;

        // Add click handlers for color and size buttons
        setTimeout(() => {
            const primaryColor = settings.primary_color;
            
            // Color button handlers
            const colorButtons = card.querySelectorAll('.color-btn');
            colorButtons.forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // Update color button styles
                    colorButtons.forEach(b => {
                        b.style.background = '#f3f4f6';
                        b.style.color = '#374151';
                        b.style.borderColor = '#e5e7eb';
                    });
                    btn.style.background = primaryColor;
                    btn.style.color = 'white';
                    btn.style.borderColor = primaryColor;
                    
                    // Get sizes for this color
                    const sizes = JSON.parse(btn.dataset.sizes || '[]');
                    const sizeContainer = card.querySelector('.size-container');
                    
                    if (sizes.length > 0) {
                        // Update size buttons for this color
                        const sizeButtons = sizes.map((v, idx) => {
                            const isFirst = idx === 0;
                            const activeStyle = isFirst 
                                ? `background: ${primaryColor}; color: white; border-color: ${primaryColor};`
                                : 'background: #f3f4f6; color: #374151; border-color: #e5e7eb;';
                            return `<button type="button" class="size-btn" 
                                data-size="${v.size}" 
                                data-link="${v.link || ''}" 
                                data-id="${v.id}"
                                data-article="${v.article || ''}"
                                style="padding: 2px 6px; font-size: 10px; border-radius: 4px; border: 1px solid; cursor: pointer; ${activeStyle}"
                            >${v.size}</button>`;
                        }).join('');
                        sizeContainer.innerHTML = `<div class="size-variants" style="display: flex; gap: 4px; flex-wrap: wrap; margin-top: 4px;">${sizeButtons}</div>`;
                        
                        // Update card link to first size of selected color
                        if (sizes[0]?.link) {
                            card.href = sizes[0].link;
                            card.dataset.currentLink = sizes[0].link;
                        }
                        
                        // Re-attach size button handlers
                        attachSizeHandlers(card, primaryColor);
                    } else {
                        sizeContainer.innerHTML = '';
                    }
                });
            });
            
            // Size button handlers
            attachSizeHandlers(card, primaryColor);
        }, 0);
        
        function attachSizeHandlers(card, primaryColor) {
            const sizeButtons = card.querySelectorAll('.size-btn');
            sizeButtons.forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    const newLink = btn.dataset.link;
                    
                    // Update card link
                    card.href = newLink || '#';
                    card.dataset.currentLink = newLink;
                    
                    // Update button styles
                    sizeButtons.forEach(b => {
                        b.style.background = '#f3f4f6';
                        b.style.color = '#374151';
                        b.style.borderColor = '#e5e7eb';
                    });
                    btn.style.background = primaryColor;
                    btn.style.color = 'white';
                    btn.style.borderColor = primaryColor;
                });
            });
        }

        return card;
    }

    function addCrossSell(messagesContainer, crossSell, settings, sessionId) {
        if (!crossSell || !crossSell.suggestions?.length) return;
        
        const s = settings || window.aintentoSettings || { primary_color: '#2563eb' };
        
        // Track cross-sell shown
        sendAnalyticsEvent('cross_sell_shown', {
            products_count: crossSell.suggestions.length,
            product_ids: crossSell.suggestions.map(p => p.id).join(',')
        });
        
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
            border-radius: 12px;
            padding: 10px;
        `;
        
        // Header
        const header = document.createElement('div');
        header.style.cssText = 'display: flex; align-items: center; gap: 6px; margin-bottom: 8px;';
        header.innerHTML = `
            <span style="font-size: 14px;">🎯</span>
            <div>
                <div style="font-weight: 600; font-size: 12px; color: #1f2937;">${crossSell.title || 'Разом краще'}</div>
                <div style="font-size: 10px; color: #92400e;">${crossSell.subtitle || 'Часто беруть разом'}</div>
            </div>
        `;
        container.appendChild(header);
        
        // Items CAROUSEL (horizontal scroll)
        const carousel = document.createElement('div');
        carousel.style.cssText = `
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 6px;
            margin: 0 -4px;
            padding: 4px;
            scroll-snap-type: x mandatory;
        `;
        
        crossSell.suggestions.forEach((item) => {
            const card = document.createElement('div');
            card.style.cssText = `
                flex-shrink: 0;
                width: 110px;
                background: white;
                border-radius: 8px;
                padding: 8px;
                border: 1px solid #e5e7eb;
                transition: all 0.2s;
                position: relative;
                scroll-snap-align: start;
                display: flex;
                flex-direction: column;
            `;
            
            // Info icon with tooltip (reason why AI suggested this)
            const infoIcon = document.createElement('div');
            infoIcon.style.cssText = `
                position: absolute;
                top: 6px;
                right: 6px;
                width: 18px;
                height: 18px;
                background: linear-gradient(135deg, ${s.primary_color}, #60a5fa);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 11px;
                color: white;
                cursor: pointer;
                font-weight: 700;
                z-index: 2;
                box-shadow: 0 2px 6px rgba(37, 99, 235, 0.4);
                transition: all 0.2s;
                animation: aintento-info-pulse 2s infinite;
            `;
            infoIcon.textContent = 'i';
            infoIcon.title = item.reason || 'Рекомендований товар';
            
            // Add pulse animation for info icon if not exists
            if (!document.querySelector('#aintento-info-pulse-style')) {
                const style = document.createElement('style');
                style.id = 'aintento-info-pulse-style';
                style.textContent = '@keyframes aintento-info-pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }';
                document.head.appendChild(style);
            }
            
            infoIcon.onmouseenter = () => { 
                infoIcon.style.transform = 'scale(1.2)';
                infoIcon.style.animation = 'none';
            };
            infoIcon.onmouseleave = () => { 
                infoIcon.style.transform = 'scale(1)';
                infoIcon.style.animation = 'aintento-info-pulse 2s infinite';
            };
            
            card.appendChild(infoIcon);
            
            // Image (clickable)
            const imgLink = document.createElement('a');
            imgLink.href = item.link || '#';
            imgLink.target = '_blank';
            imgLink.style.cssText = 'display: block; margin-bottom: 6px;';
            imgLink.innerHTML = item.image 
                ? `<img src="${item.image}" style="width: 100%; height: 55px; object-fit: cover; border-radius: 6px;" onerror="this.style.display='none'; this.parentElement.innerHTML='<div style=\\'width:100%;height:55px;background:#f1f5f9;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:20px\\'>📦</div>'">`
                : '<div style="width:100%;height:55px;background:#f1f5f9;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:20px">📦</div>';
            card.appendChild(imgLink);
            
            // Title (clickable)
            const titleLink = document.createElement('a');
            titleLink.href = item.link || '#';
            titleLink.target = '_blank';
            titleLink.style.cssText = `
                display: block;
                font-size: 10px;
                font-weight: 600;
                color: #1f2937;
                text-decoration: none;
                line-height: 1.2;
                height: 24px;
                overflow: hidden;
                margin-bottom: 3px;
            `;
            titleLink.textContent = item.title;
            titleLink.onmouseenter = () => { titleLink.style.color = s.primary_color; };
            titleLink.onmouseleave = () => { titleLink.style.color = '#1f2937'; };
            card.appendChild(titleLink);
            
            // Price
            const price = document.createElement('div');
            price.style.cssText = `font-size: 12px; font-weight: 700; color: ${s.primary_color}; margin-bottom: 6px;`;
            price.textContent = `${item.price} ₴`;
            card.appendChild(price);
            
            // Spacer to push button to bottom
            const spacer = document.createElement('div');
            spacer.style.cssText = 'flex: 1;';
            card.appendChild(spacer);
            
            // "+ Цікаво" button
            const interestedBtn = document.createElement('button');
            interestedBtn.style.cssText = `
                width: 100%;
                background: ${s.primary_color};
                color: white;
                padding: 5px 6px;
                border: none;
                border-radius: 5px;
                font-size: 10px;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.2s;
            `;
            interestedBtn.textContent = '+ Цікаво';
            interestedBtn.onmouseenter = () => { interestedBtn.style.opacity = '0.85'; };
            interestedBtn.onmouseleave = () => { interestedBtn.style.opacity = '1'; };
            interestedBtn.onclick = (e) => {
                e.stopPropagation();
                
                // Track cross-sell click
                sendAnalyticsEvent('cross_sell_click', {
                    product_id: item.id,
                    product_article: item.article,
                    product_price: item.price
                });
                
                // Animate card removal
                card.style.opacity = '0';
                card.style.transform = 'scale(0.9)';
                card.style.transition = 'all 0.3s ease';
                
                setTimeout(() => {
                    card.remove();
                    
                    // Add product to chat as clickable card
                    addCrossSellProductToChat(messagesContainer, item, s, sessionId);
                    
                    // If no more items, remove the whole block
                    if (carousel.children.length === 0) {
                        wrapper.style.opacity = '0';
                        wrapper.style.transform = 'translateY(-10px)';
                        wrapper.style.transition = 'all 0.3s ease';
                        setTimeout(() => wrapper.remove(), 300);
                    }
                }, 300);
            };
            card.appendChild(interestedBtn);
            
            // Hover effect
            card.onmouseenter = () => {
                card.style.borderColor = s.primary_color;
                card.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
            };
            card.onmouseleave = () => {
                card.style.borderColor = '#e5e7eb';
                card.style.boxShadow = 'none';
            };
            
            carousel.appendChild(card);
        });
        
        container.appendChild(carousel);
        
        // Hint about adding to cart
        const hint = document.createElement('div');
        hint.style.cssText = 'margin-top: 8px; font-size: 9px; color: #92400e; text-align: center;';
        hint.innerHTML = `💡 ${crossSell.hint || 'Щоб замовити — додайте товар у кошик на сайті'}`;
        container.appendChild(hint);
        
        // Dismiss button
        const dismissBtn = document.createElement('button');
        dismissBtn.style.cssText = `
            display: block;
            width: 100%;
            margin-top: 6px;
            padding: 4px;
            background: transparent;
            border: none;
            color: #9ca3af;
            font-size: 10px;
            cursor: pointer;
            transition: color 0.2s;
        `;
        dismissBtn.textContent = 'Приховати';
        dismissBtn.onmouseenter = () => { dismissBtn.style.color = '#6b7280'; };
        dismissBtn.onmouseleave = () => { dismissBtn.style.color = '#9ca3af'; };
        dismissBtn.onclick = () => {
            wrapper.style.opacity = '0';
            wrapper.style.transform = 'translateY(-10px)';
            wrapper.style.transition = 'all 0.3s ease';
            setTimeout(() => wrapper.remove(), 300);
        };
        container.appendChild(dismissBtn);
        
        wrapper.appendChild(container);
        messagesContainer.appendChild(wrapper);
    }
    
    // Add cross-sell product as clickable card in chat
    function addCrossSellProductToChat(messagesContainer, item, settings, sessionId) {
        const s = settings || window.aintentoSettings || { primary_color: '#2563eb' };
        
        // Add intro message (no markdown, plain text)
        addMessage(messagesContainer, `Ви зацікавились: ${item.title}`, 'assistant', sessionId, false);
        
        // Create clickable product card
        const card = document.createElement('a');
        card.href = item.link || '#';
        card.target = '_blank';
        card.style.cssText = `
            display: block;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 12px;
            text-decoration: none;
            color: #374151;
            transition: all 0.2s;
            animation: aintento-fadeInUp 0.3s ease-out;
        `;
        
        card.onmouseenter = () => {
            card.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
            card.style.transform = 'translateY(-2px)';
            card.style.borderColor = s.primary_color;
        };
        card.onmouseleave = () => {
            card.style.boxShadow = 'none';
            card.style.transform = 'translateY(0)';
            card.style.borderColor = '#e5e7eb';
        };
        
        const imgHtml = item.image 
            ? `<img src="${item.image}" style="width: 70px; height: 70px; object-fit: cover; border-radius: 8px; flex-shrink: 0;" onerror="this.style.display='none'">`
            : '';
        
        const summaryHtml = item.summary 
            ? `<div style="font-size: 11px; color: #6b7280; margin-bottom: 4px; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">${item.summary}</div>`
            : '';
        
        // Color display
        const colorHtml = item.color
            ? `<span style="font-size: 11px; color: #6b7280;">🎨 ${item.color}</span>`
            : '';
        
        // Size display
        const sizeHtml = item.size
            ? `<span style="font-size: 11px; color: #6b7280; ${item.color ? 'margin-left: 8px;' : ''}">📐 ${item.size}</span>`
            : '';
        
        const metaHtml = (colorHtml || sizeHtml) 
            ? `<div style="margin-bottom: 4px;">${colorHtml}${sizeHtml}</div>`
            : '';
        
        card.innerHTML = `
            <div style="display: flex; gap: 12px;">
                ${imgHtml}
                <div style="flex: 1; min-width: 0;">
                    <div style="font-weight: 600; font-size: 13px; margin-bottom: 4px; line-height: 1.3;">${item.title}</div>
                    ${summaryHtml}
                    ${metaHtml}
                    <div style="color: ${s.primary_color}; font-weight: 700; font-size: 16px;">${item.price} ₴</div>
                </div>
            </div>
            <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #f3f4f6; font-size: 11px; color: #6b7280; text-align: center;">
                👆 Натисніть щоб відкрити на сайті та додати в кошик
            </div>
        `;
        
        messagesContainer.appendChild(card);
        // Scroll to the product card
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        
        // Save to history
        saveMessage(sessionId, { role: 'cross_sell_interest', product: item });
    }

    function addLoader(messagesContainer) {
        const s = window.aintentoSettings || { primary_color: '#2563eb' };
        const div = document.createElement('div');
        div.className = 'aintento-loader';
        div.style.cssText = 'margin-bottom: 16px; display: flex; justify-content: flex-start;';
        div.innerHTML = `
            <div style="background: #f3f4f6; padding: 12px 16px; border-radius: 18px 18px 18px 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); display: flex; align-items: center; gap: 8px;">
                <div style="display: flex; gap: 2px;">
                    <span style="display: inline-block; color: ${s.primary_color}; font-size: 16px; animation: aintento-pulse 1.4s infinite;">●</span>
                    <span style="display: inline-block; color: ${s.primary_color}; font-size: 16px; animation: aintento-pulse 1.4s 0.2s infinite;">●</span>
                    <span style="display: inline-block; color: ${s.primary_color}; font-size: 16px; animation: aintento-pulse 1.4s 0.4s infinite;">●</span>
                </div>
                <span class="aintento-loader-text" style="color: #6b7280; font-size: 13px;">Думаю...</span>
            </div>
        `;
        messagesContainer.appendChild(div);
        div.scrollIntoView({ behavior: 'smooth', block: 'end' });
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

    // === Analytics & Attribution ===
    
    /**
     * Add UTM parameters to product link for attribution tracking
     */
    function addUtmToLink(link, sessionId, productId) {
        if (!link || link === '#') return link;
        try {
            const url = new URL(link);
            url.searchParams.set('utm_source', 'aintento');
            url.searchParams.set('utm_medium', 'chat');
            url.searchParams.set('utm_campaign', 'widget');
            if (sessionId) url.searchParams.set('utm_content', sessionId);
            if (productId) url.searchParams.set('utm_term', String(productId));
            return url.toString();
        } catch (e) {
            return link;
        }
    }

    /**
     * Track product click for attribution
     */
    function trackProductClick(product) {
        // Store clicked product in localStorage for attribution
        const key = 'aintento_clicked_products';
        let clicked = [];
        try {
            clicked = JSON.parse(localStorage.getItem(key) || '[]');
        } catch (e) {}

        clicked.push({
            id: product.id,
            article: product.article,
            price: product.price,
            session_id: localStorage.getItem('aintento_session_id'),
            timestamp: Date.now()
        });

        // Keep only last 72 hours, max 50 items
        const cutoff = Date.now() - (72 * 60 * 60 * 1000);
        clicked = clicked.filter(p => p.timestamp > cutoff).slice(-50);
        localStorage.setItem(key, JSON.stringify(clicked));

        // Send event to server
        sendAnalyticsEvent('product_click', {
            product_id: product.id,
            product_article: product.article,
            product_price: product.price
        });
    }

    /**
     * Track products shown in chat
     */
    function trackProductsShown(products) {
        products.forEach(product => {
            sendAnalyticsEvent('product_shown', {
                product_id: product.id,
                product_article: product.article,
                product_price: product.price
            });
        });
    }

    /**
     * Send analytics event to server
     */
    const analyticsQueue = [];
    let analyticsFlushTimeout = null;

    function sendAnalyticsEvent(eventType, data = {}) {
        log('Analytics event queued:', eventType);
        const event = {
            session_id: localStorage.getItem('aintento_session_id') || '',
            client_id: getOrCreateClientId(),
            merchant_id: getMerchantId(),
            event_type: eventType,
            event_source: 'widget',
            device_type: detectDeviceType(),
            page_url: window.location.href,
            referrer: document.referrer,
            timestamp: new Date().toISOString(),
            ...data
        };

        analyticsQueue.push(event);
        log('Analytics queue size:', analyticsQueue.length);

        // Debounced flush
        if (analyticsFlushTimeout) clearTimeout(analyticsFlushTimeout);
        analyticsFlushTimeout = setTimeout(flushAnalytics, 2000);

        // Immediate flush for important events
        if (['add_to_cart', 'purchase'].includes(eventType)) {
            flushAnalytics();
        }
    }

    function flushAnalytics() {
        if (analyticsQueue.length === 0) return;

        const events = [...analyticsQueue];
        analyticsQueue.length = 0;

        const payload = JSON.stringify({ events });
        log('Flushing analytics:', events.length, 'events', events.map(e => e.event_type));
        log('Payload size:', payload.length, 'bytes');
        log('Payload preview:', payload.substring(0, 200));

        // Use fetch with keepalive for reliable delivery
        fetch(BASE_URL + '/api/analytics/events', {
            method: 'POST',
            headers: { 'Content-Type': 'text/plain' },
            body: payload,
            keepalive: true
        }).then(response => {
            log('Analytics fetch response:', response.status);
            return response.json();
        }).then(data => {
            log('Analytics response data:', data);
        }).catch(err => {
            log('Analytics fetch error:', err.message);
        });
    }

    function getOrCreateClientId() {
        const cookieName = 'aintento_client_id';
        let clientId = document.cookie.match(new RegExp('(^| )' + cookieName + '=([^;]+)'))?.[2];
        if (!clientId) {
            clientId = 'cli_' + Date.now() + '_' + Math.random().toString(36).substr(2, 12);
            const expires = new Date(Date.now() + 72 * 60 * 60 * 1000).toUTCString();
            document.cookie = `${cookieName}=${clientId}; expires=${expires}; path=/; SameSite=Lax`;
        }
        return clientId;
    }

    function getMerchantId() {
        const container = document.getElementById('aintento-chat');
        return container?.dataset?.token || window.location.hostname;
    }

    function detectDeviceType() {
        const ua = navigator.userAgent;
        if (/tablet|ipad|playbook|silk/i.test(ua)) return 'tablet';
        if (/mobile|iphone|ipod|android|blackberry|opera mini|iemobile/i.test(ua)) return 'mobile';
        return 'desktop';
    }

    // Flush analytics on page unload
    window.addEventListener('pagehide', flushAnalytics);
    window.addEventListener('beforeunload', flushAnalytics);

})();


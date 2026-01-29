/**
 * AIntento Chat Widget v2.7.0 (Modular)
 * Main entry point - assembles all modules
 * 
 * Usage: <div id="aintento-chat" data-token="YOUR_TOKEN"></div>
 *        <script src="https://aintento.laravel.cloud/widget.js"></script>
 */

import { 
    WIDGET_VERSION, 
    BASE_URL, 
    BOT_AVATAR,
    setBotAvatar,
    getDefaultSettings, 
    setSettings,
    widgetState,
    updateState 
} from './config.js';

import { log, logError } from './utils.js';
import { getOrCreateSessionId, loadMessages, saveMessage } from './session.js';
import { sendAnalyticsEvent, flushAnalytics } from './analytics.js';
import { injectStyles, createWidgetHTML, getWidgetElements, createLoader, removeLoader } from './ui.js';

// Re-export everything for external use
export * from './config.js';
export * from './utils.js';
export * from './session.js';
export * from './analytics.js';
export * from './ui.js';

/**
 * Initialize widget when DOM is ready
 */
function init() {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWidget);
    } else {
        initWidget();
    }
}

/**
 * Main widget initialization
 */
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

    fetch(BASE_URL + '/api/widget/settings', {
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

/**
 * Render widget with settings
 */
function renderWidget(container, settings, token) {
    if (!settings.enabled) {
        log('Widget disabled in settings');
        return;
    }

    // Set bot avatar
    const avatar = settings.bot_avatar_base64 || settings.bot_avatar_url || (BASE_URL + '/images/aintento-avatar.svg');
    setBotAvatar(avatar);
    
    // Store settings globally
    setSettings(settings);

    // Inject CSS
    injectStyles(settings);

    // Get/create session
    const sessionId = getOrCreateSessionId();
    const savedMessages = loadMessages(sessionId);

    // Create HTML
    container.innerHTML = createWidgetHTML(settings);

    // Get DOM elements
    const elements = getWidgetElements();
    
    // Update state
    updateState({
        sessionId,
        elements,
        settings
    });

    // Setup event handlers
    setupEventHandlers(elements, settings, token, savedMessages);
    
    // Track page view
    sendAnalyticsEvent('page_view', {
        widget_version: WIDGET_VERSION
    });
    
    // Expose global API
    window.openChat = () => openChat(elements);
    window.aintentoClose = () => closeChat(elements);
}

/**
 * Setup all event handlers
 */
function setupEventHandlers(elements, settings, token, savedMessages) {
    const { toggle, close, window: chatWindow, overlay, input, send, bubble, bubbleClose, messages } = elements;
    
    // Toggle button click
    toggle?.addEventListener('click', () => {
        if (widgetState.isOpen) {
            closeChat(elements);
        } else {
            openChat(elements);
        }
    });
    
    // Close button click
    close?.addEventListener('click', () => closeChat(elements));
    
    // Overlay click (mobile)
    overlay?.addEventListener('click', () => closeChat(elements));
    
    // Bubble click
    bubble?.addEventListener('click', () => openChat(elements));
    
    // Bubble close
    bubbleClose?.addEventListener('click', (e) => {
        e.stopPropagation();
        hideBubble(elements);
    });
    
    // Send button
    send?.addEventListener('click', () => {
        const text = input.value.trim();
        if (text) {
            sendMessage(text, elements, settings, token);
        }
    });
    
    // Input enter key
    input?.addEventListener('keypress', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            const text = input.value.trim();
            if (text) {
                sendMessage(text, elements, settings, token);
            }
        }
    });
    
    // Input focus styling
    input?.addEventListener('focus', () => {
        input.style.borderColor = settings.primary_color;
        input.style.boxShadow = `0 0 0 3px ${settings.primary_color}20`;
    });
    
    input?.addEventListener('blur', () => {
        input.style.borderColor = '#e5e7eb';
        input.style.boxShadow = 'none';
    });
    
    // Restore messages if any
    if (savedMessages.length > 0) {
        restoreMessages(messages, savedMessages, settings);
        widgetState.hasShownWelcome = true;
    }
    
    // Show bubble hint after delay
    setTimeout(() => {
        if (!widgetState.isOpen && !widgetState.hasShownWelcome) {
            showBubble(elements);
        }
    }, 5000);
}

/**
 * Open chat window
 */
function openChat(elements) {
    const { window: chatWindow, overlay, bubble, toggle, messages } = elements;
    const settings = widgetState.settings;
    
    chatWindow.style.display = 'flex';
    overlay.style.display = 'block';
    overlay.style.opacity = '1';
    widgetState.isOpen = true;
    window.aintentoIsOpen = true;
    
    // Hide bubble
    hideBubble(elements);
    
    // Change toggle icon
    toggle.innerHTML = '✕';
    toggle.style.fontSize = '24px';
    
    // Focus input
    elements.input?.focus();
    
    // Show welcome if first open
    if (!widgetState.hasShownWelcome && messages) {
        addMessage(messages, settings.welcome_message, 'assistant', widgetState.sessionId, true);
        widgetState.hasShownWelcome = true;
    }
    
    // Track open event
    sendAnalyticsEvent('chat_opened', {});
    
    // Notify proactive triggers
    window.aintentoTriggers?.onChatOpened?.();
}

/**
 * Close chat window
 */
function closeChat(elements) {
    const { window: chatWindow, overlay, toggle } = elements;
    
    chatWindow.style.display = 'none';
    overlay.style.display = 'none';
    overlay.style.opacity = '0';
    widgetState.isOpen = false;
    window.aintentoIsOpen = false;
    
    // Restore toggle icon
    toggle.innerHTML = `<img src="${BOT_AVATAR}" alt="Chat" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">`;
    toggle.style.fontSize = '28px';
    
    // Track close event
    sendAnalyticsEvent('chat_closed', {});
    
    // Notify proactive triggers
    window.aintentoTriggers?.onChatClosed?.();
}

/**
 * Show bubble hint
 */
function showBubble(elements) {
    const { bubble } = elements;
    if (!bubble) return;
    
    bubble.style.display = 'block';
    setTimeout(() => {
        bubble.style.opacity = '1';
    }, 10);
}

/**
 * Hide bubble hint
 */
function hideBubble(elements) {
    const { bubble } = elements;
    if (!bubble) return;
    
    bubble.style.opacity = '0';
    setTimeout(() => {
        bubble.style.display = 'none';
    }, 400);
}

/**
 * Add message to chat
 */
function addMessage(container, text, role, sessionId, skipSave = false) {
    const settings = widgetState.settings || getDefaultSettings();
    const isUser = role === 'user';
    
    const div = document.createElement('div');
    div.className = `aintento-message aintento-message-${role}`;
    div.style.cssText = `
        margin-bottom: 16px;
        display: flex;
        justify-content: ${isUser ? 'flex-end' : 'flex-start'};
        animation: aintento-fadeInUp 0.3s ease-out;
    `;
    
    const bubble = document.createElement('div');
    bubble.style.cssText = `
        max-width: 85%;
        padding: 12px 16px;
        border-radius: ${isUser ? '18px 18px 4px 18px' : '18px 18px 18px 4px'};
        background: ${isUser ? settings.primary_color : '#f3f4f6'};
        color: ${isUser ? 'white' : '#1f2937'};
        font-size: 14px;
        line-height: 1.5;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        word-wrap: break-word;
    `;
    
    // Parse markdown for assistant messages
    bubble.innerHTML = isUser ? text : parseSimpleMarkdown(text);
    
    div.appendChild(bubble);
    container.appendChild(div);
    
    // Scroll to bottom
    div.scrollIntoView({ behavior: 'smooth', block: 'end' });
    
    // Save to localStorage
    if (!skipSave) {
        saveMessage(sessionId, { role, content: text, timestamp: Date.now() });
    }
    
    return div;
}

/**
 * Parse simple markdown
 */
function parseSimpleMarkdown(text) {
    if (!text) return '';
    
    let html = text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    
    html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
    html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" style="color: inherit; text-decoration: underline;">$1</a>');
    html = html.replace(/^- (.+)$/gm, '<li style="margin-left: 16px;">$1</li>');
    html = html.replace(/\n/g, '<br>');
    
    return html;
}

/**
 * Restore messages from localStorage
 */
function restoreMessages(container, messages, settings) {
    messages.forEach(msg => {
        if (msg.role === 'user' || msg.role === 'assistant') {
            addMessage(container, msg.content, msg.role, widgetState.sessionId, true);
        }
    });
}

/**
 * Send message to server (streaming)
 */
async function sendMessage(text, elements, settings, token) {
    const { input, messages } = elements;
    
    // Clear input
    input.value = '';
    
    // Add user message
    addMessage(messages, text, 'user', widgetState.sessionId);
    
    // Track message sent
    sendAnalyticsEvent('message_sent', {
        message_length: text.length
    });
    
    // Show loader
    const loader = createLoader(settings);
    messages.appendChild(loader);
    loader.scrollIntoView({ behavior: 'smooth', block: 'end' });
    
    try {
        // Use SSE streaming
        await sendMessageStreaming(text, messages, settings, token, loader);
    } catch (err) {
        logError('Message send error:', err);
        removeLoader(loader);
        addMessage(messages, 'Вибачте, сталася помилка. Спробуйте ще раз.', 'assistant', widgetState.sessionId);
    }
}

/**
 * Send message with SSE streaming
 */
async function sendMessageStreaming(text, messagesContainer, settings, token, loader) {
    const params = new URLSearchParams({
        message: text,
        session_id: widgetState.sessionId,
        token: token
    });
    
    if (window.aintentoTenantId) {
        params.append('tenant_id', window.aintentoTenantId);
    }
    
    const eventSource = new EventSource(`${BASE_URL}/api/chat/stream?${params.toString()}`);
    let currentTextElement = null;
    let fullText = '';
    
    return new Promise((resolve, reject) => {
        eventSource.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                
                if (data.type === 'text_delta') {
                    removeLoader(loader);
                    
                    if (!currentTextElement) {
                        currentTextElement = createStreamingTextElement(messagesContainer, settings);
                    }
                    
                    fullText += data.text;
                    currentTextElement.innerHTML = parseSimpleMarkdown(fullText);
                    currentTextElement.scrollIntoView({ behavior: 'smooth', block: 'end' });
                }
                
                if (data.type === 'products') {
                    removeLoader(loader);
                    addProductCards(messagesContainer, data.products, settings, widgetState.sessionId);
                }
                
                if (data.type === 'done') {
                    eventSource.close();
                    
                    // Save message
                    if (fullText) {
                        saveMessage(widgetState.sessionId, { 
                            role: 'assistant', 
                            content: fullText, 
                            timestamp: Date.now() 
                        });
                    }
                    
                    resolve();
                }
                
                if (data.type === 'error') {
                    eventSource.close();
                    removeLoader(loader);
                    addMessage(messagesContainer, data.message || 'Сталася помилка', 'assistant', widgetState.sessionId);
                    resolve();
                }
            } catch (e) {
                logError('SSE parse error:', e);
            }
        };
        
        eventSource.onerror = (err) => {
            eventSource.close();
            removeLoader(loader);
            reject(err);
        };
        
        // Timeout after 60 seconds
        setTimeout(() => {
            eventSource.close();
            removeLoader(loader);
            resolve();
        }, 60000);
    });
}

/**
 * Create streaming text element
 */
function createStreamingTextElement(container, settings) {
    const div = document.createElement('div');
    div.className = 'aintento-message aintento-message-assistant';
    div.style.cssText = `
        margin-bottom: 16px;
        display: flex;
        justify-content: flex-start;
        animation: aintento-fadeInUp 0.3s ease-out;
    `;
    
    const bubble = document.createElement('div');
    bubble.style.cssText = `
        max-width: 85%;
        padding: 12px 16px;
        border-radius: 18px 18px 18px 4px;
        background: #f3f4f6;
        color: #1f2937;
        font-size: 14px;
        line-height: 1.5;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        word-wrap: break-word;
    `;
    
    div.appendChild(bubble);
    container.appendChild(div);
    
    return bubble;
}

/**
 * Add product cards to chat
 */
function addProductCards(container, products, settings, sessionId) {
    if (!products || products.length === 0) return;
    
    const wrapper = document.createElement('div');
    wrapper.className = 'aintento-products';
    wrapper.style.cssText = `
        display: flex;
        flex-direction: column;
        gap: 8px;
        margin-bottom: 16px;
        animation: aintento-fadeInUp 0.3s ease-out;
    `;
    
    products.forEach(product => {
        const card = createProductCard(product, settings, sessionId);
        wrapper.appendChild(card);
    });
    
    container.appendChild(wrapper);
    wrapper.scrollIntoView({ behavior: 'smooth', block: 'end' });
    
    // Track products shown
    sendAnalyticsEvent('products_shown', {
        count: products.length,
        product_ids: products.map(p => p.id).join(',')
    });
}

/**
 * Create product card element
 */
function createProductCard(product, settings, sessionId) {
    const card = document.createElement('a');
    card.href = product.link || '#';
    card.target = '_blank';
    card.style.cssText = `
        display: block;
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 12px;
        text-decoration: none;
        color: #374151;
        transition: all 0.2s;
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
    
    // Image
    let imgHtml = '';
    if (product.images?.length > 0) {
        imgHtml = `<img src="${product.images[0]}" style="width: 70px; height: 70px; object-fit: cover; border-radius: 8px; flex-shrink: 0; background: #f3f4f6;" onerror="this.style.display='none'">`;
    }
    
    card.innerHTML = `
        <div style="display: flex; gap: 12px;">
            ${imgHtml}
            <div style="flex: 1; min-width: 0;">
                <div style="font-weight: 600; font-size: 13px; margin-bottom: 4px; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">${product.title}</div>
                <div style="color: ${settings.primary_color}; font-weight: 700; font-size: 16px; margin-top: 4px;">${product.price} ₴</div>
            </div>
        </div>
    `;
    
    // Track click
    card.addEventListener('click', () => {
        sendAnalyticsEvent('product_click', {
            product_id: product.id,
            product_article: product.article,
            product_price: product.price
        });
    });
    
    return card;
}

// Auto-initialize
init();

// Export for external use
export { initWidget, openChat, closeChat, addMessage, sendMessage };

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>AIntento — Тактичний Асистент</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes typingDot {
            0%, 60%, 100% { opacity: 0.3; }
            30% { opacity: 1; }
        }
        @keyframes glow {
            0%, 100% { box-shadow: 0 0 5px rgba(34, 211, 238, 0.5); }
            50% { box-shadow: 0 0 15px rgba(34, 211, 238, 0.8); }
        }
        .message-appear { animation: fadeInUp 0.3s ease-out; }
        .avatar-glow { animation: glow 2s ease-in-out infinite; }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 flex items-center justify-center p-4">

<div class="w-full max-w-md">
    <div class="relative bg-white rounded-2xl shadow-2xl overflow-hidden mx-auto">
        {{-- Хедер чату --}}
        <div class="bg-gradient-to-r from-slate-900 to-slate-800 px-4 py-4 flex items-center gap-3">
            <div class="avatar-glow w-12 h-12 rounded-full bg-slate-700 flex items-center justify-center border-2 border-cyan-400/60 overflow-hidden">
                <img src="/images/aintento-avatar.svg" alt="AIntento" class="w-10 h-10">
            </div>
            <div class="flex-1">
                <div class="text-white font-semibold text-base">AIntento</div>
                <div class="text-cyan-400 text-xs flex items-center gap-1">
                    <span class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></span>
                    Завжди онлайн
                </div>
            </div>
            <button id="clearChat" class="text-slate-400 hover:text-white transition-colors p-2" title="Очистити чат">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
            </button>
        </div>

        {{-- Область повідомлень --}}
        <div id="chatMessages" class="p-4 space-y-3 bg-slate-50 h-[500px] overflow-y-auto">
            {{-- Повідомлення завантажуються з localStorage --}}
        </div>

        {{-- Інпут повідомлення --}}
        <div class="p-4 border-t bg-white">
            <form id="chatForm" class="flex items-center gap-2">
                @csrf
                <input
                    id="chatInput"
                    type="text"
                    placeholder="Напишіть, що шукаєте..."
                    class="flex-1 px-4 py-3 border border-slate-300 rounded-full text-sm outline-none focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 transition-all"
                    autocomplete="off"
                >
                <button
                    type="submit"
                    class="w-12 h-12 rounded-full bg-gradient-to-r from-slate-800 to-slate-900 flex items-center justify-center text-white hover:shadow-lg hover:scale-105 transition-all"
                    aria-label="Надіслати">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                    </svg>
                </button>
            </form>
        </div>
    </div>
    
    <p class="text-center text-slate-500 text-xs mt-4">
        AIntento v2.0.0 — Тестова сторінка для розробки
    </p>
</div>

<script>
    const chatForm = document.getElementById('chatForm');
    const chatInput = document.getElementById('chatInput');
    const chatMessages = document.getElementById('chatMessages');
    const clearChatBtn = document.getElementById('clearChat');

    const SESSION_KEY = 'aintento_session_id';
    const MESSAGES_KEY_PREFIX = 'aintento_test_messages_';

    // ============ SESSION MANAGEMENT ============
    function getOrCreateSessionId() {
        // Also check old key for migration
        let sid = localStorage.getItem(SESSION_KEY) || localStorage.getItem('ailure_chat_session_id');

        if (!sid || typeof sid !== 'string' || sid.trim() === '') {
            sid = (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + '-' + Math.random().toString(16).slice(2));
        }
        
        localStorage.setItem(SESSION_KEY, sid);
        return sid;
    }

    function setSessionId(sid) {
        if (sid && typeof sid === 'string' && sid.trim() !== '') {
            localStorage.setItem(SESSION_KEY, sid.trim());
        }
    }

    // ============ MESSAGE PERSISTENCE ============
    function getMessagesKey() {
        return MESSAGES_KEY_PREFIX + getOrCreateSessionId();
    }

    function saveMessages(messages) {
        try {
            localStorage.setItem(getMessagesKey(), JSON.stringify(messages));
        } catch (e) {
            console.error('Failed to save messages:', e);
        }
    }

    function loadMessages() {
        try {
            const key = getMessagesKey();
            const oldKey = 'ailure_test_messages_' + getOrCreateSessionId();
            const data = localStorage.getItem(key) || localStorage.getItem(oldKey);
            return data ? JSON.parse(data) : [];
        } catch (e) {
            console.error('Failed to load messages:', e);
            return [];
        }
    }

    function clearMessages() {
        localStorage.removeItem(getMessagesKey());
        localStorage.removeItem(SESSION_KEY);
        chatMessages.innerHTML = '';
        // Create new session and show welcome
        getOrCreateSessionId();
        showWelcome();
    }

    // Track all messages for persistence
    let messageHistory = [];

    // ============ UI FUNCTIONS ============
    function appendMessage(text, side = 'user', save = true) {
        const wrapper = document.createElement('div');
        wrapper.className = 'flex message-appear ' + (side === 'user' ? 'justify-end' : 'justify-start');

        const bubble = document.createElement('div');
        bubble.className =
            'max-w-[80%] rounded-2xl px-4 py-3 text-sm shadow-sm ' +
            (side === 'user'
                ? 'bg-gradient-to-r from-slate-800 to-slate-900 text-white'
                : 'bg-white border border-slate-200 text-slate-800');

        bubble.innerHTML = text.replace(/\n/g, '<br>');
        wrapper.appendChild(bubble);
        chatMessages.appendChild(wrapper);
        chatMessages.scrollTop = chatMessages.scrollHeight;

        if (save) {
            messageHistory.push({ type: 'text', side, text });
            saveMessages(messageHistory);
        }
    }

    function appendBotHtml(html, save = true, products = null) {
        const wrapper = document.createElement('div');
        wrapper.className = 'flex justify-start message-appear';

        const bubble = document.createElement('div');
        bubble.className = 'max-w-[90%] rounded-2xl px-4 py-3 bg-white border border-slate-200 text-sm shadow-sm';

        bubble.innerHTML = html;
        wrapper.appendChild(bubble);
        chatMessages.appendChild(wrapper);
        chatMessages.scrollTop = chatMessages.scrollHeight;

        if (save && products) {
            messageHistory.push({ type: 'products', products });
            saveMessages(messageHistory);
        }
    }

    function showTypingIndicator() {
        const existing = document.getElementById('typing-indicator');
        if (existing) return;
        
        const wrapper = document.createElement('div');
        wrapper.id = 'typing-indicator';
        wrapper.className = 'flex justify-start';
        wrapper.innerHTML = `
            <div class="bg-white border border-slate-200 rounded-2xl px-4 py-3 shadow-sm">
                <span style="display: inline-block; animation: typingDot 1.4s infinite; color: #64748b;">●</span>
                <span style="display: inline-block; animation: typingDot 1.4s 0.2s infinite; color: #64748b;">●</span>
                <span style="display: inline-block; animation: typingDot 1.4s 0.4s infinite; color: #64748b;">●</span>
            </div>
        `;
        chatMessages.appendChild(wrapper);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function removeTypingIndicator() {
        const indicator = document.getElementById('typing-indicator');
        if (indicator) indicator.remove();
    }

    function renderProducts(products, introText) {
        let html = '<p class="text-sm text-slate-700 mb-3">' + (introText || 'Ось що я знайшов:') + '</p>';
        html += '<div class="space-y-2">';

        products.forEach((p) => {
            const title = p.title ?? (p.title_json && (p.title_json.ua || p.title_json.ru)) ?? 'Без назви';
            const price = p.price ? (Math.round(p.price) + ' ₴') : '';
            const link = p.link || '#';
            const image = (p.images && p.images.length) ? p.images[0] : '';

            html += `
                <a href="${link}" target="_blank"
                   class="flex items-start gap-3 bg-slate-50 rounded-xl p-3 transition-all duration-200 border border-slate-200 hover:shadow-md hover:-translate-y-0.5 hover:border-cyan-500">
                    <div class="w-16 h-16 flex-shrink-0 bg-white rounded-lg overflow-hidden border border-slate-200">
                        ${image 
                            ? `<img src="${image}" alt="${title}" class="w-full h-full object-cover" onerror="this.style.display='none'">`
                            : '<div class="w-full h-full flex items-center justify-center text-slate-400 text-2xl">📦</div>'
                        }
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium text-slate-900 line-clamp-2">${title}</div>
                        <div class="text-base font-bold text-cyan-600 mt-1">${price}</div>
                    </div>
                </a>
            `;
        });

        html += '</div>';
        return html;
    }

    function renderBotReply(payload) {
        if (!payload) {
            appendMessage('Порожня відповідь від сервера 🥲', 'bot');
            return;
        }

        if (payload.session_id) {
            setSessionId(payload.session_id);
        }

        // Product cards response (with descriptions) - NEW FORMAT
        if (payload.type === 'products' && payload.data?.product_cards?.length) {
            // Show intro text first
            if (payload.text) {
                appendMessage(payload.text, 'bot');
            }
            // Render each card with description
            renderProductCards(payload.data.product_cards);
            return;
        }

        // Products response (legacy format without descriptions)
        if (payload.type === 'products' && payload.data?.products?.length) {
            const products = payload.data.products.slice(0, 10);
            const html = renderProducts(products, payload.text);
            appendBotHtml(html, true, products);
            return;
        }

        // Text response
        if (payload.type === 'text' && payload.text) {
            appendMessage(payload.text, 'bot');
            return;
        }

        // Fallback
        if (payload.text) {
            appendMessage(payload.text, 'bot');
            return;
        }

        appendMessage('Отримав відповідь, але не знаю як її показати 🤔', 'bot');
    }

    function renderProductCards(productCards) {
        productCards.slice(0, 5).forEach((card) => {
            const product = card.product;
            const description = card.description;

            // Show description bubble (if exists)
            if (description && description.trim()) {
                const descWrapper = document.createElement('div');
                descWrapper.className = 'flex justify-start message-appear mb-2';
                descWrapper.innerHTML = `
                    <div class="bg-slate-100 text-slate-600 rounded-2xl px-4 py-2 text-sm">
                        ${description}
                    </div>
                `;
                chatMessages.appendChild(descWrapper);
            }

            // Show product card
            const cardHtml = renderSingleProduct(product);
            appendBotHtml(cardHtml, false);
        });

        // Save to history
        messageHistory.push({ type: 'product_cards', cards: productCards.slice(0, 5) });
        saveMessages(messageHistory);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function renderSingleProduct(p) {
        const title = p.title ?? (p.title_json && (p.title_json.ua || p.title_json.ru)) ?? 'Без назви';
        const price = p.price ? (Math.round(p.price) + ' ₴') : '';
        const link = p.link || '#';
        const image = (p.images && p.images.length) ? p.images[0] : '';

        return `
            <a href="${link}" target="_blank"
               class="flex items-start gap-3 bg-slate-50 rounded-xl p-3 transition-all duration-200 border border-slate-200 hover:shadow-md hover:-translate-y-0.5 hover:border-cyan-500">
                <div class="w-16 h-16 flex-shrink-0 bg-white rounded-lg overflow-hidden border border-slate-200">
                    ${image 
                        ? `<img src="${image}" alt="${title}" class="w-full h-full object-cover" onerror="this.style.display='none'">`
                        : '<div class="w-full h-full flex items-center justify-center text-slate-400 text-2xl">📦</div>'
                    }
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium text-slate-900 line-clamp-2">${title}</div>
                    <div class="text-base font-bold text-cyan-600 mt-1">${price}</div>
                </div>
            </a>
        `;
    }

    // ============ RESTORE HISTORY ============
    function restoreHistory() {
        const saved = loadMessages();
        if (saved.length === 0) {
            showWelcome();
            return;
        }

        saved.forEach(msg => {
            if (msg.type === 'text') {
                appendMessage(msg.text, msg.side, false);
            } else if (msg.type === 'product_cards' && msg.cards) {
                // Restore product cards with descriptions
                msg.cards.forEach((card) => {
                    if (card.description && card.description.trim()) {
                        const descWrapper = document.createElement('div');
                        descWrapper.className = 'flex justify-start message-appear mb-2';
                        descWrapper.innerHTML = `
                            <div class="bg-slate-100 text-slate-600 rounded-2xl px-4 py-2 text-sm">
                                ${card.description}
                            </div>
                        `;
                        chatMessages.appendChild(descWrapper);
                    }
                    const cardHtml = renderSingleProduct(card.product);
                    appendBotHtml(cardHtml, false);
                });
            } else if (msg.type === 'products' && msg.products) {
                const html = renderProducts(msg.products, 'Раніше знайдені товари:');
                appendBotHtml(html, false);
            }
        });

        messageHistory = saved;
    }

    function showWelcome() {
        const welcomeText = `Вітаю! 👋 Я AIntento — ваш персональний помічник з підбору тактичного спорядження.

Можу дізнатись статус вашого замовлення, розповім все про магазин та допоможу підібрати спорядження.`;
        
        appendMessage(welcomeText, 'bot', true);
    }

    // ============ EVENT HANDLERS ============
    chatForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        const text = chatInput.value.trim();
        if (!text) return;

        appendMessage(text, 'user');
        chatInput.value = '';
        chatInput.disabled = true;

        const sessionId = getOrCreateSessionId();
        showTypingIndicator();

        try {
            const response = await fetch('/api/chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    message: text,
                    session_id: sessionId,
                }),
            });

            removeTypingIndicator();

            if (!response.ok) {
                appendMessage('Помилка сервера: ' + response.status, 'bot');
                return;
            }

            const payload = await response.json();
            renderBotReply(payload);
        } catch (err) {
            console.error(err);
            removeTypingIndicator();
            appendMessage('Помилка з\'єднання з сервером 😔', 'bot');
        } finally {
            chatInput.disabled = false;
            chatInput.focus();
        }
    });

    clearChatBtn.addEventListener('click', () => {
        if (confirm('Очистити історію чату? Буде створено нову сесію.')) {
            clearMessages();
        }
    });

    // ============ INIT ============
    document.addEventListener('DOMContentLoaded', () => {
        restoreHistory();
        chatInput.focus();
    });
</script>

</body>
</html>

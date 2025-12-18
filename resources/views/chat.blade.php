<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Ailure Асістент</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-100 flex items-center justify-center p-4">

<div class="w-full max-w-md">
    <div class="relative bg-white rounded-lg shadow-xl overflow-hidden mx-auto">
        {{-- Хедер чату --}}
        <div class="bg-gray-900 px-4 py-3 flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-white flex items-center justify-center">
                <svg class="w-6 h-6 text-gray-900" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/>
                </svg>
            </div>
            <div class="flex-1">
                <div class="text-white font-medium text-sm">Ailure Асістент</div>
                <div class="text-gray-400 text-xs">Завжди відповідаємо за хвилину</div>
            </div>
        </div>

        {{-- Область повідомлень --}}
        <div id="chatMessages" class="p-4 space-y-3 bg-gray-50 h-[500px] overflow-y-auto">
            {{-- Початкове вітання додається через JS --}}
        </div>

        {{-- Інпут повідомлення --}}
        <div class="p-4 border-t bg-white">
            <form id="chatForm" class="flex items-center gap-2">
                @csrf
                <input
                    id="chatInput"
                    type="text"
                    placeholder="Напишіть, що шукаєте..."
                    class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-sm outline-none focus:border-gray-900 focus:ring-1 focus:ring-gray-900"
                    autocomplete="off"
                >
                <button
                    type="submit"
                    class="w-10 h-10 rounded-full bg-gray-900 flex items-center justify-center text-white hover:bg-gray-800 transition-colors"
                    aria-label="Надіслати">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                    </svg>
                </button>
            </form>
        </div>
    </div>
</div>

<script>
    const chatForm = document.getElementById('chatForm');
    const chatInput = document.getElementById('chatInput');
    const chatMessages = document.getElementById('chatMessages');

    const SESSION_KEY = 'ailure_chat_session_id';

    function getOrCreateSessionId() {
        let sid = localStorage.getItem(SESSION_KEY);

        if (!sid || typeof sid !== 'string' || sid.trim() === '') {
            // UUID v4 (працює в сучасних браузерах)
            sid = (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + '-' + Math.random().toString(16).slice(2));
            localStorage.setItem(SESSION_KEY, sid);
        }

        return sid;
    }

    function setSessionId(sid) {
        if (sid && typeof sid === 'string' && sid.trim() !== '') {
            localStorage.setItem(SESSION_KEY, sid.trim());
        }
    }

    // Додаємо звичайне текстове повідомлення
    function appendMessage(text, side = 'user') {
        const wrapper = document.createElement('div');
        wrapper.className = 'flex ' + (side === 'user' ? 'justify-end' : 'justify-start');

        const bubble = document.createElement('div');
        bubble.className =
            'max-w-[85%] rounded-2xl px-4 py-2.5 text-sm ' +
            (side === 'user'
                ? 'bg-accent text-primary rounded-br-md'
                : 'bg-card border rounded-bl-md');

        bubble.innerText = text;
        wrapper.appendChild(bubble);
        chatMessages.appendChild(wrapper);

        chatMessages.scrollTop = chatMessages.scrollHeight;
    }0%] rounded-lg px-4 py-2.5 text-sm ' +
            (side === 'user'
                ? 'bg-gray-900 text-white'
                : 'bg-white border border-gray-200 text-gray-900');

        bubble.innerHTML = text.replace(/\n/g, '<br>');
        wrapper.appendChild(bubble);
        chatMessages.appendChild(wrapper);

        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    // Додаємо бот-відповідь з HTML (для списку товарів)
    function appendBotHtml(html) {
        const wrapper = document.createElement('div');
        wrapper.className = 'flex justify-start';

        const bubble = document.createElement('div');
        bubble.className = 'max-w-[90%] rounded-lg px-4 py-3 bg-white border border-gray-200
        if (!payload) {
            appendMessage('Порожня відповідь від сервера 🥲', 'bot');
            return;
        }

        // підхоплюємо session_id з відповіді (якщо бек згенерив)
        if (payload.session_id) {
            setSessionId(payload.session_id);
        }

        // ВАЖЛИВО: у тебе формат відповіді = { type, text, data }
        // а товари лежать в payload.data.products, НЕ payload.products
        if (payload.type === 'products'
            && payload.data
            && Array.isArray(payload.data.products)
            && payload.data.products.length
        ) {
            const products = payload.data.products.slice(0, 10);

            let html = '<p class="text-sm text-gray-700 mb-3">' + (payload.text || 'Ось варіанти:') + '</p>';
            html += '<div class="space-y-2">';

            products.forEach((p) => {
                const title =
                    p.title
                    ?? (p.title_json && (p.title_json.ua || p.title_json.ru))
                    ?? 'Без назви';

                const price = p.price ? (Math.round(p.price) + ' ₴') : '';
                const link  = p.link || '#';
                const image = (p.images && p.images.length) ? p.images[0] : '';

                html += `
                    <a href="${link}" target="_blank"
                       class="flex items-start gap-3 bg-gray-50 rounded-lg p-3 hover:bg-gray-100 transition border border-gray-200">
                        <div class="w-16 h-16 flex-shrink-0 bg-white rounded overflow-hidden border border-gray-200">
                            ${image 
                                ? `<img src="${image}" alt="${title}" class="w-full h-full object-cover">`
                                : '<div class="w-full h-full flex items-center justify-center text-gray-400 text-2xl">📦</div>'
                            }
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-gray-900 line-clamp-2">${title}</div>
                            <div class="text-base font-semibold text-gray-900 mt-1">${price}</div>
                        </div>
                    </a>
                `;
            });

            html += '</div>';
            appendBotHtml(html);
            return;
        }

        // текстова відповідь
        if (payload.type === 'text' && payload.text) {
            appendMessage(payload.text, 'bot');
            return;
        }

        // фолбек
        appendMessage('Я отримав відповідь, але не знаю, як її показати 🤔', 'bot');
    }

    chatForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        const text = chatInput.value.trim();
        if (!text) return;

        appendMessage(text, 'user');
        chatInput.value = '';

        const sessionId = getOrCreateSessionId();

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

            if (!response.ok) {
                appendMessage('Помилка сервера: ' + response.status, 'bot');
                return;
            }

            const payload = await response.json();
            renderBotReply(payload);
        } catch (err) {
            console.error(err);
            appendMessage('Помилка з’єднання з сервером 😔', 'bot');
        const welcomeText = `Вітаю! 👋 Я Ailure Асістент.

Напиши, що саме шукаєш і для чого (для себе, авто, укриття) — тоді зможу підібрати відповідні варіанти або підказати, на що звернути увагу при виборі.

Приклади запитів:

1) Потрібна плитоноска для патрулювання
2) Чи ти маєш на увазі **бронепластини** для захисту техніки, авто, укриття?
3) Є айсік, виміряй клас захисту (типу 4-й, 5-й), вага, розмір, бюджет?`;
        
        appendMessage(welcomeText, 'bot'очаткове привітання від бота
    window.addEventListener('DOMContentLoaded', () => {
        getOrCreateSessionId();
        appendMessage(
            'Вітаю! 👋 Я AILure Асистент. Напишіть, що шукаєте (наприклад: "зимова куртка до 5000 грн").',
            'bot'
        );
    });
</script>

</body>
</html>

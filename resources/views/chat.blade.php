<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>AILure Асистент</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Tailwind через CDN для швидкого старту --}}
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        :root {
            --color-primary: #0f172a;
            --color-primary-foreground: #f9fafb;
            --color-card: #ffffff;
            --color-secondary: #e5e7eb;
            --color-accent: #a5b4fc;
        }

        .bg-primary {
            background-color: var(--color-primary);
        }

        .text-primary-foreground {
            color: var(--color-primary-foreground);
        }

        .bg-card {
            background-color: var(--color-card);
        }

        .bg-secondary {
            background-color: var(--color-secondary);
        }

        .bg-accent {
            background-color: var(--color-accent);
        }

        .text-primary {
            color: var(--color-primary);
        }

        .text-accent {
            color: var(--color-accent);
        }
    </style>
</head>
<body class="min-h-screen bg-slate-100 flex items-center justify-center p-4">

<div class="w-full max-w-md">
    <div class="relative bg-card rounded-2xl shadow-2xl border overflow-hidden mx-auto">
        {{-- Хедер чату --}}
        <div class="bg-primary px-4 py-3 flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-accent flex items-center justify-center">
                <span class="text-primary font-bold">A</span>
            </div>
            <div>
                <div class="text-primary-foreground font-semibold text-sm">
                    AILure Асистент
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="w-2 h-2 bg-green-400 rounded-full"></span>
                    <span class="text-primary-foreground/70 text-xs">Онлайн</span>
                </div>
            </div>
        </div>

        {{-- Область повідомлень --}}
        <div id="chatMessages"
             class="p-4 space-y-4 bg-secondary/30 h-96 overflow-y-auto">

            {{-- Приклад перших фіксованих повідомлень (можеш потім прибрати) --}}
            <div class="flex justify-end">
                <div class="max-w-[85%] rounded-2xl px-4 py-2.5 bg-accent text-primary rounded-br-md">
                    <p class="text-sm">
                        Привіт! Шукаю куртку для зими, бюджет до 5000 грн
                    </p>
                </div>
            </div>

            <div class="flex justify-start">
                <div class="max-w-[85%] rounded-2xl px-4 py-2.5 bg-card border rounded-bl-md">
                    <p class="text-sm">
                        Вітаю! 👋 Допоможу знайти ідеальну зимову куртку. Скажіть, який стиль вам більше до вподоби — класичний чи спортивний?
                    </p>
                </div>
            </div>

            <div class="flex justify-end">
                <div class="max-w-[85%] rounded-2xl px-4 py-2.5 bg-accent text-primary rounded-br-md">
                    <p class="text-sm">
                        Спортивний, для активного відпочинку
                    </p>
                </div>
            </div>

            <div class="flex justify-start">
                <div class="max-w-[85%] rounded-2xl px-4 py-2.5 bg-card border rounded-bl-md">
                    <p class="text-sm">
                        Чудовий вибір! Ось 3 найпопулярніші моделі у вашому бюджеті:
                    </p>

                    <div class="mt-3 space-y-2">
                        <div class="flex items-center gap-3 bg-secondary/50 rounded-lg p-2">
                            <span class="text-2xl">🧥</span>
                            <div class="flex-1">
                                <div class="text-xs font-medium">Куртка Alpine Pro</div>
                                <div class="text-xs text-accent font-semibold">4 299 ₴</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 bg-secondary/50 rounded-lg p-2">
                            <span class="text-2xl">🧥</span>
                            <div class="flex-1">
                                <div class="text-xs font-medium">Пуховик North Wind</div>
                                <div class="text-xs text-accent font-semibold">4 850 ₴</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        {{-- Інпут повідомлення --}}
        <div class="p-3 border-t bg-card">
            <form id="chatForm" class="flex items-center gap-2 bg-secondary rounded-xl px-4 py-2.5">
                @csrf
                <input
                    id="chatInput"
                    type="text"
                    placeholder="Напишіть повідомлення..."
                    class="flex-1 bg-transparent text-sm outline-none text-slate-900 placeholder:text-slate-500">

                <button
                    type="submit"
                    class="w-8 h-8 rounded-lg bg-accent flex items-center justify-center text-primary hover:bg-accent/90 transition-colors"
                    aria-label="Надіслати">
                    <svg xmlns="http://www.w3.org/2000/svg"
                         width="24" height="24" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round"
                         class="w-4 h-4">
                        <path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"></path>
                        <path d="m21.854 2.147-10.94 10.939"></path>
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

        // автоскрол донизу
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    chatForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        const text = chatInput.value.trim();
        if (!text) return;

        appendMessage(text, 'user');
        chatInput.value = '';

        // Поки що просто мок-відповідь, щоб протестити UI.
        // Потім тут можна підключити твій реальний /api/chat ендпоінт.
        setTimeout(() => {
            appendMessage('Я поки відповідаю як мок. Потім тут буде реальна логіка з бекенду 👌', 'bot');
        }, 500);

        /*
        // Коли будеш готовий підключити реальний бекенд:
        try {
            const response = await fetch('/api/chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ message: text }),
            });

            const data = await response.json();
            appendMessage(data.reply ?? 'Щось пішло не так, спробуйте ще раз 🥲', 'bot');
        } catch (e) {
            appendMessage('Помилка з’єднання з сервером 😔', 'bot');
        }
        */
    });
</script>

</body>
</html>

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Довідка та встановлення
        </h2>
    </x-slot>

    <div class="py-10">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Install guide --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="text-xl font-bold text-gray-900 mb-4">Як встановити чат-віджет</h3>

                <ol class="list-decimal list-inside space-y-3 text-gray-700">
                    <li>
                        Переконайтесь, що пройшли онбординг та магазин підключено -
                        <a href="{{ route('dashboard') }}" class="text-blue-600 underline">перевірити статус</a>.
                    </li>
                    <li>
                        Відкрийте
                        <a href="{{ route('dashboard') }}" class="text-blue-600 underline">Панель керування</a> &rarr; вкладка "Налаштування"
                        і скопіюйте код вставки.
                    </li>
                    <li>
                        У адмінці вашого магазину (Horoshop, OpenCart, інший двигун) знайдіть блок
                        "Користувацький HTML" або редагування шаблону <code>footer</code>.
                    </li>
                    <li>Вставте скопійований код перед закривним тегом <code>&lt;/body&gt;</code>.</li>
                    <li>Збережіть і оновіть сторінку магазину. Віджет має з'явитись у правому нижньому куті.</li>
                </ol>

                <div class="mt-4 p-4 bg-gray-50 border border-gray-200 rounded">
                    <p class="text-sm text-gray-600 mb-2">Приклад коду вставки:</p>
<pre class="text-xs bg-gray-900 text-gray-100 p-3 rounded overflow-x-auto"><code>&lt;script
    src="{{ config('app.url') }}/widget.js"
    data-token="ВАШ_ТОКЕН"
    async
&gt;&lt;/script&gt;</code></pre>
                    <p class="text-xs text-gray-500 mt-2">Токен для вашого магазину показаний у розділі налаштувань віджета.</p>
                </div>
            </div>

            {{-- FAQ --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="text-xl font-bold text-gray-900 mb-4">Часті питання</h3>

                <div class="divide-y divide-gray-200">
                    <details class="py-3 group">
                        <summary class="cursor-pointer font-semibold text-gray-800 flex justify-between items-center">
                            <span>Віджет не з'являється на сайті</span>
                            <span class="text-gray-400 group-open:rotate-180 transition-transform">▾</span>
                        </summary>
                        <div class="mt-2 text-gray-700 text-sm space-y-2">
                            <p>1. Перевірте, що скрипт вставлено перед <code>&lt;/body&gt;</code>, а не в <code>&lt;head&gt;</code>.</p>
                            <p>2. Відкрийте DevTools (F12) &rarr; вкладка Console. Якщо є помилка CORS - напишіть у підтримку.</p>
                            <p>3. Перевірте що токен не змінювався після копіювання.</p>
                        </div>
                    </details>

                    <details class="py-3 group">
                        <summary class="cursor-pointer font-semibold text-gray-800 flex justify-between items-center">
                            <span>Товари не знаходяться</span>
                            <span class="text-gray-400 group-open:rotate-180 transition-transform">▾</span>
                        </summary>
                        <div class="mt-2 text-gray-700 text-sm space-y-2">
                            <p>Перевірте статус синхронізації на дашборді. Якщо "В процесі" - зачекайте, AI-аналіз товарів займає час (~1 година на 500 товарів).</p>
                            <p>Якщо статус "Завершено", а товари не знаходяться - натисніть "Перезапустити індексацію" в налаштуваннях.</p>
                        </div>
                    </details>

                    <details class="py-3 group">
                        <summary class="cursor-pointer font-semibold text-gray-800 flex justify-between items-center">
                            <span>Онбординг зупинився на кроці AI-аналізу</span>
                            <span class="text-gray-400 group-open:rotate-180 transition-transform">▾</span>
                        </summary>
                        <div class="mt-2 text-gray-700 text-sm space-y-2">
                            <p>Це нормально для великих каталогів. AI обробляє товари порціями (~100/хв). Якщо прогрес не рухається більше 30 хв - натисніть "Спробувати знову" в повідомленні про помилку.</p>
                        </div>
                    </details>

                    <details class="py-3 group">
                        <summary class="cursor-pointer font-semibold text-gray-800 flex justify-between items-center">
                            <span>Як змінити тон відповідей AI</span>
                            <span class="text-gray-400 group-open:rotate-180 transition-transform">▾</span>
                        </summary>
                        <div class="mt-2 text-gray-700 text-sm space-y-2">
                            <p>Перейдіть у <a href="{{ route('dashboard') }}" class="text-blue-600 underline">Панель керування</a> &rarr; вкладка "Налаштування" &rarr; "Тон спілкування". Доступні варіанти: офіційний, дружній, спартанський.</p>
                        </div>
                    </details>

                    <details class="py-3 group">
                        <summary class="cursor-pointer font-semibold text-gray-800 flex justify-between items-center">
                            <span>Що таке ліміт повідомлень і як його збільшити</span>
                            <span class="text-gray-400 group-open:rotate-180 transition-transform">▾</span>
                        </summary>
                        <div class="mt-2 text-gray-700 text-sm space-y-2">
                            <p>Ліміт - це кількість відповідей AI, які ваш магазин може отримати за місяць. На пробному періоді це 2000 повідомлень.</p>
                            <p>Щоб збільшити - оберіть тарифний план на сторінці <a href="{{ route('billing.index') }}" class="text-blue-600 underline">Підписка</a>.</p>
                        </div>
                    </details>

                    <details class="py-3 group">
                        <summary class="cursor-pointer font-semibold text-gray-800 flex justify-between items-center">
                            <span>Де подивитись статистику чатів</span>
                            <span class="text-gray-400 group-open:rotate-180 transition-transform">▾</span>
                        </summary>
                        <div class="mt-2 text-gray-700 text-sm space-y-2">
                            <p>Відкрийте <a href="{{ route('dashboard') }}" class="text-blue-600 underline">Панель керування</a> - там є вкладки: Огляд, Чати, Конверсії.</p>
                        </div>
                    </details>

                    <details class="py-3 group">
                        <summary class="cursor-pointer font-semibold text-gray-800 flex justify-between items-center">
                            <span>Як додати свої відповіді на часті питання</span>
                            <span class="text-gray-400 group-open:rotate-180 transition-transform">▾</span>
                        </summary>
                        <div class="mt-2 text-gray-700 text-sm space-y-2">
                            <p>Напишіть нам - ми імпортуємо ваші шаблони відповідей у базу знань магазину. AI автоматично використовуватиме їх для відповідей про доставку, оплату, повернення.</p>
                        </div>
                    </details>
                </div>
            </div>

            {{-- Support contact --}}
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 text-center">
                <h4 class="font-semibold text-blue-900">Не знайшли відповідь?</h4>
                <p class="text-sm text-blue-800 mt-1">
                    Напишіть нам на <a href="mailto:support@aintento.com" class="underline">support@aintento.com</a>
                    або у Telegram - відповідаємо протягом кількох годин.
                </p>
            </div>
        </div>
    </div>
</x-app-layout>

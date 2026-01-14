<div x-data="{ showToast: false, toastMessage: '' }" 
     x-on:notify.window="showToast = true; toastMessage = $event.detail; setTimeout(() => showToast = false, 3000)">
    
    <!-- Toast Notification -->
    <div x-show="showToast" 
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-2"
         class="fixed bottom-4 right-4 z-50 px-6 py-3 bg-green-600 text-white rounded-lg shadow-lg flex items-center gap-2">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
        </svg>
        <span x-text="toastMessage"></span>
    </div>

    <!-- Navigation -->
    <div class="mb-4 flex gap-2">
        <a href="{{ route('admin.dashboard') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition-colors">Dashboard</a>
        <a href="{{ route('admin.analytics') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition-colors">📊 Аналітика</a>
        <a href="{{ route('admin.conversions') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition-colors">🛒 Конверсії</a>
        <a href="{{ route('admin.chats.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition-colors">💬 Чати</a>
        <a href="{{ route('admin.widget.settings') }}" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm shadow-sm">⚙️ Віджет</a>
    </div>

    <!-- Header -->
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900">⚙️ Налаштування віджета</h2>
        <p class="mt-1 text-sm text-gray-500">Персоналізуйте зовнішній вигляд та поведінку чат-віджета</p>
    </div>

    @if (session()->has('message'))
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => { show = false; $dispatch('notify', '{{ session('message') }}') }, 100)"
         class="mb-4 p-4 bg-green-50 border border-green-200 text-green-800 rounded-lg flex items-center gap-2">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
        </svg>
        {{ session('message') }}
    </div>
    @endif

    <div class="grid grid-cols-2 gap-6">
        <!-- Settings Form -->
        <div class="space-y-6">
            <form wire:submit="save">
                <!-- Appearance -->
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">🎨 Зовнішній вигляд</h3>
                    
                    <div class="space-y-4">
                        <!-- Color Presets -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Готові схеми кольорів</label>
                            <div class="flex gap-2 flex-wrap">
                                <button type="button" wire:click="$set('primary_color', '#2563eb'); $set('text_color', '#ffffff')" 
                                        class="w-10 h-10 rounded-lg bg-blue-600 border-2 {{ $primary_color === '#2563eb' ? 'border-gray-900 ring-2 ring-offset-2 ring-blue-600' : 'border-transparent' }} hover:scale-110 transition-transform" title="Синій"></button>
                                <button type="button" wire:click="$set('primary_color', '#0f0f0f'); $set('text_color', '#f78c5e')" 
                                        class="w-10 h-10 rounded-lg bg-gray-900 border-2 {{ $primary_color === '#0f0f0f' ? 'border-gray-900 ring-2 ring-offset-2 ring-gray-900' : 'border-transparent' }} hover:scale-110 transition-transform" title="Contractor"></button>
                                <button type="button" wire:click="$set('primary_color', '#059669'); $set('text_color', '#ffffff')" 
                                        class="w-10 h-10 rounded-lg bg-emerald-600 border-2 {{ $primary_color === '#059669' ? 'border-gray-900 ring-2 ring-offset-2 ring-emerald-600' : 'border-transparent' }} hover:scale-110 transition-transform" title="Зелений"></button>
                                <button type="button" wire:click="$set('primary_color', '#dc2626'); $set('text_color', '#ffffff')" 
                                        class="w-10 h-10 rounded-lg bg-red-600 border-2 {{ $primary_color === '#dc2626' ? 'border-gray-900 ring-2 ring-offset-2 ring-red-600' : 'border-transparent' }} hover:scale-110 transition-transform" title="Червоний"></button>
                                <button type="button" wire:click="$set('primary_color', '#7c3aed'); $set('text_color', '#ffffff')" 
                                        class="w-10 h-10 rounded-lg bg-violet-600 border-2 {{ $primary_color === '#7c3aed' ? 'border-gray-900 ring-2 ring-offset-2 ring-violet-600' : 'border-transparent' }} hover:scale-110 transition-transform" title="Фіолетовий"></button>
                                <button type="button" wire:click="$set('primary_color', '#ea580c'); $set('text_color', '#ffffff')" 
                                        class="w-10 h-10 rounded-lg bg-orange-600 border-2 {{ $primary_color === '#ea580c' ? 'border-gray-900 ring-2 ring-offset-2 ring-orange-600' : 'border-transparent' }} hover:scale-110 transition-transform" title="Помаранчевий"></button>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Основний колір</label>
                                <div class="flex gap-2">
                                    <input type="color" wire:model.live="primary_color" class="h-10 w-16 rounded border-gray-300 cursor-pointer">
                                    <input type="text" wire:model.live="primary_color" class="flex-1 px-3 py-2 border border-gray-300 rounded-lg font-mono text-sm" placeholder="#2563eb">
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Колір тексту</label>
                                <div class="flex gap-2">
                                    <input type="color" wire:model.live="text_color" class="h-10 w-16 rounded border-gray-300 cursor-pointer">
                                    <input type="text" wire:model.live="text_color" class="flex-1 px-3 py-2 border border-gray-300 rounded-lg font-mono text-sm" placeholder="#ffffff">
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Позиція</label>
                                <select wire:model.live="position" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    <option value="left">← Зліва</option>
                                    <option value="right">Справа →</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Початковий стан</label>
                                <select wire:model.live="start_state" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    <option value="closed">🔒 Закритий</option>
                                    <option value="open">🔓 Відкритий</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Радіус закруглення: {{ $border_radius }}px</label>
                            <input type="range" wire:model.live="border_radius" min="0" max="24" class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-blue-600">
                            <div class="flex justify-between text-xs text-gray-500 mt-1">
                                <span>Квадратний</span>
                                <span>Заокруглений</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Branding -->
                <div class="bg-white rounded-lg shadow-sm p-6 mt-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">🤖 Брендинг бота</h3>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Ім'я бота</label>
                            <input type="text" wire:model.live="bot_name" class="w-full px-3 py-2 border border-gray-300 rounded-lg" placeholder="AIntento" maxlength="100">
                            <p class="mt-1 text-xs text-gray-500">Відображається в заголовку віджету</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Аватар бота</label>
                            <div class="flex items-start gap-4">
                                <!-- Current Avatar Preview -->
                                <div class="flex-shrink-0">
                                    @if($bot_avatar_url)
                                        <div class="relative">
                                            <img src="{{ $bot_avatar_url }}" alt="Avatar" class="w-20 h-20 rounded-full object-cover border-2 border-gray-200">
                                            <button type="button" wire:click="removeAvatar" class="absolute -top-2 -right-2 w-6 h-6 bg-red-500 text-white rounded-full text-xs hover:bg-red-600 flex items-center justify-center">✕</button>
                                        </div>
                                    @else
                                        <div class="w-20 h-20 rounded-full bg-gray-100 border-2 border-dashed border-gray-300 flex items-center justify-center">
                                            <span class="text-2xl">🤖</span>
                                        </div>
                                    @endif
                                </div>
                                
                                <!-- Upload Options -->
                                <div class="flex-1 space-y-2">
                                    <div>
                                        <label class="block">
                                            <span class="sr-only">Завантажити аватар</span>
                                            <input type="file" wire:model="bot_avatar_upload" accept="image/*" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer">
                                        </label>
                                        <div wire:loading wire:target="bot_avatar_upload" class="text-sm text-blue-600 mt-1">
                                            ⏳ Завантаження...
                                        </div>
                                        @error('bot_avatar_upload') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="text-xs text-gray-500">або вставте URL:</div>
                                    <input type="url" wire:model.live="bot_avatar_url" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="https://example.com/avatar.png" maxlength="500">
                                </div>
                            </div>
                            <p class="mt-2 text-xs text-gray-500">Рекомендований розмір: 80×80px. Макс 1MB.</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Статус бота</label>
                            <input type="text" wire:model.live="bot_status_text" class="w-full px-3 py-2 border border-gray-300 rounded-lg" placeholder="Завжди онлайн" maxlength="100">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Шрифт</label>
                            <select wire:model.live="font_family" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="">Системний (за замовчуванням)</option>
                                <option value="'Inter', sans-serif">Inter</option>
                                <option value="'Roboto', sans-serif">Roboto</option>
                                <option value="'Open Sans', sans-serif">Open Sans</option>
                                <option value="'Montserrat', sans-serif">Montserrat</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Тон відповідей</label>
                            <select wire:model.live="tone" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="official">Офіційний — ввічливий, формальний</option>
                                <option value="spartan">Лаконічний — коротко, по суті</option>
                                <option value="friendly">Дружній — неформальний, позитивний</option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">Впливає на стиль відповідей AI</p>
                        </div>

                        <div class="flex items-center">
                            <input type="checkbox" wire:model.live="show_shadow" id="show_shadow" class="rounded border-gray-300">
                            <label for="show_shadow" class="ml-2 text-sm text-gray-700">Показувати тінь віджету</label>
                        </div>
                    </div>
                </div>

                <!-- Content -->
                <div class="bg-white rounded-lg shadow-sm p-6 mt-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Тексти</h3>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Вітальне повідомлення</label>
                            <textarea wire:model.live="welcome_message" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg" maxlength="500"></textarea>
                            <p class="mt-1 text-xs text-gray-500">{{ mb_strlen($welcome_message ?? '') }}/500</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Placeholder інпуту</label>
                            <input type="text" wire:model.live="input_placeholder" class="w-full px-3 py-2 border border-gray-300 rounded-lg" maxlength="200">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Повідомлення про згоду (опціонально)</label>
                            <textarea wire:model.live="consent_notice" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg" maxlength="300"></textarea>
                        </div>

                        <div class="flex items-center">
                            <input type="checkbox" wire:model.live="enabled" id="enabled" class="rounded border-gray-300">
                            <label for="enabled" class="ml-2 text-sm text-gray-700">Віджет увімкнено</label>
                        </div>
                    </div>
                </div>

                <!-- Контакти та доставка -->
                <div class="bg-white rounded-lg shadow-sm p-6 mt-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Контакти та доставка</h3>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Телефон для зворотного зв'язку</label>
                            <input type="text" wire:model.live="shop_phone" class="w-full px-3 py-2 border border-gray-300 rounded-lg" maxlength="50">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Посилання на форму зворотного зв'язку</label>
                            <input type="url" wire:model.live="callback_form_url" class="w-full px-3 py-2 border border-gray-300 rounded-lg" maxlength="255">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Посилання на трекінг Нової Пошти</label>
                            <input type="url" wire:model.live="nova_poshta_tracking_url" class="w-full px-3 py-2 border border-gray-300 rounded-lg" maxlength="255">
                        </div>

                        <div class="flex items-center space-x-3">
                            <input type="checkbox" wire:model.live="enable_delivery_tracking" id="enable_delivery_tracking" class="rounded border-gray-300">
                            <label for="enable_delivery_tracking" class="text-sm text-gray-700">Увімкнути відображення статусу доставки / ТТН</label>
                        </div>

                        <div class="flex items-center space-x-3">
                            <input type="checkbox" wire:model.live="enable_faq_from_horoshop" id="enable_faq_from_horoshop" class="rounded border-gray-300">
                            <label for="enable_faq_from_horoshop" class="text-sm text-gray-700">Показувати FAQ сторінки з Horoshop</label>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Домен Horoshop (для формування посилань на FAQ)</label>
                            <input type="url" wire:model.live="horoshop_domain" class="w-full px-3 py-2 border border-gray-300 rounded-lg" maxlength="255" placeholder="https://contractor.kiev.ua">
                            <p class="mt-1 text-xs text-gray-500">Використовується для побудови прямих лінків типу /page/{id}</p>
                        </div>
                    </div>
                </div>

                <!-- FAQ налаштування -->
                <div class="bg-white rounded-lg shadow-sm p-6 mt-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">FAQ налаштування</h3>

                    <div class="flex items-center space-x-3 mb-4">
                        <input type="checkbox" wire:model.live="enable_faq_custom_content" id="enable_faq_custom_content" class="rounded border-gray-300">
                        <label for="enable_faq_custom_content" class="text-sm text-gray-700">Використовувати власний контент для FAQ</label>
                    </div>

                    <div class="grid grid-cols-2 gap-6">
                        <div class="space-y-3">
                            <label class="block text-sm font-medium text-gray-700">Оплата і доставка — URL</label>
                            <input type="url" wire:model.live="faq_payment_delivery_url" class="w-full px-3 py-2 border border-gray-300 rounded-lg" placeholder="https://contractor.kiev.ua/oplata-i-dostavka/">
                            <label class="block text-sm font-medium text-gray-700">Оплата і доставка — Текст (до 2000 символів)</label>
                            <textarea wire:model.live="faq_payment_delivery_text" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-lg" maxlength="2000"></textarea>
                        </div>

                        <div class="space-y-3">
                            <label class="block text-sm font-medium text-gray-700">Обмін та повернення — URL</label>
                            <input type="url" wire:model.live="faq_returns_url" class="w-full px-3 py-2 border border-gray-300 rounded-lg" placeholder="https://contractor.kiev.ua/obmin-ta-povernennya/">
                            <label class="block text-sm font-medium text-gray-700">Обмін та повернення — Текст</label>
                            <textarea wire:model.live="faq_returns_text" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-lg" maxlength="2000"></textarea>
                        </div>

                        <div class="space-y-3">
                            <label class="block text-sm font-medium text-gray-700">Контактна інформація — URL</label>
                            <input type="url" wire:model.live="faq_contacts_url" class="w-full px-3 py-2 border border-gray-300 rounded-lg" placeholder="https://contractor.kiev.ua/kontaktna-informatsiya/">
                            <label class="block text-sm font-medium text-gray-700">Контактна інформація — Текст</label>
                            <textarea wire:model.live="faq_contacts_text" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-lg" maxlength="2000"></textarea>
                        </div>

                        <div class="space-y-3">
                            <label class="block text-sm font-medium text-gray-700">Про нас — URL</label>
                            <input type="url" wire:model.live="faq_about_url" class="w-full px-3 py-2 border border-gray-300 rounded-lg" placeholder="https://contractor.kiev.ua/pro-nas/">
                            <label class="block text-sm font-medium text-gray-700">Про нас — Текст</label>
                            <textarea wire:model.live="faq_about_text" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-lg" maxlength="2000"></textarea>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="button" wire:click="fetchFaqNow" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-60" @if(!$can_fetch_now) disabled @endif>
                            Оновити FAQ з сторінок зараз
                        </button>
                        <p class="mt-2 text-xs text-gray-500">Перепарсить вказані URL-и і оновить тексти нижче. Доступно не частіше 1 разу на день.</p>
                        @if($faq_last_ingest_at)
                        <p class="mt-1 text-xs text-gray-500">Останнє оновлення: {{ $faq_last_ingest_at }} @if($next_fetch_time) | Наступне доступне: {{ $next_fetch_time }} @endif</p>
                        @endif
                    </div>
                </div>

                <!-- API Token -->
                <div class="bg-white rounded-lg shadow-sm p-6 mt-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">API Токен</h3>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Токен</label>
                            <div class="flex gap-2">
                                <input type="text" value="{{ $api_token }}" readonly class="flex-1 px-3 py-2 bg-gray-50 border border-gray-300 rounded-lg font-mono text-sm">
                                <button type="button" wire:click="regenerateToken" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                                    Оновити
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6">
                    <button type="submit" class="w-full px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium disabled:opacity-50 flex items-center justify-center gap-2" wire:loading.attr="disabled">
                        <svg wire:loading wire:target="save" class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span wire:loading.remove wire:target="save">Зберегти налаштування</span>
                        <span wire:loading wire:target="save">Зберігаю...</span>
                    </button>
                </div>
            </form>

            <!-- Install Code -->
            <div class="bg-white rounded-lg shadow-sm p-6 mt-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Код для вставки</h3>
                <pre class="bg-gray-50 p-4 rounded-lg text-xs overflow-x-auto"><code>&lt;!-- AIntento Chat Widget --&gt;
&lt;div id="aintento-chat" data-token="{{ $api_token }}"&gt;&lt;/div&gt;
&lt;script&gt;
(function(){
  var s = document.createElement('script');
  s.src = '{{ url('/widget.js') }}?v=' + Date.now();
  document.body.appendChild(s);
})();
&lt;/script&gt;</code></pre>
                <p class="mt-2 text-xs text-gray-500">Додайте цей код перед закриваючим тегом &lt;/body&gt; на вашому сайті. Віджет завжди завантажуватиме найновішу версію.</p>
            </div>
        </div>

        <!-- Preview -->
        <div class="sticky top-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">👁️ Попередній перегляд</h3>
            <div class="bg-gray-200 rounded-lg p-6 h-[650px] flex items-end justify-{{ $position === 'left' ? 'start' : 'end' }}" style="background-image: linear-gradient(45deg, #ccc 25%, transparent 25%), linear-gradient(-45deg, #ccc 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #ccc 75%), linear-gradient(-45deg, transparent 75%, #ccc 75%); background-size: 20px 20px; background-position: 0 0, 0 10px, 10px -10px, -10px 0px;">
                <div class="bg-white flex flex-col overflow-hidden" style="border-radius: {{ $border_radius }}px; width: 380px; height: 520px; {{ $show_shadow ? 'box-shadow: 0 12px 48px rgba(0,0,0,0.25);' : '' }}">
                    <!-- Header -->
                    <div class="p-4 flex items-center gap-3" style="background: linear-gradient(135deg, {{ $primary_color }} 0%, {{ $primary_color }}dd 100%); color: {{ $text_color }}; border-radius: {{ $border_radius }}px {{ $border_radius }}px 0 0;">
                        <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center border-2 border-cyan-400/60">
                            @if($bot_avatar_url)
                                <img src="{{ $bot_avatar_url }}" alt="{{ $bot_name }}" class="w-8 h-8 rounded-full">
                            @else
                                <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/>
                                </svg>
                            @endif
                        </div>
                        <div class="flex flex-col">
                            <h4 class="font-semibold text-[15px]" style="color: {{ $text_color }}">{{ $bot_name ?: 'AIntento' }}</h4>
                            <span class="text-xs opacity-90" style="color: {{ $text_color }}">🟢 {{ $bot_status_text ?: 'Завжди онлайн' }}</span>
                        </div>
                        <button class="ml-auto w-7 h-7 rounded-full bg-white/20 flex items-center justify-center text-lg" style="color: {{ $text_color }}">✕</button>
                    </div>
                    
                    <!-- Messages -->
                    <div class="flex-1 p-4 overflow-y-auto bg-gray-50">
                        <div class="flex gap-2 mb-3">
                            <div class="w-8 h-8 rounded-full bg-gray-200 flex-shrink-0 flex items-center justify-center">
                                @if($bot_avatar_url)
                                    <img src="{{ $bot_avatar_url }}" alt="" class="w-8 h-8 rounded-full">
                                @else
                                    <span class="text-sm">🤖</span>
                                @endif
                            </div>
                            <div class="bg-white rounded-2xl rounded-tl-sm p-3 text-sm shadow-sm max-w-[85%]">
                                {{ $welcome_message ?: 'Вітаю! 👋' }}
                            </div>
                        </div>
                        
                        <!-- Quick Actions Preview -->
                        <div class="flex flex-wrap gap-2 ml-10">
                            <button class="px-3 py-1.5 text-xs rounded-full border-2 transition-all" style="border-color: {{ $primary_color }}; color: {{ $primary_color }}">
                                🔍 Пошук товару
                            </button>
                            <button class="px-3 py-1.5 text-xs rounded-full border-2 transition-all" style="border-color: {{ $primary_color }}; color: {{ $primary_color }}">
                                📦 Статус замовлення
                            </button>
                        </div>
                    </div>
                    
                    <!-- Input -->
                    <div class="p-4 border-t bg-white">
                        <div class="flex gap-2">
                            <input type="text" placeholder="{{ $input_placeholder }}" disabled class="flex-1 px-4 py-2.5 border border-gray-300 rounded-full text-sm bg-gray-50">
                            <button class="w-10 h-10 rounded-full flex items-center justify-center text-white" style="background-color: {{ $primary_color }}">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                                </svg>
                            </button>
                        </div>
                        @if($consent_notice)
                        <p class="mt-2 text-xs text-gray-500 text-center">{{ $consent_notice }}</p>
                        @endif
                    </div>
                </div>
            </div>
            
            <!-- Tone Preview -->
            @if($tone)
            <div class="mt-4 p-4 bg-white rounded-lg shadow-sm">
                <h4 class="text-sm font-medium text-gray-700 mb-2">🎭 Приклад тону "{{ $tone === 'official' ? 'Офіційний' : ($tone === 'spartan' ? 'Лаконічний' : 'Дружній') }}"</h4>
                <div class="text-sm text-gray-600 italic">
                    @if($tone === 'official')
                        "Доброго дня! Із задоволенням допоможу вам підібрати товар. Які ваші побажання?"
                    @elseif($tone === 'spartan')
                        "Привіт. Що шукаєте?"
                    @else
                        "Привіт! 👋 Радий бачити! Давай підберемо щось круте — що тебе цікавить?"
                    @endif
                </div>
            </div>
            @endif
        </div>
    </div>
</div>

<div x-data="{ 
    showToast: false, 
    toastMessage: '',
    toastIsError: false,
    hasUnsavedChanges: false,
    originalData: {},
    initTracker() {
        this.originalData = JSON.stringify({
            primary_color: $wire.primary_color,
            text_color: $wire.text_color,
            position: $wire.position,
            bot_name: $wire.bot_name,
            welcome_message: $wire.welcome_message,
        });
    },
    checkChanges() {
        const current = JSON.stringify({
            primary_color: $wire.primary_color,
            text_color: $wire.text_color,
            position: $wire.position,
            bot_name: $wire.bot_name,
            welcome_message: $wire.welcome_message,
        });
        this.hasUnsavedChanges = this.originalData !== current;
    }
}" 
     x-init="initTracker()"
     x-on:notify.window="showToast = true; toastMessage = $event.detail; toastIsError = $event.detail.includes('Помилка'); if (!toastIsError) { hasUnsavedChanges = false; initTracker(); } setTimeout(() => showToast = false, 4000)">
    
    <!-- Toast Notification -->
    <div x-show="showToast" 
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         :class="toastIsError ? 'bg-red-600' : 'bg-green-600'"
         class="fixed bottom-4 right-4 z-50 px-6 py-3 text-white rounded-lg shadow-lg flex items-center gap-2">
        <svg x-show="!toastIsError" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
        </svg>
        <svg x-show="toastIsError" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
        <span x-text="toastMessage"></span>
    </div>

    <!-- Unsaved Changes Warning -->
    <div x-show="hasUnsavedChanges" 
         x-transition
         class="fixed top-4 left-1/2 -translate-x-1/2 z-50 px-4 py-2 bg-amber-100 border border-amber-300 text-amber-800 rounded-lg shadow-lg flex items-center gap-2 text-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        <span>Є незбережені зміни</span>
    </div>

    <!-- Header with Save Button -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-gray-900">⚙️ Налаштування віджета</h2>
            <p class="mt-1 text-sm text-gray-500">Персоналізуйте зовнішній вигляд та поведінку чат-віджета</p>
        </div>
        <button type="button" wire:click="save" 
                class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium disabled:opacity-50 flex items-center justify-center gap-2 transition-all"
                :class="{ 'ring-2 ring-amber-400 ring-offset-2': hasUnsavedChanges }"
                wire:loading.attr="disabled">
            <svg wire:loading wire:target="save" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span wire:loading.remove wire:target="save">💾 Зберегти</span>
            <span wire:loading wire:target="save">Зберігаю...</span>
        </button>
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

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <!-- Settings Form -->
        <div class="space-y-6">
            <form wire:submit="save" x-on:input="checkChanges()">
                <!-- Appearance -->
                <div class="bg-white rounded-lg shadow-sm p-4 sm:p-6">
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

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Основний колір</label>
                                <div class="flex gap-2">
                                    <input type="color" wire:model.live="primary_color" class="h-10 w-14 rounded border-gray-300 cursor-pointer">
                                    <input type="text" wire:model.live="primary_color" class="flex-1 px-3 py-2 border border-gray-300 rounded-lg font-mono text-sm" placeholder="#2563eb">
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Колір тексту</label>
                                <div class="flex gap-2">
                                    <input type="color" wire:model.live="text_color" class="h-10 w-14 rounded border-gray-300 cursor-pointer">
                                    <input type="text" wire:model.live="text_color" class="flex-1 px-3 py-2 border border-gray-300 rounded-lg font-mono text-sm" placeholder="#ffffff">
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
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
                <div class="bg-white rounded-lg shadow-sm p-4 sm:p-6 mt-6">
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
                                <div class="flex-shrink-0">
                                    @if($bot_avatar_base64 || $bot_avatar_url)
                                        <div class="relative">
                                            <img src="{{ $bot_avatar_base64 ?: $bot_avatar_url }}" alt="Avatar" class="w-16 h-16 rounded-full object-cover border-2 border-gray-200" style="box-shadow: 0 0 10px {{ $glow_color ?: $primary_color }};">
                                            <button type="button" wire:click="removeAvatar" class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white rounded-full text-xs hover:bg-red-600 flex items-center justify-center">✕</button>
                                        </div>
                                    @else
                                        <div class="w-16 h-16 rounded-full bg-gray-100 border-2 border-dashed border-gray-300 flex items-center justify-center">
                                            <span class="text-xl">🤖</span>
                                        </div>
                                    @endif
                                </div>
                                
                                <div class="flex-1 space-y-2">
                                    <div>
                                        <label class="inline-block px-3 py-1.5 text-sm bg-blue-50 text-blue-700 rounded-lg cursor-pointer hover:bg-blue-100 transition">
                                            📁 Вибрати файл
                                            <input type="file" wire:model="bot_avatar_upload" accept="image/*" class="hidden">
                                        </label>
                                        <div wire:loading wire:target="bot_avatar_upload" class="text-sm text-blue-600 mt-1">⏳ Завантаження...</div>
                                        @error('bot_avatar_upload') <span class="text-red-500 text-sm block mt-1">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="text-xs text-gray-500">або URL:</div>
                                    <input type="url" wire:model.live="bot_avatar_url" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="https://example.com/avatar.png">
                                </div>
                            </div>
                            <p class="mt-2 text-xs text-gray-500">80×80px, макс 1MB</p>
                        </div>

                        <!-- Glow Color -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Колір сяйва (glow)</label>
                            <div class="flex gap-2 items-center">
                                <input type="color" wire:model.live="glow_color" value="{{ $glow_color ?: $primary_color }}" class="h-10 w-14 rounded border-gray-300 cursor-pointer">
                                <input type="text" wire:model.live="glow_color" class="flex-1 px-3 py-2 border border-gray-300 rounded-lg font-mono text-sm" placeholder="{{ $primary_color }}">
                                <button type="button" wire:click="$set('glow_color', '')" class="px-3 py-2 text-xs bg-gray-100 text-gray-600 rounded-lg hover:bg-gray-200" title="Скинути до основного кольору">🔄</button>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">За замовчуванням використовує основний колір</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Статус бота</label>
                            <input type="text" wire:model.live="bot_status_text" class="w-full px-3 py-2 border border-gray-300 rounded-lg" placeholder="Завжди онлайн" maxlength="100">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Шрифт</label>
                            <select wire:model.live="font_family" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="">Системний</option>
                                <option value="'Inter', sans-serif">Inter</option>
                                <option value="'Roboto', sans-serif">Roboto</option>
                                <option value="'Open Sans', sans-serif">Open Sans</option>
                                <option value="'Montserrat', sans-serif">Montserrat</option>
                            </select>
                            @if($font_family)
                            <div class="mt-2 p-3 bg-gray-50 rounded-lg border text-sm" style="font-family: {{ $font_family }}">
                                Приклад: Привіт! Чим допомогти?
                            </div>
                            @endif
                        </div>

                        <div class="flex items-center">
                            <input type="checkbox" wire:model.live="show_shadow" id="show_shadow" class="rounded border-gray-300">
                            <label for="show_shadow" class="ml-2 text-sm text-gray-700">Тінь віджету</label>
                        </div>
                    </div>
                </div>

                <!-- Brand Rules -->
                <div class="bg-white rounded-lg shadow-sm p-4 sm:p-6 mt-6 border-2 border-gray-100">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">📋 Правила бренду</h3>
                            <p class="text-xs text-gray-500 mt-1">Інструкції для AI про стиль спілкування</p>
                        </div>
                        <div x-data="{ show: false }" class="relative">
                            <button type="button" @click="show = !show" class="w-6 h-6 rounded-full bg-gray-100 text-gray-500 hover:bg-gray-200 flex items-center justify-center text-sm">?</button>
                            <div x-show="show" @click.away="show = false" x-transition class="absolute right-0 mt-2 w-64 p-3 bg-white rounded-lg shadow-xl border z-10 text-xs">
                                <p class="font-medium mb-2">ℹ️ Приклади правил:</p>
                                <ul class="text-gray-600 space-y-1 list-disc pl-4">
                                    <li>Завжди звертайся на "ти"</li>
                                    <li>Не використовуй емодзі</li>
                                    <li>Пропонуй аксесуари до товарів</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="space-y-2">
                        @for($i = 0; $i < 5; $i++)
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-gray-400 w-4">{{ $i + 1 }}.</span>
                            <input type="text" wire:model.live="brand_rules.{{ $i }}" 
                                   class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm" 
                                   placeholder="Правило..."
                                   maxlength="200">
                        </div>
                        @endfor
                    </div>
                </div>

                <!-- Tone -->
                <div class="bg-white rounded-lg shadow-sm p-4 sm:p-6 mt-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">🎭 Тон відповідей</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="cursor-pointer" wire:click="$set('tone', 'official')">
                            <div class="p-3 border-2 rounded-lg transition-all {{ $tone === 'official' ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:border-gray-400' }} text-center">
                                <div class="text-lg">📋</div>
                                <div class="font-medium text-sm">Офіційний</div>
                                <div class="text-xs text-gray-500 mt-1 italic">«Доброго дня! З радістю допоможу.»</div>
                            </div>
                        </div>
                        <div class="cursor-pointer" wire:click="$set('tone', 'spartan')">
                            <div class="p-3 border-2 rounded-lg transition-all {{ $tone === 'spartan' ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:border-gray-400' }} text-center">
                                <div class="text-lg">⚡</div>
                                <div class="font-medium text-sm">Лаконічний</div>
                                <div class="text-xs text-gray-500 mt-1 italic">«Привіт. Що шукаєте?»</div>
                            </div>
                        </div>
                        <div class="cursor-pointer" wire:click="$set('tone', 'friendly')">
                            <div class="p-3 border-2 rounded-lg transition-all {{ $tone === 'friendly' ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:border-gray-400' }} text-center">
                                <div class="text-lg">😊</div>
                                <div class="font-medium text-sm">Дружній</div>
                                <div class="text-xs text-gray-500 mt-1 italic">«Привіт! 👋 Що тебе цікавить?»</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Content -->
                <div class="bg-white rounded-lg shadow-sm p-4 sm:p-6 mt-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">📝 Тексти</h3>
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
                            <label class="block text-sm font-medium text-gray-700 mb-1">Повідомлення про згоду</label>
                            <textarea wire:model.live="consent_notice" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg" maxlength="300"></textarea>
                        </div>

                        <div class="flex items-center">
                            <input type="checkbox" wire:model.live="enabled" id="enabled" class="rounded border-gray-300">
                            <label for="enabled" class="ml-2 text-sm text-gray-700">Віджет увімкнено</label>
                        </div>
                    </div>
                </div>

                <!-- Contacts -->
                <div class="bg-white rounded-lg shadow-sm p-4 sm:p-6 mt-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">📞 Контакти</h3>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Телефон</label>
                            <input type="text" wire:model.live="shop_phone" class="w-full px-3 py-2 border border-gray-300 rounded-lg" maxlength="50" placeholder="+380 XX XXX XXXX">
                            <p class="text-xs text-gray-500 mt-1">Телефон для зв'язку з магазином</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Форма зворотного зв'язку (URL)</label>
                            <input type="url" wire:model.live="callback_form_url" class="w-full px-3 py-2 border border-gray-300 rounded-lg" maxlength="255" placeholder="https://ваш-сайт.ua/kontakty/#callback">
                            <p class="text-xs text-gray-500 mt-1">Посилання на сторінку з формою замовлення дзвінка</p>
                        </div>
                    </div>
                </div>

                <!-- FAQ -->
                <div class="bg-white rounded-lg shadow-sm p-4 sm:p-6 mt-6 border-2 border-indigo-100">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-4">
                        <h3 class="text-lg font-semibold text-gray-900">❓ FAQ</h3>
                        <button type="button" wire:click="fetchFaqNow" 
                                class="px-3 py-1.5 text-sm bg-indigo-100 text-indigo-700 rounded-lg hover:bg-indigo-200 disabled:opacity-50 w-fit" 
                                @if(!$can_fetch_now) disabled @endif>
                            🔄 Оновити FAQ
                        </button>
                    </div>
                    
                    @if($faq_last_ingest_at)
                    <p class="text-xs text-gray-500 mb-4">Оновлено: {{ $faq_last_ingest_at }}</p>
                    @endif

                    <div class="flex items-center space-x-3 mb-4">
                        <input type="checkbox" wire:model.live="enable_faq_custom_content" id="enable_faq_custom_content" class="rounded border-gray-300">
                        <label for="enable_faq_custom_content" class="text-sm text-gray-700">Власний контент FAQ</label>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <div class="p-3 bg-gray-50 rounded-lg space-y-2">
                            <label class="block text-sm font-medium text-gray-700">💳 Оплата і доставка</label>
                            <input type="url" wire:model.live="faq_payment_delivery_url" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="URL">
                            <textarea wire:model.live="faq_payment_delivery_text" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" maxlength="2000" placeholder="Текст"></textarea>
                        </div>

                        <div class="p-3 bg-gray-50 rounded-lg space-y-2">
                            <label class="block text-sm font-medium text-gray-700">🔄 Обмін та повернення</label>
                            <input type="url" wire:model.live="faq_returns_url" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="URL">
                            <textarea wire:model.live="faq_returns_text" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" maxlength="2000" placeholder="Текст"></textarea>
                        </div>

                        <div class="p-3 bg-gray-50 rounded-lg space-y-2">
                            <label class="block text-sm font-medium text-gray-700">📍 Контакти</label>
                            <input type="url" wire:model.live="faq_contacts_url" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="URL">
                            <textarea wire:model.live="faq_contacts_text" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" maxlength="2000" placeholder="Текст"></textarea>
                        </div>

                        <div class="p-3 bg-gray-50 rounded-lg space-y-2">
                            <label class="block text-sm font-medium text-gray-700">ℹ️ Про нас</label>
                            <input type="url" wire:model.live="faq_about_url" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="URL">
                            <textarea wire:model.live="faq_about_text" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" maxlength="2000" placeholder="Текст"></textarea>
                        </div>
                    </div>
                </div>

                <!-- API Token -->
                <div class="bg-white rounded-lg shadow-sm p-4 sm:p-6 mt-6">
                    <div class="flex items-start justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-900">🔑 API Токен</h3>
                        <div x-data="{ show: false }" class="relative">
                            <button type="button" @click="show = !show" class="w-6 h-6 rounded-full bg-gray-100 text-gray-500 hover:bg-gray-200 flex items-center justify-center text-sm">?</button>
                            <div x-show="show" @click.away="show = false" x-transition class="absolute right-0 mt-2 w-64 p-3 bg-white rounded-lg shadow-xl border z-10 text-xs">
                                <p>Токен ідентифікує ваш магазин. Оновіть його якщо скомпрометовано.</p>
                            </div>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <input type="text" value="{{ $api_token }}" readonly class="flex-1 px-3 py-2 bg-gray-50 border border-gray-300 rounded-lg font-mono text-xs">
                        <button type="button" wire:click="regenerateToken" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 text-sm">🔄</button>
                    </div>
                </div>

                <div class="mt-6">
                    <button type="submit" 
                            class="w-full px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium disabled:opacity-50 flex items-center justify-center gap-2"
                            :class="{ 'ring-2 ring-amber-400': hasUnsavedChanges }"
                            wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="save">💾 Зберегти налаштування</span>
                        <span wire:loading wire:target="save">Зберігаю...</span>
                    </button>
                </div>
            </form>

            <!-- Install Code (hidden when embedded with hideEmbedCode) -->
            @if(!$hideEmbedCode)
            <div class="bg-white rounded-lg shadow-sm p-4 sm:p-6 mt-6">
                <div class="flex items-start justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">📋 Код для вставки</h3>
                    <div x-data="{ show: false }" class="relative">
                        <button type="button" @click="show = !show" class="w-6 h-6 rounded-full bg-gray-100 text-gray-500 hover:bg-gray-200 flex items-center justify-center text-sm">?</button>
                        <div x-show="show" @click.away="show = false" x-transition class="absolute right-0 mt-2 w-64 p-3 bg-white rounded-lg shadow-xl border z-10 text-xs">
                            <ol class="list-decimal pl-4 space-y-1">
                                <li>Скопіюйте код</li>
                                <li>Вставте перед &lt;/body&gt;</li>
                                <li>Готово!</li>
                            </ol>
                        </div>
                    </div>
                </div>
                <pre class="bg-gray-900 text-green-400 p-4 rounded-lg text-xs overflow-x-auto"><code>&lt;!-- AIntento Chat --&gt;
&lt;div id="aintento-chat" data-token="{{ $api_token }}"&gt;&lt;/div&gt;
&lt;script&gt;
(function(){
  var s = document.createElement('script');
  s.src = '{{ url('/widget.js') }}?v=' + Date.now();
  document.body.appendChild(s);
})();
&lt;/script&gt;</code></pre>
                <button type="button" 
                        onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent); this.textContent = '✓ Скопійовано!'; setTimeout(() => this.textContent = '📋 Скопіювати', 2000)"
                        class="mt-3 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-sm">
                    📋 Скопіювати
                </button>
            </div>
            @endif
        </div>

        <!-- Preview (desktop only) -->
        <div class="hidden xl:block sticky top-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">👁️ Попередній перегляд</h3>
            
            <div class="rounded-xl overflow-hidden shadow-lg border border-gray-200" 
                 style="background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%); height: 600px;">
                <div class="h-full flex items-end justify-{{ $position === 'left' ? 'start' : 'end' }} p-6">
                    <div class="bg-white flex flex-col overflow-hidden" style="border-radius: {{ $border_radius }}px; width: 340px; height: 480px; {{ $show_shadow ? 'box-shadow: 0 10px 40px rgba(0,0,0,0.15);' : '' }}">
                        <!-- Header -->
                        <div class="p-3 flex items-center gap-3" style="background: {{ $primary_color }}; color: {{ $text_color }}; border-radius: {{ $border_radius }}px {{ $border_radius }}px 0 0;">
                            <div class="w-9 h-9 rounded-full bg-white/20 flex items-center justify-center overflow-hidden" style="box-shadow: 0 0 8px {{ $glow_color ?: $primary_color }};">
                                @if($bot_avatar_base64 || $bot_avatar_url)
                                    <img src="{{ $bot_avatar_base64 ?: $bot_avatar_url }}" alt="" class="w-7 h-7 rounded-full object-cover">
                                @else
                                    <span>🤖</span>
                                @endif
                            </div>
                            <div class="flex flex-col">
                                <span class="font-semibold text-sm">{{ $bot_name ?: 'AIntento' }}</span>
                                <span class="text-xs opacity-80">🟢 {{ $bot_status_text ?: 'Онлайн' }}</span>
                            </div>
                            <button class="ml-auto w-6 h-6 rounded-full bg-white/20 text-sm">✕</button>
                        </div>
                        
                        <!-- Messages -->
                        <div class="flex-1 p-3 overflow-y-auto bg-gray-50">
                            <div class="flex gap-2 mb-3">
                                <div class="w-7 h-7 rounded-full bg-gray-200 flex-shrink-0 flex items-center justify-center overflow-hidden" style="box-shadow: 0 0 6px {{ $glow_color ?: $primary_color }};">
                                    @if($bot_avatar_base64 || $bot_avatar_url)
                                        <img src="{{ $bot_avatar_base64 ?: $bot_avatar_url }}" alt="" class="w-7 h-7 rounded-full object-cover">
                                    @else
                                        <span class="text-xs">🤖</span>
                                    @endif
                                </div>
                                <div class="bg-white rounded-xl rounded-tl-sm p-2.5 text-xs shadow-sm max-w-[85%]">
                                    {{ Str::limit($welcome_message ?: 'Вітаю! 👋', 100) }}
                                </div>
                            </div>
                            
                            <div class="flex flex-wrap gap-1.5 ml-9">
                                <button class="px-2 py-1 text-[10px] rounded-full border" style="border-color: {{ $primary_color }}; color: {{ $primary_color }}">
                                    🔍 Пошук
                                </button>
                                <button class="px-2 py-1 text-[10px] rounded-full border" style="border-color: {{ $primary_color }}; color: {{ $primary_color }}">
                                    📦 Замовлення
                                </button>
                            </div>
                        </div>
                        
                        <!-- Input -->
                        <div class="p-3 border-t bg-white">
                            <div class="flex gap-2">
                                <input type="text" placeholder="{{ Str::limit($input_placeholder, 25) }}" disabled class="flex-1 px-3 py-2 border border-gray-300 rounded-full text-xs bg-gray-50">
                                <button class="w-8 h-8 rounded-full flex items-center justify-center text-white text-sm" style="background-color: {{ $primary_color }}">➤</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            @if($tone)
            <div class="mt-4 p-3 bg-white rounded-lg shadow-sm">
                <h4 class="text-sm font-medium text-gray-700 mb-2">🎭 Тон "{{ $tone === 'official' ? 'Офіційний' : ($tone === 'spartan' ? 'Лаконічний' : 'Дружній') }}"</h4>
                <div class="text-xs text-gray-600 italic">
                    @if($tone === 'official') "Доброго дня! Із задоволенням допоможу." @elseif($tone === 'spartan') "Привіт. Що шукаєте?" @else "Привіт! 👋 Що тебе цікавить?" @endif
                </div>
            </div>
            @endif
        </div>
    </div>
</div>

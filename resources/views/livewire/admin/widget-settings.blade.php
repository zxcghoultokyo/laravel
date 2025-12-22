<div>
    <!-- Header -->
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900">Налаштування віджета</h2>
        <p class="mt-1 text-sm text-gray-500">Персоналізуйте зовнішній вигляд чат-віджета</p>
    </div>

    @if (session()->has('message'))
    <div class="mb-4 p-4 bg-green-50 border border-green-200 text-green-800 rounded-lg">
        {{ session('message') }}
    </div>
    @endif

    <div class="grid grid-cols-2 gap-6">
        <!-- Settings Form -->
        <div class="space-y-6">
            <form wire:submit="save">
                <!-- Appearance -->
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Зовнішній вигляд</h3>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Основний колір</label>
                            <input type="color" wire:model.live="primary_color" class="h-10 w-full rounded border-gray-300">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Колір тексту</label>
                            <input type="color" wire:model.live="text_color" class="h-10 w-full rounded border-gray-300">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Позиція</label>
                            <select wire:model.live="position" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="left">Зліва</option>
                                <option value="right">Справа</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Початковий стан</label>
                            <select wire:model.live="start_state" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="open">Відкритий</option>
                                <option value="closed">Закритий</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Радіус закруглення (px)</label>
                            <input type="number" wire:model.live="border_radius" min="0" max="50" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
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
                        <button type="button" wire:click="fetchFaqNow" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                            Оновити FAQ з сторінок зараз
                        </button>
                        <p class="mt-2 text-xs text-gray-500">Перепарсить вказані URL-и і оновить тексти нижче.</p>
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
                    <button type="submit" class="w-full px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium">
                        Зберегти налаштування
                    </button>
                </div>
            </form>

            <!-- Install Code -->
            <div class="bg-white rounded-lg shadow-sm p-6 mt-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Код для вставки</h3>
                <pre class="bg-gray-50 p-4 rounded-lg text-xs overflow-x-auto"><code>&lt;script src="{{ url('/widget.js') }}"&gt;&lt;/script&gt;
&lt;div id="ailure-chat" data-token="{{ $api_token }}"&gt;&lt;/div&gt;</code></pre>
            </div>
        </div>

        <!-- Preview -->
        <div class="sticky top-6">
            <div class="bg-gray-100 rounded-lg p-6 h-[600px] flex items-end justify-{{ $position === 'left' ? 'start' : 'end' }}">
                <div class="bg-white rounded-lg shadow-xl w-96 h-[500px] flex flex-col" style="border-radius: {{ $border_radius }}px;">
                    <!-- Header -->
                    <div class="p-4 rounded-t-lg" style="background-color: {{ $primary_color }}; color: {{ $text_color }}; border-radius: {{ $border_radius }}px {{ $border_radius }}px 0 0;">
                        <h4 class="font-semibold">AILure Асистент</h4>
                    </div>
                    
                    <!-- Messages -->
                    <div class="flex-1 p-4 overflow-y-auto">
                        <div class="bg-gray-100 rounded-lg p-3 mb-3 text-sm">
                            {{ $welcome_message ?: 'Вітаю! 👋' }}
                        </div>
                    </div>
                    
                    <!-- Input -->
                    <div class="p-4 border-t">
                        <input type="text" placeholder="{{ $input_placeholder }}" disabled class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                        @if($consent_notice)
                        <p class="mt-2 text-xs text-gray-500">{{ $consent_notice }}</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

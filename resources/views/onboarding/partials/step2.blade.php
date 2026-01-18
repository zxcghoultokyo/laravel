<!-- Step 2: Platform Credentials -->
<div class="text-center mb-8">
    <h3 class="text-2xl font-bold text-gray-900">Підключіть ваш магазин</h3>
    <p class="mt-2 text-gray-600">Введіть дані для доступу до API Horoshop</p>
</div>

<form method="POST" action="{{ route('onboarding.step2.save') }}">
    @csrf

    @if($tenant->platform === 'horoshop')
        <!-- Instructions - always visible blocks -->
        <div class="mb-6 max-w-xl mx-auto">
            <div class="p-4 bg-amber-50 border border-amber-200 rounded-lg mb-3">
                <div class="flex items-center gap-3 mb-3">
                    <span class="text-2xl">📋</span>
                    <span class="font-medium text-amber-800">Як створити користувача для API?</span>
                </div>
            </div>
            
            <div class="p-4 bg-white border border-gray-200 rounded-lg">
                <ol class="space-y-3 text-sm text-gray-700">
                    <li class="flex gap-3">
                        <span class="flex-shrink-0 w-6 h-6 bg-emerald-100 text-emerald-700 rounded-full flex items-center justify-center text-xs font-bold">1</span>
                        <div>
                            <p class="font-medium">Відкрийте адмінпанель вашого магазину</p>
                            <p class="text-gray-500">Перейдіть за адресою <code class="bg-gray-100 px-1 rounded">ваш-домен/admin/</code></p>
                        </div>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex-shrink-0 w-6 h-6 bg-emerald-100 text-emerald-700 rounded-full flex items-center justify-center text-xs font-bold">2</span>
                        <div>
                            <p class="font-medium">Перейдіть в Налаштування → Адміни</p>
                            <p class="text-gray-500">Натисніть "Додати" для створення нового користувача</p>
                        </div>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex-shrink-0 w-6 h-6 bg-emerald-100 text-emerald-700 rounded-full flex items-center justify-center text-xs font-bold">3</span>
                        <div>
                            <p class="font-medium">Заповніть обов'язкові поля:</p>
                            <ul class="mt-1 ml-4 list-disc text-gray-500 space-y-1">
                                <li><strong>Логін</strong> — наприклад: <code class="bg-gray-100 px-1 rounded">aintento_api</code></li>
                                <li><strong>Пароль</strong> — надійний пароль</li>
                                <li><strong>Email</strong> — ваша email адреса для повідомлень</li>
                                <li><strong>Роль</strong> — оберіть <code class="bg-amber-100 text-amber-800 px-1 rounded">Marketing-specialist</code></li>
                            </ul>
                        </div>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex-shrink-0 w-6 h-6 bg-emerald-100 text-emerald-700 rounded-full flex items-center justify-center text-xs font-bold">4</span>
                        <div>
                            <p class="font-medium">Перевірте налаштування</p>
                            <ul class="mt-1 ml-4 list-disc text-gray-500 space-y-1">
                                <li>❌ <strong>Заблоковано</strong> — має бути <u>вимкнено</u></li>
                            </ul>
                        </div>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex-shrink-0 w-6 h-6 bg-emerald-100 text-emerald-700 rounded-full flex items-center justify-center text-xs font-bold">5</span>
                        <div>
                            <p class="font-medium">Збережіть і введіть дані нижче</p>
                        </div>
                    </li>
                </ol>
                
                <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                    <p class="text-xs text-blue-700">
                        <strong>💡 Чому Marketing-specialist?</strong><br>
                        Ця роль має доступ до товарів та замовлень, але не може змінювати критичні налаштування магазину. Це безпечно для інтеграції.
                    </p>
                </div>
            </div>
        </div>

        <div class="space-y-4 max-w-md mx-auto">
            <div>
                <x-input-label for="api_domain" value="Домен магазину" />
                <x-text-input id="api_domain" 
                              name="api_domain" 
                              type="text" 
                              class="mt-1 block w-full" 
                              placeholder="https://myshop.horoshop.ua"
                              :value="old('api_domain', $tenant->domain)"
                              required />
                <p class="mt-1 text-sm text-gray-500">Без / в кінці. Приклад: <code class="bg-gray-100 px-1 rounded">https://contractor.horoshop.ua</code></p>
                <x-input-error :messages="$errors->get('api_domain')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="api_login" value="Логін адміна (який ви створили)" />
                <x-text-input id="api_login" 
                              name="api_login" 
                              type="text" 
                              class="mt-1 block w-full" 
                              placeholder="aintento_api"
                              :value="old('api_login')"
                              required />
                <x-input-error :messages="$errors->get('api_login')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="api_password" value="Пароль адміна" />
                <x-text-input id="api_password" 
                              name="api_password" 
                              type="password" 
                              class="mt-1 block w-full" 
                              placeholder="••••••••"
                              required />
                <x-input-error :messages="$errors->get('api_password')" class="mt-2" />
            </div>

            @error('connection')
                <div class="p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-start gap-3">
                        <span class="text-xl">❌</span>
                        <div>
                            <p class="font-medium text-red-800">Помилка підключення</p>
                            <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                            <p class="text-red-600 text-sm mt-2">Перевірте що користувач не заблокований та має роль Marketing-specialist або вище.</p>
                        </div>
                    </div>
                </div>
            @enderror
        </div>
    @endif

    <div class="flex justify-between mt-8 max-w-md mx-auto">
        <a href="{{ route('onboarding.step1') }}" class="inline-flex items-center px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg text-gray-700 transition">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
            Назад
        </a>
        <x-primary-button>
            Перевірити підключення
            <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
        </x-primary-button>
    </div>
</form>

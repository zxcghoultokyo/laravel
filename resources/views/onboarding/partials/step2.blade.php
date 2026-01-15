<!-- Step 2: Platform Credentials -->
<div class="text-center mb-8">
    <h3 class="text-2xl font-bold text-gray-900">Підключіть ваш магазин</h3>
    <p class="mt-2 text-gray-600">Введіть дані для доступу до API</p>
</div>

<form method="POST" action="{{ route('onboarding.step2.save') }}">
    @csrf

    @if($tenant->platform === 'horoshop')
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
                <p class="mt-1 text-sm text-gray-500">Наприклад: https://contractor.horoshop.ua</p>
                <x-input-error :messages="$errors->get('api_domain')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="api_login" value="API Login" />
                <x-text-input id="api_login" 
                              name="api_login" 
                              type="text" 
                              class="mt-1 block w-full" 
                              :value="old('api_login')"
                              required />
                <x-input-error :messages="$errors->get('api_login')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="api_password" value="API Password" />
                <x-text-input id="api_password" 
                              name="api_password" 
                              type="password" 
                              class="mt-1 block w-full" 
                              required />
                <x-input-error :messages="$errors->get('api_password')" class="mt-2" />
            </div>

            @error('connection')
                <div class="p-4 bg-red-50 border border-red-200 rounded-lg">
                    <p class="text-red-600 text-sm">{{ $message }}</p>
                </div>
            @enderror

            <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <h4 class="font-medium text-blue-800 mb-2">Як отримати API ключі?</h4>
                <ol class="text-sm text-blue-700 list-decimal list-inside space-y-1">
                    <li>Увійдіть в панель адміністратора Horoshop</li>
                    <li>Перейдіть в Налаштування → API</li>
                    <li>Створіть новий API ключ або використайте існуючий</li>
                </ol>
            </div>
        </div>
    @endif

    <div class="flex justify-between mt-8">
        <a href="{{ route('onboarding.step1') }}" class="inline-flex items-center px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-md text-gray-700">
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

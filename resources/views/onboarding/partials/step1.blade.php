<!-- Step 1: Platform Selection -->
<div class="text-center mb-8">
    <h3 class="text-2xl font-bold text-gray-900">Виберіть вашу платформу</h3>
    <p class="mt-2 text-gray-600">Звідки імпортувати товари?</p>
</div>

<form method="POST" action="{{ route('onboarding.step1.save') }}">
    @csrf
    
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 mb-8">
        <!-- Horoshop - Active -->
        <label class="relative cursor-pointer">
            <input type="radio" name="platform" value="horoshop" class="peer sr-only" required>
            <div class="p-4 md:p-6 border-2 rounded-xl text-center transition-all
                        peer-checked:border-emerald-500 peer-checked:bg-emerald-50
                        hover:border-emerald-300 hover:shadow-md">
                <div class="w-12 h-12 md:w-16 md:h-16 mx-auto mb-3 bg-orange-100 rounded-full flex items-center justify-center">
                    <svg class="w-6 h-6 md:w-8 md:h-8 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                    </svg>
                </div>
                <h4 class="font-semibold text-sm md:text-lg">Horoshop</h4>
                <p class="text-xs text-gray-500 mt-1 hidden md:block">Українська платформа</p>
                <span class="inline-block mt-2 px-2 py-1 bg-emerald-100 text-emerald-700 text-xs rounded font-medium">✓ Готово</span>
            </div>
        </label>

        <!-- Shopify - Coming Soon -->
        <div class="relative opacity-60 cursor-not-allowed">
            <div class="p-4 md:p-6 border-2 border-gray-200 rounded-xl text-center bg-gray-50">
                <div class="w-12 h-12 md:w-16 md:h-16 mx-auto mb-3 bg-green-100 rounded-full flex items-center justify-center">
                    <span class="text-xl md:text-2xl">🛒</span>
                </div>
                <h4 class="font-semibold text-sm md:text-lg text-gray-600">Shopify</h4>
                <p class="text-xs text-gray-400 mt-1 hidden md:block">Глобальна платформа</p>
                <span class="inline-block mt-2 px-2 py-1 bg-amber-100 text-amber-700 text-xs rounded font-medium">Q1 2026</span>
            </div>
        </div>

        <!-- WooCommerce - Coming Soon -->
        <div class="relative opacity-60 cursor-not-allowed">
            <div class="p-4 md:p-6 border-2 border-gray-200 rounded-xl text-center bg-gray-50">
                <div class="w-12 h-12 md:w-16 md:h-16 mx-auto mb-3 bg-purple-100 rounded-full flex items-center justify-center">
                    <span class="text-xl md:text-2xl">🔮</span>
                </div>
                <h4 class="font-semibold text-sm md:text-lg text-gray-600">WooCommerce</h4>
                <p class="text-xs text-gray-400 mt-1 hidden md:block">WordPress плагін</p>
                <span class="inline-block mt-2 px-2 py-1 bg-amber-100 text-amber-700 text-xs rounded font-medium">Q1 2026</span>
            </div>
        </div>

        <!-- Prom.ua - Coming Soon -->
        <div class="relative opacity-60 cursor-not-allowed">
            <div class="p-4 md:p-6 border-2 border-gray-200 rounded-xl text-center bg-gray-50">
                <div class="w-12 h-12 md:w-16 md:h-16 mx-auto mb-3 bg-blue-100 rounded-full flex items-center justify-center">
                    <span class="text-xl md:text-2xl">🇺🇦</span>
                </div>
                <h4 class="font-semibold text-sm md:text-lg text-gray-600">Prom.ua</h4>
                <p class="text-xs text-gray-400 mt-1 hidden md:block">Маркетплейс</p>
                <span class="inline-block mt-2 px-2 py-1 bg-amber-100 text-amber-700 text-xs rounded font-medium">Q2 2026</span>
            </div>
        </div>

        <!-- Rozetka - Coming Soon -->
        <div class="relative opacity-60 cursor-not-allowed">
            <div class="p-4 md:p-6 border-2 border-gray-200 rounded-xl text-center bg-gray-50">
                <div class="w-12 h-12 md:w-16 md:h-16 mx-auto mb-3 bg-green-100 rounded-full flex items-center justify-center">
                    <span class="text-xl md:text-2xl">🌹</span>
                </div>
                <h4 class="font-semibold text-sm md:text-lg text-gray-600">Rozetka</h4>
                <p class="text-xs text-gray-400 mt-1 hidden md:block">Маркетплейс</p>
                <span class="inline-block mt-2 px-2 py-1 bg-amber-100 text-amber-700 text-xs rounded font-medium">Q2 2026</span>
            </div>
        </div>

        <!-- OpenCart - Coming Soon -->
        <div class="relative opacity-60 cursor-not-allowed">
            <div class="p-4 md:p-6 border-2 border-gray-200 rounded-xl text-center bg-gray-50">
                <div class="w-12 h-12 md:w-16 md:h-16 mx-auto mb-3 bg-cyan-100 rounded-full flex items-center justify-center">
                    <span class="text-xl md:text-2xl">🛠</span>
                </div>
                <h4 class="font-semibold text-sm md:text-lg text-gray-600">OpenCart</h4>
                <p class="text-xs text-gray-400 mt-1 hidden md:block">OcStore 3-4</p>
                <span class="inline-block mt-2 px-2 py-1 bg-amber-100 text-amber-700 text-xs rounded font-medium">Q2 2026</span>
            </div>
        </div>

        <!-- Tilda - Widget Works -->
        <div class="relative opacity-60 cursor-not-allowed">
            <div class="p-4 md:p-6 border-2 border-gray-200 rounded-xl text-center bg-gray-50">
                <div class="w-12 h-12 md:w-16 md:h-16 mx-auto mb-3 bg-yellow-100 rounded-full flex items-center justify-center">
                    <span class="text-xl md:text-2xl">📄</span>
                </div>
                <h4 class="font-semibold text-sm md:text-lg text-gray-600">Tilda</h4>
                <p class="text-xs text-gray-400 mt-1 hidden md:block">Конструктор</p>
                <span class="inline-block mt-2 px-2 py-1 bg-blue-100 text-blue-700 text-xs rounded font-medium">Віджет</span>
            </div>
        </div>

        <!-- Other platforms - Manual -->
        <label class="relative cursor-pointer">
            <input type="radio" name="platform" value="manual" class="peer sr-only">
            <div class="p-4 md:p-6 border-2 rounded-xl text-center transition-all
                        peer-checked:border-emerald-500 peer-checked:bg-emerald-50
                        hover:border-gray-300 hover:shadow-md">
                <div class="w-12 h-12 md:w-16 md:h-16 mx-auto mb-3 bg-gray-100 rounded-full flex items-center justify-center">
                    <span class="text-xl md:text-2xl">📦</span>
                </div>
                <h4 class="font-semibold text-sm md:text-lg">Інше</h4>
                <p class="text-xs text-gray-500 mt-1 hidden md:block">CSV / ручний імпорт</p>
                <span class="inline-block mt-2 px-2 py-1 bg-gray-100 text-gray-600 text-xs rounded font-medium">Ручний</span>
            </div>
        </label>
    </div>

    @error('platform')
        <p class="text-red-500 text-sm mb-4">{{ $message }}</p>
    @enderror

    <div class="flex justify-end">
        <x-primary-button>
            Продовжити
            <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
        </x-primary-button>
    </div>
</form>

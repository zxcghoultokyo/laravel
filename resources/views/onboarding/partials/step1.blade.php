<!-- Step 1: Platform Selection -->
<div class="text-center mb-8">
    <h3 class="text-2xl font-bold text-gray-900">Виберіть вашу платформу</h3>
    <p class="mt-2 text-gray-600">Звідки імпортувати товари?</p>
</div>

<form method="POST" action="{{ route('onboarding.step1.save') }}">
    @csrf
    
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <!-- Horoshop - Active -->
        <label class="relative cursor-pointer">
            <input type="radio" name="platform" value="horoshop" class="peer sr-only" required>
            <div class="p-6 border-2 rounded-xl text-center transition-all
                        peer-checked:border-emerald-500 peer-checked:bg-emerald-50
                        hover:border-emerald-300 hover:shadow-md">
                <div class="w-16 h-16 mx-auto mb-4 bg-orange-100 rounded-full flex items-center justify-center">
                    <svg class="w-8 h-8 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                    </svg>
                </div>
                <h4 class="font-semibold text-lg">Horoshop</h4>
                <p class="text-sm text-gray-500 mt-1">Українська e-commerce платформа</p>
                <span class="inline-block mt-2 px-2 py-1 bg-emerald-100 text-emerald-700 text-xs rounded font-medium">✓ Підтримується</span>
            </div>
        </label>

        <!-- Shopify - Coming Soon (Disabled) -->
        <div class="relative opacity-50 cursor-not-allowed">
            <div class="p-6 border-2 border-gray-200 rounded-xl text-center bg-gray-50">
                <div class="w-16 h-16 mx-auto mb-4 bg-gray-200 rounded-full flex items-center justify-center">
                    <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                </div>
                <h4 class="font-semibold text-lg text-gray-500">Shopify</h4>
                <p class="text-sm text-gray-400 mt-1">Глобальна платформа</p>
                <span class="inline-block mt-2 px-2 py-1 bg-amber-100 text-amber-700 text-xs rounded font-medium">Скоро</span>
            </div>
        </div>

        <!-- OpenCart/OcStore - Coming Soon (Disabled) -->
        <div class="relative opacity-50 cursor-not-allowed">
            <div class="p-6 border-2 border-gray-200 rounded-xl text-center bg-gray-50">
                <div class="w-16 h-16 mx-auto mb-4 bg-gray-200 rounded-full flex items-center justify-center">
                    <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                    </svg>
                </div>
                <h4 class="font-semibold text-lg text-gray-500">OpenCart</h4>
                <p class="text-sm text-gray-400 mt-1">OcStore / OpenCart 3-4</p>
                <span class="inline-block mt-2 px-2 py-1 bg-amber-100 text-amber-700 text-xs rounded font-medium">Скоро</span>
            </div>
        </div>

        <!-- Prom.ua - Coming Soon (Disabled) -->
        <div class="relative opacity-50 cursor-not-allowed">
            <div class="p-6 border-2 border-gray-200 rounded-xl text-center bg-gray-50">
                <div class="w-16 h-16 mx-auto mb-4 bg-gray-200 rounded-full flex items-center justify-center">
                    <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path>
                    </svg>
                </div>
                <h4 class="font-semibold text-lg text-gray-500">Prom.ua</h4>
                <p class="text-sm text-gray-400 mt-1">Маркетплейс України</p>
                <span class="inline-block mt-2 px-2 py-1 bg-amber-100 text-amber-700 text-xs rounded font-medium">Скоро</span>
            </div>
        </div>
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

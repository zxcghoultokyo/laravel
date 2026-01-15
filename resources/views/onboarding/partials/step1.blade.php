<!-- Step 1: Platform Selection -->
<div class="text-center mb-8">
    <h3 class="text-2xl font-bold text-gray-900">Виберіть вашу платформу</h3>
    <p class="mt-2 text-gray-600">Звідки імпортувати товари?</p>
</div>

<form method="POST" action="{{ route('onboarding.step1.save') }}">
    @csrf
    
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        <!-- Horoshop -->
        <label class="relative cursor-pointer">
            <input type="radio" name="platform" value="horoshop" class="peer sr-only" required>
            <div class="p-6 border-2 rounded-xl text-center transition-all
                        peer-checked:border-blue-500 peer-checked:bg-blue-50
                        hover:border-gray-300">
                <div class="w-16 h-16 mx-auto mb-4 bg-orange-100 rounded-full flex items-center justify-center">
                    <svg class="w-8 h-8 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                    </svg>
                </div>
                <h4 class="font-semibold text-lg">Horoshop</h4>
                <p class="text-sm text-gray-500 mt-1">Українська e-commerce платформа</p>
            </div>
        </label>

        <!-- Shopify -->
        <label class="relative cursor-pointer">
            <input type="radio" name="platform" value="shopify" class="peer sr-only">
            <div class="p-6 border-2 rounded-xl text-center transition-all
                        peer-checked:border-blue-500 peer-checked:bg-blue-50
                        hover:border-gray-300">
                <div class="w-16 h-16 mx-auto mb-4 bg-green-100 rounded-full flex items-center justify-center">
                    <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                </div>
                <h4 class="font-semibold text-lg">Shopify</h4>
                <p class="text-sm text-gray-500 mt-1">Глобальна платформа</p>
                <span class="inline-block mt-2 px-2 py-1 bg-yellow-100 text-yellow-800 text-xs rounded">Скоро</span>
            </div>
        </label>

        <!-- Manual -->
        <label class="relative cursor-pointer">
            <input type="radio" name="platform" value="manual" class="peer sr-only">
            <div class="p-6 border-2 rounded-xl text-center transition-all
                        peer-checked:border-blue-500 peer-checked:bg-blue-50
                        hover:border-gray-300">
                <div class="w-16 h-16 mx-auto mb-4 bg-gray-100 rounded-full flex items-center justify-center">
                    <svg class="w-8 h-8 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                    </svg>
                </div>
                <h4 class="font-semibold text-lg">Вручну</h4>
                <p class="text-sm text-gray-500 mt-1">Завантажити CSV/Excel</p>
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

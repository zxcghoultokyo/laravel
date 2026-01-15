<!-- Step 4: Widget Customization -->
<div class="text-center mb-8">
    <h3 class="text-2xl font-bold text-gray-900">Налаштуйте віджет</h3>
    <p class="mt-2 text-gray-600">Зробіть бота у стилі вашого бренду</p>
</div>

<form method="POST" action="{{ route('onboarding.step4.save') }}">
    @csrf

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- Settings -->
        <div class="space-y-4">
            <div>
                <x-input-label for="primary_color" value="Колір бренду" />
                <div class="mt-1 flex items-center space-x-3">
                    <input type="color" 
                           id="primary_color" 
                           name="primary_color" 
                           value="{{ old('primary_color', $settings->primary_color ?? '#2563EB') }}"
                           class="w-12 h-12 rounded cursor-pointer">
                    <x-text-input type="text" 
                                  id="primary_color_text" 
                                  class="w-32"
                                  value="{{ old('primary_color', $settings->primary_color ?? '#2563EB') }}" />
                </div>
                <x-input-error :messages="$errors->get('primary_color')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="header_text" value="Заголовок віджета" />
                <x-text-input id="header_text" 
                              name="header_text" 
                              type="text" 
                              class="mt-1 block w-full" 
                              :value="old('header_text', $settings->header_text ?? 'AI Асистент')"
                              maxlength="100"
                              required />
                <x-input-error :messages="$errors->get('header_text')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="welcome_message" value="Вітальне повідомлення" />
                <textarea id="welcome_message" 
                          name="welcome_message" 
                          rows="3"
                          class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                          maxlength="500"
                          required>{{ old('welcome_message', $settings->welcome_message ?? 'Привіт! Чим можу допомогти?') }}</textarea>
                <x-input-error :messages="$errors->get('welcome_message')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="position" value="Позиція віджета" />
                <select id="position" 
                        name="position" 
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                    <option value="bottom-right" {{ old('position', $settings->position ?? 'bottom-right') === 'bottom-right' ? 'selected' : '' }}>
                        Внизу справа
                    </option>
                    <option value="bottom-left" {{ old('position', $settings->position ?? '') === 'bottom-left' ? 'selected' : '' }}>
                        Внизу зліва
                    </option>
                </select>
                <x-input-error :messages="$errors->get('position')" class="mt-2" />
            </div>

            @if($storeContext && $storeContext->store_type)
                <div class="p-4 bg-green-50 border border-green-200 rounded-lg">
                    <p class="text-sm text-green-700">
                        <span class="font-medium">🤖 AI визначив тип магазину:</span> 
                        {{ ucfirst($storeContext->store_type) }}
                    </p>
                    <p class="text-xs text-green-600 mt-1">
                        Бот автоматично налаштований для вашої ніші
                    </p>
                </div>
            @endif
        </div>

        <!-- Preview -->
        <div>
            <p class="text-sm font-medium text-gray-700 mb-3">Попередній перегляд</p>
            <div class="bg-gray-100 rounded-xl p-4 h-96 relative">
                <div id="widget-preview" class="absolute bottom-4 right-4 w-80 bg-white rounded-xl shadow-xl overflow-hidden">
                    <!-- Header -->
                    <div id="preview-header" class="px-4 py-3 text-white" style="background-color: {{ $settings->primary_color ?? '#2563EB' }}">
                        <div class="flex items-center justify-between">
                            <span id="preview-title" class="font-medium">{{ $settings->header_text ?? 'AI Асистент' }}</span>
                            <button class="text-white/80 hover:text-white">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <!-- Messages -->
                    <div class="p-4 h-48 overflow-y-auto">
                        <div class="flex mb-3">
                            <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center mr-2">
                                <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                            <div class="bg-gray-100 rounded-lg px-3 py-2 max-w-[80%]">
                                <p id="preview-message" class="text-sm">{{ $settings->welcome_message ?? 'Привіт! Чим можу допомогти?' }}</p>
                            </div>
                        </div>
                    </div>
                    <!-- Input -->
                    <div class="px-4 py-3 border-t">
                        <div class="flex items-center space-x-2">
                            <input type="text" placeholder="Напишіть повідомлення..." class="flex-1 text-sm px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <button id="preview-send" class="p-2 rounded-lg text-white" style="background-color: {{ $settings->primary_color ?? '#2563EB' }}">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="flex justify-between mt-8">
        <a href="{{ route('onboarding.step3') }}" class="inline-flex items-center px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-md text-gray-700">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
            Назад
        </a>
        <x-primary-button>
            Продовжити
            <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
        </x-primary-button>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const colorPicker = document.getElementById('primary_color');
    const colorText = document.getElementById('primary_color_text');
    const headerText = document.getElementById('header_text');
    const welcomeMessage = document.getElementById('welcome_message');
    
    const previewHeader = document.getElementById('preview-header');
    const previewTitle = document.getElementById('preview-title');
    const previewMessage = document.getElementById('preview-message');
    const previewSend = document.getElementById('preview-send');

    // Sync color picker and text input
    colorPicker.addEventListener('input', function() {
        colorText.value = this.value;
        updatePreview();
    });
    
    colorText.addEventListener('input', function() {
        if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
            colorPicker.value = this.value;
            updatePreview();
        }
    });

    headerText.addEventListener('input', updatePreview);
    welcomeMessage.addEventListener('input', updatePreview);

    function updatePreview() {
        previewHeader.style.backgroundColor = colorPicker.value;
        previewSend.style.backgroundColor = colorPicker.value;
        previewTitle.textContent = headerText.value || 'AI Асистент';
        previewMessage.textContent = welcomeMessage.value || 'Привіт! Чим можу допомогти?';
    }
});
</script>

<div x-data="{ 
    toast: { show: false, message: '', type: 'success' },
    showToast(message, type = 'success') {
        this.toast = { show: true, message, type };
        setTimeout(() => this.toast.show = false, 3000);
    }
}" 
@toast.window="showToast($event.detail.message, $event.detail.type)"
class="p-4 sm:p-6 max-w-7xl mx-auto">
    
    {{-- Toast Notification --}}
    <div x-show="toast.show" 
         x-transition 
         :class="toast.type === 'success' ? 'bg-green-500' : (toast.type === 'error' ? 'bg-red-500' : 'bg-blue-500')"
         class="fixed top-4 right-4 text-white px-4 sm:px-6 py-3 rounded-lg shadow-lg z-50 text-sm sm:text-base max-w-xs sm:max-w-none">
        <span x-text="toast.message"></span>
    </div>

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 mb-6">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-gray-900">🎯 Проактивні Тригери</h1>
            <p class="text-gray-600 mt-1 text-sm sm:text-base">Автоматичні повідомлення для залучення відвідувачів</p>
        </div>
        <button wire:click="create" 
                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center justify-center gap-2 text-sm sm:text-base">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            <span>Новий тригер</span>
        </button>
    </div>

    {{-- Info Banner --}}
    <div x-data="{ showHelp: false }" class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
        <div class="flex items-start justify-between">
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-blue-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div class="text-sm text-blue-800">
                    <p><strong>Проактивні тригери</strong> — автоматично показують повідомлення відвідувачам на основі їх поведінки.</p>
                    <p class="mt-1">Використовуйте різні типи тригерів для збільшення конверсії чату.</p>
                </div>
            </div>
            <button @click="showHelp = !showHelp" class="text-blue-600 hover:text-blue-800 text-sm flex items-center gap-1">
                <span x-text="showHelp ? 'Сховати' : 'Детальніше'"></span>
                <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': showHelp }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
        </div>
        
        <div x-show="showHelp" x-collapse class="mt-4 pt-4 border-t border-blue-200 text-sm text-blue-900 space-y-4">
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                @foreach($triggerTypes as $type => $info)
                    <div class="bg-white rounded p-3">
                        <div class="flex items-center gap-2 mb-1">
                            <span>{{ $info['icon'] }}</span>
                            <span class="font-semibold">{{ $info['label'] }}</span>
                        </div>
                        <p class="text-xs text-gray-600">{{ $info['description'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-3 mb-4">
        <select wire:model.live="filterType" class="rounded-lg border-gray-300 text-sm">
            <option value="">Всі типи</option>
            @foreach($triggerTypes as $type => $info)
                <option value="{{ $type }}">{{ $info['icon'] }} {{ $info['label'] }}</option>
            @endforeach
        </select>
        <select wire:model.live="filterStatus" class="rounded-lg border-gray-300 text-sm">
            <option value="">Всі статуси</option>
            <option value="1">Увімкнені</option>
            <option value="0">Вимкнені</option>
        </select>
    </div>

    {{-- Rules Table (Desktop) --}}
    <div class="hidden sm:block bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Назва</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Тип</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Пріоритет</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Статистика</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Статус</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Дії</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($rules as $rule)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900">{{ $rule->name }}</div>
                            <div class="text-xs text-gray-500 truncate max-w-xs" title="{{ $rule->message }}">
                                {{ Str::limit($rule->message, 50) }}
                            </div>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                @switch($rule->trigger_type)
                                    @case('exit_intent') bg-red-100 text-red-800 @break
                                    @case('time_on_page') bg-yellow-100 text-yellow-800 @break
                                    @case('utm_campaign') bg-blue-100 text-blue-800 @break
                                    @case('returning_visitor') bg-green-100 text-green-800 @break
                                    @case('pdp_no_variant') bg-purple-100 text-purple-800 @break
                                    @default bg-gray-100 text-gray-800
                                @endswitch
                            ">
                                {{ $triggerTypes[$rule->trigger_type]['icon'] ?? '❓' }} 
                                {{ $triggerTypes[$rule->trigger_type]['label'] ?? $rule->trigger_type }}
                            </span>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500">
                            {{ $rule->priority }}
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap text-sm">
                            <div class="flex items-center gap-4">
                                <div title="Показано">
                                    <span class="text-gray-400">👁</span>
                                    <span class="text-gray-700">{{ number_format($rule->shown_count) }}</span>
                                </div>
                                <div title="Кліки">
                                    <span class="text-gray-400">👆</span>
                                    <span class="text-gray-700">{{ number_format($rule->clicked_count) }}</span>
                                </div>
                                <div title="CTR" class="text-{{ $rule->ctr >= 5 ? 'green' : ($rule->ctr >= 2 ? 'yellow' : 'gray') }}-600">
                                    {{ $rule->ctr }}%
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap">
                            <button wire:click="toggleEnabled({{ $rule->id }})" 
                                    class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors {{ $rule->is_enabled ? 'bg-green-500' : 'bg-gray-300' }}">
                                <span class="inline-block h-4 w-4 transform rounded-full bg-white transition {{ $rule->is_enabled ? 'translate-x-6' : 'translate-x-1' }}"></span>
                            </button>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <div class="flex justify-end gap-2">
                                <button wire:click="edit({{ $rule->id }})" 
                                        class="text-blue-600 hover:text-blue-900" title="Редагувати">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </button>
                                <button wire:click="duplicate({{ $rule->id }})" 
                                        class="text-gray-600 hover:text-gray-900" title="Дублювати">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/>
                                    </svg>
                                </button>
                                <button wire:click="resetStats({{ $rule->id }})" 
                                        class="text-yellow-600 hover:text-yellow-900" title="Скинути статистику">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                    </svg>
                                </button>
                                <button wire:click="confirmDelete({{ $rule->id }})" 
                                        class="text-red-600 hover:text-red-900" title="Видалити">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                            <p class="mb-2">Немає тригерів</p>
                            <button wire:click="create" class="text-blue-600 hover:text-blue-800">
                                Створити перший тригер
                            </button>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Rules Cards (Mobile) --}}
    <div class="sm:hidden space-y-3">
        @forelse($rules as $rule)
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium 
                                @switch($rule->trigger_type)
                                    @case('exit_intent') bg-red-100 text-red-800 @break
                                    @case('time_on_page') bg-yellow-100 text-yellow-800 @break
                                    @case('utm_campaign') bg-blue-100 text-blue-800 @break
                                    @case('returning_visitor') bg-green-100 text-green-800 @break
                                    @case('pdp_no_variant') bg-purple-100 text-purple-800 @break
                                    @default bg-gray-100 text-gray-800
                                @endswitch
                            ">
                                {{ $triggerTypes[$rule->trigger_type]['icon'] ?? '❓' }} 
                                {{ $triggerTypes[$rule->trigger_type]['label'] ?? $rule->trigger_type }}
                            </span>
                        </div>
                        <h3 class="font-medium text-gray-900 mt-1">{{ $rule->name }}</h3>
                        <p class="text-sm text-gray-500 mt-1">{{ Str::limit($rule->message, 60) }}</p>
                    </div>
                    <button wire:click="toggleEnabled({{ $rule->id }})" 
                            class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors flex-shrink-0 {{ $rule->is_enabled ? 'bg-green-500' : 'bg-gray-300' }}">
                        <span class="inline-block h-4 w-4 transform rounded-full bg-white transition {{ $rule->is_enabled ? 'translate-x-6' : 'translate-x-1' }}"></span>
                    </button>
                </div>
                
                <div class="flex items-center gap-4 text-sm mb-3">
                    <div title="Показано">
                        <span class="text-gray-400">👁</span>
                        <span class="text-gray-700">{{ number_format($rule->shown_count) }}</span>
                    </div>
                    <div title="Кліки">
                        <span class="text-gray-400">👆</span>
                        <span class="text-gray-700">{{ number_format($rule->clicked_count) }}</span>
                    </div>
                    <div title="CTR" class="text-{{ $rule->ctr >= 5 ? 'green' : ($rule->ctr >= 2 ? 'yellow' : 'gray') }}-600 font-medium">
                        CTR: {{ $rule->ctr }}%
                    </div>
                </div>

                <div class="flex justify-end gap-3 border-t pt-3">
                    <button wire:click="edit({{ $rule->id }})" class="text-blue-600 text-sm">Редагувати</button>
                    <button wire:click="duplicate({{ $rule->id }})" class="text-gray-600 text-sm">Дублювати</button>
                    <button wire:click="confirmDelete({{ $rule->id }})" class="text-red-600 text-sm">Видалити</button>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-lg shadow p-8 text-center text-gray-500">
                <p class="mb-2">Немає тригерів</p>
                <button wire:click="create" class="text-blue-600 hover:text-blue-800">
                    Створити перший тригер
                </button>
            </div>
        @endforelse
    </div>

    {{-- Pagination --}}
    <div class="mt-4">
        {{ $rules->links() }}
    </div>

    {{-- Edit/Create Modal --}}
    @if($showModal)
    <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" 
         x-data 
         @keydown.escape.window="$wire.showModal = false"
         wire:click.self="$set('showModal', false)">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            {{-- Modal Header --}}
            <div class="sticky top-0 bg-white border-b px-6 py-4 flex justify-between items-center">
                <h2 class="text-xl font-bold text-gray-900">
                    {{ $editMode ? 'Редагувати тригер' : 'Новий тригер' }}
                </h2>
                <button wire:click="$set('showModal', false)" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            {{-- Modal Body --}}
            <form wire:submit="save" class="p-6 space-y-6">
                {{-- Basic Info --}}
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Назва *</label>
                        <input type="text" wire:model="name" 
                               class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500"
                               placeholder="Назва для ідентифікації">
                        @error('name') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Тип тригера *</label>
                        <select wire:model.live="trigger_type" 
                                class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
                            @foreach($triggerTypes as $type => $info)
                                <option value="{{ $type }}">{{ $info['icon'] }} {{ $info['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Trigger Type Description --}}
                @if(isset($triggerTypes[$trigger_type]))
                <div class="bg-gray-50 rounded-lg p-3 text-sm text-gray-600">
                    <span class="font-medium">{{ $triggerTypes[$trigger_type]['icon'] }} {{ $triggerTypes[$trigger_type]['label'] }}:</span>
                    {{ $triggerTypes[$trigger_type]['description'] }}
                </div>
                @endif

                {{-- Conditions based on trigger type --}}
                <div class="bg-blue-50 rounded-lg p-4">
                    <h3 class="font-medium text-blue-900 mb-3">Умови спрацювання</h3>
                    
                    @switch($trigger_type)
                        @case('exit_intent')
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Тип сторінки</label>
                                    <select wire:model="condition_page_type" class="w-full rounded-lg border-gray-300">
                                        @foreach($pageTypes as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Мін. час на сайті (сек)</label>
                                    <input type="number" wire:model="condition_min_time" min="0" max="300"
                                           class="w-full rounded-lg border-gray-300">
                                </div>
                            </div>
                            @break

                        @case('time_on_page')
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Тип сторінки</label>
                                    <select wire:model="condition_page_type" class="w-full rounded-lg border-gray-300">
                                        @foreach($pageTypes as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Час на сторінці (сек)</label>
                                    <input type="number" wire:model="condition_time_seconds" min="5" max="600"
                                           class="w-full rounded-lg border-gray-300">
                                </div>
                            </div>
                            @break

                        @case('utm_campaign')
                            <div class="grid gap-4 md:grid-cols-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">UTM Source</label>
                                    <input type="text" wire:model="condition_utm_source" 
                                           class="w-full rounded-lg border-gray-300"
                                           placeholder="google, facebook...">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">UTM Medium</label>
                                    <input type="text" wire:model="condition_utm_medium" 
                                           class="w-full rounded-lg border-gray-300"
                                           placeholder="cpc, social...">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">UTM Campaign</label>
                                    <input type="text" wire:model="condition_utm_campaign" 
                                           class="w-full rounded-lg border-gray-300"
                                           placeholder="black_friday...">
                                </div>
                            </div>
                            <div class="mt-3">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Затримка показу (сек)</label>
                                <input type="number" wire:model="condition_delay_seconds" min="0" max="120"
                                       class="w-32 rounded-lg border-gray-300">
                            </div>
                            @break

                        @case('returning_visitor')
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Мін. кількість візитів</label>
                                    <input type="number" wire:model="condition_min_visits" min="2" max="100"
                                           class="w-full rounded-lg border-gray-300">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Затримка показу (сек)</label>
                                    <input type="number" wire:model="condition_delay_seconds" min="0" max="120"
                                           class="w-full rounded-lg border-gray-300">
                                </div>
                            </div>
                            @break

                        @case('pdp_no_variant')
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Таймаут вибору (сек)</label>
                                    <input type="number" wire:model="condition_variant_timeout" min="5" max="120"
                                           class="w-full rounded-lg border-gray-300">
                                    <p class="text-xs text-gray-500 mt-1">Час після якого показати підказку</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Категорія (опційно)</label>
                                    <input type="text" wire:model="condition_category" 
                                           class="w-full rounded-lg border-gray-300"
                                           placeholder="Для конкретної категорії">
                                </div>
                            </div>
                            @break
                    @endswitch
                </div>

                {{-- Message --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Повідомлення *</label>
                    <textarea wire:model="message" rows="3"
                              class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500"
                              placeholder="Текст, який побачить відвідувач"></textarea>
                    @error('message') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                    <p class="text-xs text-gray-500 mt-1">Можна використовувати: @{{category}}, @{{product}}, @{{discount}}</p>
                </div>

                {{-- Button & Icon --}}
                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Текст кнопки *</label>
                        <input type="text" wire:model="button_text" 
                               class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500"
                               placeholder="Почати чат">
                        @error('button_text') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Іконка</label>
                        <input type="text" wire:model="icon" 
                               class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500"
                               placeholder="💬">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Дія</label>
                        <select wire:model="action_type" class="w-full rounded-lg border-gray-300">
                            @foreach($actionTypes as $type => $info)
                                <option value="{{ $type }}">{{ $info['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Action Config (if needed) --}}
                @if($action_type === 'open_chat_with_context')
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Контекст для чату</label>
                    <input type="text" wire:model="action_context" 
                           class="w-full rounded-lg border-gray-300"
                           placeholder="Повідомлення що відправиться в чат">
                </div>
                @endif

                @if($action_type === 'show_products')
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Тип товарів</label>
                    <input type="text" wire:model="action_product_type" 
                           class="w-full rounded-lg border-gray-300"
                           placeholder="bestsellers, new, sale...">
                </div>
                @endif

                {{-- Limits --}}
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="font-medium text-gray-900 mb-3">Обмеження</h3>
                    <div class="grid gap-4 md:grid-cols-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Пріоритет</label>
                            <input type="number" wire:model="priority" min="0" max="100"
                                   class="w-full rounded-lg border-gray-300">
                            <p class="text-xs text-gray-500 mt-1">Менше = вищий</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Макс. за сесію</label>
                            <input type="number" wire:model="max_per_session" min="1" max="10"
                                   class="w-full rounded-lg border-gray-300">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Макс. за день</label>
                            <input type="number" wire:model="max_per_day" min="1" max="20"
                                   class="w-full rounded-lg border-gray-300">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cooldown (хв)</label>
                            <input type="number" wire:model="cooldown_minutes" min="0" max="1440"
                                   class="w-full rounded-lg border-gray-300">
                        </div>
                    </div>
                </div>

                {{-- Status --}}
                <div class="flex items-center gap-3">
                    <button type="button" wire:click="$toggle('is_enabled')"
                            class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors {{ $is_enabled ? 'bg-green-500' : 'bg-gray-300' }}">
                        <span class="inline-block h-4 w-4 transform rounded-full bg-white transition {{ $is_enabled ? 'translate-x-6' : 'translate-x-1' }}"></span>
                    </button>
                    <span class="text-sm text-gray-700">{{ $is_enabled ? 'Тригер увімкнено' : 'Тригер вимкнено' }}</span>
                </div>

                {{-- Modal Footer --}}
                <div class="flex justify-end gap-3 pt-4 border-t">
                    <button type="button" wire:click="$set('showModal', false)"
                            class="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg">
                        Скасувати
                    </button>
                    <button type="submit"
                            class="px-4 py-2 text-white bg-blue-600 hover:bg-blue-700 rounded-lg flex items-center gap-2">
                        <svg wire:loading wire:target="save" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span wire:loading.remove wire:target="save">{{ $editMode ? 'Зберегти' : 'Створити' }}</span>
                        <span wire:loading wire:target="save">Збереження...</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif

    {{-- Delete Confirmation Modal --}}
    @if($showDeleteConfirm)
    <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md p-6">
            <div class="text-center">
                <div class="w-12 h-12 rounded-full bg-red-100 mx-auto mb-4 flex items-center justify-center">
                    <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Видалити тригер?</h3>
                <p class="text-gray-500 mb-6">Цю дію неможливо скасувати. Всі дані тригера будуть втрачені.</p>
                <div class="flex justify-center gap-3">
                    <button wire:click="$set('showDeleteConfirm', false)"
                            class="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg">
                        Скасувати
                    </button>
                    <button wire:click="delete"
                            class="px-4 py-2 text-white bg-red-600 hover:bg-red-700 rounded-lg">
                        Видалити
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>

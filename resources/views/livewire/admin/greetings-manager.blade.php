<div x-data="{ 
    toast: { show: false, message: '', type: 'success' },
    showToast(message, type = 'success') {
        this.toast = { show: true, message, type };
        setTimeout(() => this.toast.show = false, 3000);
    }
}" 
@toast.window="showToast($event.detail.message, $event.detail.type)"
class="p-6 max-w-7xl mx-auto">
    
    {{-- Toast Notification --}}
    <div x-show="toast.show" 
         x-transition 
         :class="toast.type === 'success' ? 'bg-green-500' : 'bg-red-500'"
         class="fixed top-4 right-4 text-white px-6 py-3 rounded-lg shadow-lg z-50">
        <span x-text="toast.message"></span>
    </div>

    {{-- Header --}}
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">🎯 Привітання</h1>
            <p class="text-gray-600 mt-1">Динамічні привітання для різних умов (UTM, категорії, пристрої)</p>
        </div>
        <button wire:click="create" 
                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Нове привітання
        </button>
    </div>

    {{-- Greetings Table --}}
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Назва</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Умови</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Пріоритет</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Статус</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Дії</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($greetings as $greeting)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-4">
                            <div class="flex items-center gap-2">
                                @if($greeting->is_default)
                                    <span class="px-2 py-0.5 text-xs bg-yellow-100 text-yellow-800 rounded-full">За замовчуванням</span>
                                @endif
                                <span class="font-medium text-gray-900">{{ $greeting->name }}</span>
                            </div>
                            <p class="text-sm text-gray-500 mt-1 truncate max-w-xs">{{ Str::limit($greeting->message, 60) }}</p>
                        </td>
                        <td class="px-4 py-4">
                            <div class="flex flex-wrap gap-1">
                                @if($greeting->utm_campaign)
                                    <span class="px-2 py-0.5 text-xs bg-purple-100 text-purple-800 rounded">utm:{{ $greeting->utm_campaign }}</span>
                                @endif
                                @if($greeting->utm_source)
                                    <span class="px-2 py-0.5 text-xs bg-purple-100 text-purple-800 rounded">src:{{ $greeting->utm_source }}</span>
                                @endif
                                @if($greeting->category_path)
                                    <span class="px-2 py-0.5 text-xs bg-blue-100 text-blue-800 rounded">cat:{{ Str::limit($greeting->category_path, 15) }}</span>
                                @endif
                                @if($greeting->device !== 'any')
                                    <span class="px-2 py-0.5 text-xs bg-gray-100 text-gray-800 rounded">{{ $greeting->device }}</span>
                                @endif
                                @if($greeting->visitor_type !== 'any')
                                    <span class="px-2 py-0.5 text-xs bg-green-100 text-green-800 rounded">{{ $greeting->visitor_type === 'new' ? 'новий' : 'поверн.' }}</span>
                                @endif
                                @if($greeting->time_range)
                                    <span class="px-2 py-0.5 text-xs bg-orange-100 text-orange-800 rounded">{{ $greeting->time_range['start'] }}-{{ $greeting->time_range['end'] }}</span>
                                @endif
                                @if(!$greeting->utm_campaign && !$greeting->utm_source && !$greeting->category_path && $greeting->device === 'any' && $greeting->visitor_type === 'any' && !$greeting->time_range && !$greeting->is_default)
                                    <span class="text-xs text-gray-400">Без умов</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-4">
                            <span class="px-2 py-1 text-sm bg-gray-100 rounded">{{ $greeting->priority }}</span>
                        </td>
                        <td class="px-4 py-4">
                            <button wire:click="toggleActive({{ $greeting->id }})" 
                                    class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors {{ $greeting->is_active ? 'bg-green-500' : 'bg-gray-300' }}">
                                <span class="inline-block h-4 w-4 transform rounded-full bg-white transition {{ $greeting->is_active ? 'translate-x-6' : 'translate-x-1' }}"></span>
                            </button>
                        </td>
                        <td class="px-4 py-4 text-right">
                            <div class="flex justify-end gap-2">
                                <button wire:click="edit({{ $greeting->id }})" 
                                        class="p-2 text-gray-600 hover:text-blue-600 hover:bg-blue-50 rounded">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </button>
                                <button wire:click="duplicate({{ $greeting->id }})" 
                                        class="p-2 text-gray-600 hover:text-green-600 hover:bg-green-50 rounded"
                                        title="Дублювати">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                    </svg>
                                </button>
                                <button wire:click="delete({{ $greeting->id }})" 
                                        wire:confirm="Видалити це привітання?"
                                        class="p-2 text-gray-600 hover:text-red-600 hover:bg-red-50 rounded">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-12 text-center text-gray-500">
                            <div class="flex flex-col items-center gap-4">
                                <svg class="w-12 h-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
                                </svg>
                                <p>Поки що немає привітань</p>
                                <button wire:click="create" class="text-blue-600 hover:underline">
                                    Створити перше привітання
                                </button>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        
        @if($greetings->hasPages())
            <div class="px-4 py-3 border-t">
                {{ $greetings->links() }}
            </div>
        @endif
    </div>

    {{-- Modal --}}
    @if($showModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
            <div class="flex items-center justify-center min-h-screen p-4">
                <div wire:click="$set('showModal', false)" class="fixed inset-0 bg-black bg-opacity-50 transition-opacity"></div>
                
                <div class="relative bg-white rounded-xl shadow-xl max-w-3xl w-full max-h-[90vh] overflow-y-auto">
                    {{-- Header --}}
                    <div class="sticky top-0 bg-white px-6 py-4 border-b flex justify-between items-center">
                        <h2 class="text-xl font-semibold">
                            {{ $editMode ? 'Редагувати привітання' : 'Нове привітання' }}
                        </h2>
                        <button wire:click="$set('showModal', false)" class="p-2 hover:bg-gray-100 rounded-full">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <div class="p-6 space-y-6">
                        {{-- Basic Info --}}
                        <div class="space-y-4">
                            <h3 class="font-medium text-gray-900 border-b pb-2">📝 Основне</h3>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Назва (для адмінки)</label>
                                <input type="text" wire:model="name" 
                                       class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
                                       placeholder="Наприклад: Привітання для рекламної кампанії">
                                @error('name') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Текст привітання</label>
                                <textarea wire:model="message" rows="3"
                                          class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
                                          placeholder="Вітаю! 👋 Чим можу допомогти?"></textarea>
                                @error('message') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        {{-- Quick Actions --}}
                        <div class="space-y-4">
                            <h3 class="font-medium text-gray-900 border-b pb-2">⚡ Швидкі дії</h3>
                            
                            @if(count($quick_actions) > 0)
                                <div class="space-y-2">
                                    @foreach($quick_actions as $index => $action)
                                        <div class="flex items-center gap-2 bg-gray-50 p-2 rounded">
                                            <span class="flex-1 text-sm">
                                                <span class="font-medium">{{ $action['label'] }}</span>
                                                <span class="text-gray-500">→ {{ $action['query'] }}</span>
                                            </span>
                                            <button wire:click="removeQuickAction({{ $index }})" 
                                                    class="p-1 text-red-500 hover:bg-red-50 rounded">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                </svg>
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                            
                            <div class="flex gap-2">
                                <input type="text" wire:model="newActionLabel" 
                                       class="flex-1 px-3 py-2 border rounded-lg text-sm"
                                       placeholder="Напис на кнопці">
                                <input type="text" wire:model="newActionQuery" 
                                       class="flex-1 px-3 py-2 border rounded-lg text-sm"
                                       placeholder="Запит при кліку">
                                <button wire:click="addQuickAction" 
                                        class="px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm">
                                    + Додати
                                </button>
                            </div>
                        </div>

                        {{-- Conditions --}}
                        <div class="space-y-4">
                            <h3 class="font-medium text-gray-900 border-b pb-2">🎯 Умови показу</h3>
                            <p class="text-sm text-gray-500">Залиште поле пустим, щоб ігнорувати цю умову</p>
                            
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">UTM Campaign</label>
                                    <input type="text" wire:model="utm_campaign" 
                                           class="w-full px-3 py-2 border rounded-lg text-sm"
                                           placeholder="black_friday">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">UTM Source</label>
                                    <input type="text" wire:model="utm_source" 
                                           class="w-full px-3 py-2 border rounded-lg text-sm"
                                           placeholder="facebook">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">UTM Medium</label>
                                    <input type="text" wire:model="utm_medium" 
                                           class="w-full px-3 py-2 border rounded-lg text-sm"
                                           placeholder="cpc">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">URL містить</label>
                                    <input type="text" wire:model="url_contains" 
                                           class="w-full px-3 py-2 border rounded-lg text-sm"
                                           placeholder="/sale/">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Категорія</label>
                                    <input type="text" wire:model="category_path" 
                                           class="w-full px-3 py-2 border rounded-lg text-sm"
                                           placeholder="plate-carriers">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Пристрій</label>
                                    <select wire:model="device" class="w-full px-3 py-2 border rounded-lg text-sm">
                                        <option value="any">Будь-який</option>
                                        <option value="mobile">Мобільний</option>
                                        <option value="desktop">Десктоп</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Тип відвідувача</label>
                                    <select wire:model="visitor_type" class="w-full px-3 py-2 border rounded-lg text-sm">
                                        <option value="any">Будь-який</option>
                                        <option value="new">Новий</option>
                                        <option value="returning">Повертається</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Мова браузера</label>
                                    <input type="text" wire:model="language" 
                                           class="w-full px-3 py-2 border rounded-lg text-sm"
                                           placeholder="uk, en, pl">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Час з</label>
                                    <input type="time" wire:model="time_start" 
                                           class="w-full px-3 py-2 border rounded-lg text-sm">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Час до</label>
                                    <input type="time" wire:model="time_end" 
                                           class="w-full px-3 py-2 border rounded-lg text-sm">
                                </div>
                            </div>
                        </div>

                        {{-- Settings --}}
                        <div class="space-y-4">
                            <h3 class="font-medium text-gray-900 border-b pb-2">⚙️ Налаштування</h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Пріоритет</label>
                                    <input type="number" wire:model="priority" min="0" max="1000"
                                           class="w-full px-3 py-2 border rounded-lg text-sm">
                                    <p class="text-xs text-gray-500 mt-1">Вищий = перевіряється раніше</p>
                                </div>
                                <div class="flex items-center gap-3 pt-6">
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" wire:model="is_active" class="sr-only peer">
                                        <div class="w-11 h-6 bg-gray-200 peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                        <span class="ml-3 text-sm font-medium text-gray-700">Активне</span>
                                    </label>
                                </div>
                                <div class="flex items-center gap-3 pt-6">
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" wire:model="is_default" class="sr-only peer">
                                        <div class="w-11 h-6 bg-gray-200 peer-focus:ring-4 peer-focus:ring-yellow-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-yellow-500"></div>
                                        <span class="ml-3 text-sm font-medium text-gray-700">За замовчуванням</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Footer --}}
                    <div class="sticky bottom-0 bg-gray-50 px-6 py-4 border-t flex justify-end gap-3">
                        <button wire:click="$set('showModal', false)" 
                                class="px-4 py-2 border rounded-lg hover:bg-gray-100">
                            Скасувати
                        </button>
                        <button wire:click="save" 
                                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            {{ $editMode ? 'Зберегти' : 'Створити' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

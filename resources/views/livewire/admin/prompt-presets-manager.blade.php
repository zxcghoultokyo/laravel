<div x-data="{ 
    toast: { show: false, message: '', type: 'success' },
    showToast(message, type = 'success') {
        this.toast = { show: true, message, type };
        setTimeout(() => this.toast.show = false, 3000);
    }
}" 
@toast.window="showToast($event.detail.message, $event.detail.type)"
@download.window="
    const blob = new Blob([$event.detail.content], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = $event.detail.filename;
    a.click();
    URL.revokeObjectURL(url);
"
class="p-6 max-w-7xl mx-auto">
    
    {{-- Toast Notification --}}
    <div x-show="toast.show" 
         x-transition 
         :class="toast.type === 'success' ? 'bg-green-500' : (toast.type === 'error' ? 'bg-red-500' : 'bg-blue-500')"
         class="fixed top-4 right-4 text-white px-6 py-3 rounded-lg shadow-lg z-50">
        <span x-text="toast.message"></span>
    </div>

    {{-- Header --}}
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">📝 Промпт Пресети</h1>
            <p class="text-gray-600 mt-1">Кастомні системні промпти з підтримкою змінних</p>
        </div>
        <button wire:click="create" 
                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Новий пресет
        </button>
    </div>

    {{-- Info Banner --}}
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
        <div class="flex items-start gap-3">
            <svg class="w-5 h-5 text-blue-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div class="text-sm text-blue-800">
                <p><strong>Змінні:</strong> Використовуйте <code class="bg-blue-100 px-1 rounded">&#123;&#123;variable_name&#125;&#125;</code> для динамічних значень.</p>
                <p class="mt-1"><strong>Умови:</strong> Пресет застосовується автоматично при збігу категорії, мови, тону або UTM-кампанії.</p>
            </div>
        </div>
    </div>

    {{-- Presets Table --}}
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Назва</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Умови</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Змінні</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Пріоритет</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Статус</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Дії</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($presets as $preset)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-4">
                            <div class="flex items-center gap-2">
                                @if($preset->is_default)
                                    <span class="px-2 py-0.5 text-xs bg-yellow-100 text-yellow-800 rounded-full">Default</span>
                                @endif
                                <span class="font-medium text-gray-900">{{ $preset->name }}</span>
                            </div>
                            @if($preset->description)
                                <p class="text-sm text-gray-500 mt-1 truncate max-w-xs">{{ $preset->description }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-4">
                            <div class="flex flex-wrap gap-1">
                                @if($preset->language)
                                    <span class="px-2 py-0.5 text-xs bg-green-100 text-green-800 rounded">{{ strtoupper($preset->language) }}</span>
                                @endif
                                @if($preset->tone)
                                    <span class="px-2 py-0.5 text-xs bg-purple-100 text-purple-800 rounded">{{ $preset->tone }}</span>
                                @endif
                                @if($preset->campaign)
                                    <span class="px-2 py-0.5 text-xs bg-orange-100 text-orange-800 rounded">utm:{{ $preset->campaign }}</span>
                                @endif
                                @if(!empty($preset->categories))
                                    @foreach(array_slice($preset->categories, 0, 2) as $cat)
                                        <span class="px-2 py-0.5 text-xs bg-blue-100 text-blue-800 rounded">{{ $cat }}</span>
                                    @endforeach
                                    @if(count($preset->categories) > 2)
                                        <span class="px-2 py-0.5 text-xs bg-gray-100 text-gray-600 rounded">+{{ count($preset->categories) - 2 }}</span>
                                    @endif
                                @endif
                                @if(!$preset->language && !$preset->tone && !$preset->campaign && empty($preset->categories))
                                    <span class="text-xs text-gray-400">Без умов</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-4">
                            @if(!empty($preset->variables))
                                <div class="flex flex-wrap gap-1">
                                    @foreach(array_slice($preset->variables, 0, 3) as $var)
                                        <span class="px-2 py-0.5 text-xs bg-gray-100 text-gray-700 rounded font-mono">@{{ '{{' . $var['name'] . '}}' }}</span>
                                    @endforeach
                                    @if(count($preset->variables) > 3)
                                        <span class="px-2 py-0.5 text-xs bg-gray-100 text-gray-600 rounded">+{{ count($preset->variables) - 3 }}</span>
                                    @endif
                                </div>
                            @else
                                <span class="text-xs text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-4">
                            <span class="px-2 py-1 text-sm bg-gray-100 rounded">{{ $preset->priority }}</span>
                        </td>
                        <td class="px-4 py-4">
                            <button wire:click="toggleActive({{ $preset->id }})" 
                                    class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors {{ $preset->is_active ? 'bg-green-500' : 'bg-gray-300' }}">
                                <span class="inline-block h-4 w-4 transform rounded-full bg-white transition {{ $preset->is_active ? 'translate-x-6' : 'translate-x-1' }}"></span>
                            </button>
                        </td>
                        <td class="px-4 py-4 text-right">
                            <div class="flex justify-end gap-1">
                                <button wire:click="openTestModal({{ $preset->id }})" 
                                        class="p-2 text-gray-600 hover:text-green-600 hover:bg-green-50 rounded" title="Тест">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                </button>
                                <button wire:click="edit({{ $preset->id }})" 
                                        class="p-2 text-gray-600 hover:text-blue-600 hover:bg-blue-50 rounded" title="Редагувати">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </button>
                                <button wire:click="duplicate({{ $preset->id }})" 
                                        class="p-2 text-gray-600 hover:text-purple-600 hover:bg-purple-50 rounded" title="Копіювати">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                    </svg>
                                </button>
                                <button wire:click="exportPreset({{ $preset->id }})" 
                                        class="p-2 text-gray-600 hover:text-orange-600 hover:bg-orange-50 rounded" title="Експорт">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                    </svg>
                                </button>
                                @unless($preset->is_default)
                                    <button wire:click="delete({{ $preset->id }})" 
                                            wire:confirm="Видалити пресет '{{ $preset->name }}'?"
                                            class="p-2 text-gray-600 hover:text-red-600 hover:bg-red-50 rounded" title="Видалити">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                    </button>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                            <div class="flex flex-col items-center gap-2">
                                <svg class="w-12 h-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                                <p>Ще немає пресетів</p>
                                <button wire:click="create" class="text-blue-600 hover:underline">Створити перший</button>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($presets->hasPages())
            <div class="px-4 py-3 border-t border-gray-200">
                {{ $presets->links() }}
            </div>
        @endif
    </div>

    {{-- Create/Edit Modal --}}
    @if($showModal)
        <div class="fixed inset-0 bg-black/50 z-40 flex items-start justify-center pt-10 overflow-y-auto">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl mx-4 my-8" @click.outside="$wire.showModal = false">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-900">
                        {{ $editMode ? 'Редагувати пресет' : 'Новий пресет' }}
                    </h2>
                    <button wire:click="$set('showModal', false)" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="p-6 space-y-6 max-h-[70vh] overflow-y-auto">
                    {{-- Basic Info --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Назва *</label>
                            <input type="text" wire:model="name" 
                                   class="w-full rounded-lg border-gray-300"
                                   placeholder="Fashion UA Official">
                            @error('name') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Пріоритет</label>
                            <input type="number" wire:model="priority" min="0" max="1000"
                                   class="w-full rounded-lg border-gray-300">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Опис</label>
                        <input type="text" wire:model="description" 
                               class="w-full rounded-lg border-gray-300"
                               placeholder="Пресет для категорії одягу, офіційний тон">
                    </div>

                    {{-- System Prompt --}}
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="block text-sm font-medium text-gray-700">Системний промпт *</label>
                            <button wire:click="extractVariablesFromPrompt" type="button"
                                    class="text-sm text-blue-600 hover:underline">
                                Знайти змінні
                            </button>
                        </div>
                        <textarea wire:model="system_prompt" rows="12"
                                  class="w-full rounded-lg border-gray-300 font-mono text-sm"
                                  placeholder="Ти — AI-продавець магазину ..."></textarea>
                        @error('system_prompt') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        <p class="text-xs text-gray-500 mt-1">Використовуйте &#123;&#123;variable_name&#125;&#125; для динамічних значень</p>
                    </div>

                    {{-- Variables --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Змінні</label>
                        <div class="space-y-2">
                            @foreach($variables as $index => $var)
                                <div class="flex items-center gap-2 p-2 bg-gray-50 rounded">
                                    <code class="text-sm text-purple-600">@verbatim{{@endverbatim{{ $var['name'] }}@verbatim}}@endverbatim</code>
                                    <span class="text-gray-400">=</span>
                                    <input type="text" wire:model="variables.{{ $index }}.default"
                                           class="flex-1 text-sm rounded border-gray-300"
                                           placeholder="Значення за замовчуванням">
                                    <button wire:click="removeVariable({{ $index }})" type="button"
                                            class="p-1 text-red-500 hover:bg-red-50 rounded">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                        <div class="flex gap-2 mt-2">
                            <input type="text" wire:model="newVarName" 
                                   class="flex-1 text-sm rounded-lg border-gray-300"
                                   placeholder="Назва змінної (напр. brand_name)">
                            <input type="text" wire:model="newVarDefault"
                                   class="flex-1 text-sm rounded-lg border-gray-300"
                                   placeholder="Значення за замовчуванням">
                            <button wire:click="addVariable" type="button"
                                    class="px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm">
                                Додати
                            </button>
                        </div>
                    </div>

                    {{-- Conditions --}}
                    <div class="border-t border-gray-200 pt-4">
                        <h3 class="text-sm font-medium text-gray-700 mb-3">Умови застосування</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Мова</label>
                                <select wire:model="language" 
                                        class="w-full rounded-lg border-gray-300 text-sm">
                                    @foreach($languages as $code => $label)
                                        <option value="{{ $code }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Тон</label>
                                <select wire:model="tone"
                                        class="w-full rounded-lg border-gray-300 text-sm">
                                    @foreach($tones as $code => $label)
                                        <option value="{{ $code }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">UTM Campaign</label>
                                <input type="text" wire:model="campaign"
                                       class="w-full rounded-lg border-gray-300 text-sm"
                                       placeholder="black_friday">
                            </div>
                        </div>

                        {{-- Categories --}}
                        <div class="mt-4">
                            <label class="block text-xs text-gray-500 mb-1">Категорії</label>
                            <div class="flex flex-wrap gap-1 mb-2">
                                @foreach($categories as $index => $cat)
                                    <span class="inline-flex items-center gap-1 px-2 py-1 bg-blue-100 text-blue-800 rounded text-sm">
                                        {{ $cat }}
                                        <button wire:click="removeCategory({{ $index }})" type="button" class="hover:text-blue-600">×</button>
                                    </span>
                                @endforeach
                            </div>
                            <div class="flex gap-2">
                                <input type="text" wire:model="newCategory"
                                       class="flex-1 text-sm rounded-lg border-gray-300"
                                       placeholder="Одяг, Взуття, Аксесуари...">
                                <button wire:click="addCategory" type="button"
                                        class="px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm">
                                    Додати
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- Meta --}}
                    <div class="flex items-center gap-6 pt-4 border-t border-gray-200">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="is_active" class="rounded border-gray-300 text-blue-600">
                            <span class="text-sm text-gray-700">Активний</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="is_default" class="rounded border-gray-300 text-blue-600">
                            <span class="text-sm text-gray-700">За замовчуванням</span>
                        </label>
                    </div>
                </div>

                <div class="flex justify-end gap-3 px-6 py-4 border-t border-gray-200 bg-gray-50 rounded-b-lg">
                    <button wire:click="$set('showModal', false)" 
                            class="px-4 py-2 text-gray-700 hover:bg-gray-200 rounded-lg">
                        Скасувати
                    </button>
                    <button wire:click="save" 
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                        {{ $editMode ? 'Зберегти' : 'Створити' }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Test Modal --}}
    @if($showTestModal)
        <div class="fixed inset-0 bg-black/50 z-40 flex items-center justify-center">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl mx-4" @click.outside="$wire.showTestModal = false">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-900">🧪 Тест пресету</h2>
                    <button wire:click="$set('showTestModal', false)" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Тестове повідомлення</label>
                        <div class="flex gap-2">
                            <input type="text" wire:model="testMessage" 
                                   wire:keydown.enter="testPreset"
                                   class="flex-1 rounded-lg border-gray-300"
                                   placeholder="Покажи зимові куртки до 5000 грн">
                            <button wire:click="testPreset" 
                                    wire:loading.attr="disabled"
                                    class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg disabled:opacity-50">
                                <span wire:loading.remove wire:target="testPreset">Тест</span>
                                <span wire:loading wire:target="testPreset">...</span>
                            </button>
                        </div>
                    </div>

                    @if($testResponse)
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Відповідь AI</label>
                            <div class="p-4 bg-gray-50 rounded-lg">
                                <p class="text-gray-800 whitespace-pre-wrap">{{ $testResponse }}</p>
                            </div>
                        </div>
                    @endif

                    <div class="text-xs text-gray-500">
                        Тест використовує рендерений промпт з дефолтними значеннями змінних. Результати можуть відрізнятися від production.
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

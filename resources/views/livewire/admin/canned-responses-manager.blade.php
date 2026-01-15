<div>
    @section('title', 'Шаблони відповідей')

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Шаблони відповідей</h1>
            <p class="text-gray-600">Швидкі відповіді для операторів</p>
        </div>
        <div class="flex gap-3">
            <button wire:click="seedDefaults" wire:confirm="Створити базові шаблони?" class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                Базові шаблони
            </button>
            <button wire:click="openModal()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Додати
            </button>
        </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm p-4 border border-gray-100">
            <div class="text-2xl font-bold text-gray-900">{{ $stats['total'] }}</div>
            <div class="text-sm text-gray-500">Всього шаблонів</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 border border-green-100">
            <div class="text-2xl font-bold text-green-600">{{ $stats['active'] }}</div>
            <div class="text-sm text-gray-500">Активних</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 border border-blue-100">
            <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['total_usage']) }}</div>
            <div class="text-sm text-gray-500">Використань</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-xl shadow-sm p-4 mb-6 border border-gray-100">
        <div class="flex flex-wrap gap-4 items-center">
            <div class="flex-1 min-w-[200px]">
                <input type="text" 
                       wire:model.live.debounce.300ms="search"
                       placeholder="Пошук по назві, шорткату або тексту..."
                       class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <select wire:model.live="categoryFilter" class="px-4 py-2 border border-gray-200 rounded-lg">
                <option value="">Всі категорії</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat['key'] }}">{{ $cat['icon'] }} {{ $cat['label'] }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" wire:model.live="showInactive" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                <span class="text-sm text-gray-600">Показати неактивні</span>
            </label>
        </div>
    </div>

    <!-- Flash Messages -->
    @if(session()->has('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 text-green-700 rounded-lg">
            {{ session('success') }}
        </div>
    @endif
    @if(session()->has('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg">
            {{ session('error') }}
        </div>
    @endif

    <!-- Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @forelse($responses as $response)
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 hover:shadow-md transition {{ !$response->is_active ? 'opacity-60' : '' }}">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <h3 class="font-semibold text-gray-900">{{ $response->title }}</h3>
                        @if($response->shortcut)
                            <code class="text-xs bg-gray-100 text-blue-600 px-2 py-0.5 rounded">/{{ $response->shortcut }}</code>
                        @endif
                    </div>
                    <span class="text-xs px-2 py-1 rounded-full bg-gray-100 text-gray-600">
                        {{ collect($categories)->firstWhere('key', $response->category)['icon'] ?? '📝' }}
                        {{ collect($categories)->firstWhere('key', $response->category)['label'] ?? 'Інше' }}
                    </span>
                </div>
                
                <p class="text-sm text-gray-600 mb-3 line-clamp-3">
                    {{ $response->content }}
                </p>

                <div class="flex items-center justify-between pt-3 border-t border-gray-100">
                    <span class="text-xs text-gray-500">
                        📊 {{ $response->usage_count }} використань
                    </span>
                    <div class="flex items-center gap-1">
                        <button wire:click="toggleActive({{ $response->id }})" 
                                class="p-1.5 rounded hover:bg-gray-100 transition {{ $response->is_active ? 'text-green-600' : 'text-gray-400' }}"
                                title="{{ $response->is_active ? 'Деактивувати' : 'Активувати' }}">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $response->is_active ? 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' : 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z' }}"/>
                            </svg>
                        </button>
                        <button wire:click="openModal({{ $response->id }})" class="p-1.5 text-gray-600 hover:text-blue-600 hover:bg-blue-50 rounded transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        </button>
                        <button wire:click="delete({{ $response->id }})" wire:confirm="Видалити цей шаблон?" class="p-1.5 text-gray-600 hover:text-red-600 hover:bg-red-50 rounded transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-full text-center py-12 text-gray-500">
                <svg class="w-12 h-12 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                <p>Шаблони не знайдено</p>
                <button wire:click="openModal()" class="mt-4 text-blue-600 hover:underline">Створити перший шаблон</button>
            </div>
        @endforelse
    </div>

    <div class="mt-6">
        {{ $responses->links() }}
    </div>

    <!-- Modal -->
    @if($showModal)
        <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center" wire:click.self="closeModal">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl mx-4 max-h-[90vh] overflow-y-auto">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-xl font-bold">{{ $editingId ? 'Редагування шаблону' : 'Новий шаблон' }}</h2>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Назва *</label>
                        <input type="text" wire:model="form.title" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="Привітання клієнта">
                        @error('form.title') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Шорткат</label>
                            <div class="flex">
                                <span class="inline-flex items-center px-3 bg-gray-100 border border-r-0 border-gray-200 rounded-l-lg text-gray-500">/</span>
                                <input type="text" wire:model="form.shortcut" class="flex-1 px-3 py-2 border border-gray-200 rounded-r-lg focus:ring-2 focus:ring-blue-500" placeholder="hi">
                            </div>
                            @error('form.shortcut') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            <p class="text-xs text-gray-500 mt-1">Тільки латинські літери, цифри, - та _</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Категорія</label>
                            <select wire:model="form.category" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">Оберіть категорію</option>
                                @foreach($categories as $cat)
                                    <option value="{{ $cat['key'] }}">{{ $cat['icon'] }} {{ $cat['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Текст відповіді *</label>
                        <textarea wire:model="form.content" rows="6" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="Вітаю! 👋 Чим можу допомогти?"></textarea>
                        @error('form.content') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        <p class="text-xs text-gray-500 mt-1">
                            Використовуйте змінні: <code class="bg-gray-100 px-1 rounded">@{{customer_name}}</code>, <code class="bg-gray-100 px-1 rounded">@{{order_id}}</code>, <code class="bg-gray-100 px-1 rounded">@{{ttn}}</code>
                        </p>
                    </div>

                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model="form.is_active" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="text-sm text-gray-700">Активний</span>
                    </label>
                </div>
                <div class="p-6 border-t border-gray-200 flex justify-end gap-3">
                    <button wire:click="closeModal" class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition">
                        Скасувати
                    </button>
                    <button wire:click="save" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                        {{ $editingId ? 'Зберегти' : 'Створити' }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

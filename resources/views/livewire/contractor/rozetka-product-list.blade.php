<div>
    {{-- Stats bar --}}
    <div class="mb-4 flex flex-wrap items-center gap-4">
        <div class="bg-white rounded-lg shadow-sm px-4 py-3 flex items-center gap-2">
            <span class="text-2xl font-bold text-gray-800">{{ $totalProducts }}</span>
            <span class="text-sm text-gray-500">товарів</span>
        </div>
        <div class="bg-white rounded-lg shadow-sm px-4 py-3 flex items-center gap-2">
            <span class="text-2xl font-bold text-emerald-600">{{ $inStockCount }}</span>
            <span class="text-sm text-gray-500">в наявності</span>
        </div>
        <div class="bg-white rounded-lg shadow-sm px-4 py-3 flex items-center gap-2">
            <span class="text-2xl font-bold text-blue-600">{{ $withCategoryCount }}</span>
            <span class="text-sm text-gray-500">з категорією</span>
        </div>

        <div class="ml-auto">
            <button wire:click="syncProducts" wire:loading.attr="disabled"
                    class="bg-emerald-600 text-white px-4 py-2 rounded-md hover:bg-emerald-700 transition text-sm font-medium disabled:opacity-50">
                <span wire:loading.remove wire:target="syncProducts">🔄 Синхронізувати</span>
                <span wire:loading wire:target="syncProducts">⏳ Завантаження...</span>
            </button>
        </div>
    </div>

    @if ($syncMessage)
        <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded text-sm text-blue-700">
            {{ $syncMessage }}
        </div>
    @endif

    {{-- Filters --}}
    <div class="mb-4 bg-white rounded-lg shadow-sm p-4 flex flex-wrap items-center gap-4">
        <div class="flex-1 min-w-[200px]">
            <input type="text" wire:model.live.debounce.300ms="search"
                   placeholder="Пошук за назвою або артикулом..."
                   class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
        </div>
        <select wire:model.live="stockFilter"
                class="px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
            <option value="">Всі товари</option>
            <option value="in_stock">В наявності</option>
            <option value="out_of_stock">Немає в наявності</option>
        </select>
    </div>

    {{-- Product list --}}
    <div class="space-y-2">
        @forelse ($products as $product)
            <div class="bg-white rounded-lg shadow-sm overflow-hidden">
                {{-- Product row --}}
                <div wire:click="toggleProduct({{ $product->id }})"
                     class="flex items-center gap-4 px-4 py-3 cursor-pointer hover:bg-gray-50 transition">
                    {{-- Photo --}}
                    <div class="w-12 h-12 rounded overflow-hidden bg-gray-100 flex-shrink-0">
                        @if ($product->first_photo)
                            <img src="{{ $product->first_photo }}" alt="" class="w-full h-full object-cover">
                        @else
                            <div class="w-full h-full flex items-center justify-center text-gray-400 text-xs">📷</div>
                        @endif
                    </div>

                    {{-- Title + Article --}}
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium text-gray-800 truncate">{{ $product->title }}</div>
                        <div class="text-xs text-gray-500">{{ $product->article }}</div>
                    </div>

                    {{-- Category --}}
                    <div class="hidden md:block text-xs text-gray-500 max-w-[200px] truncate">
                        {{ $product->rozetka_category_name ?? '—' }}
                    </div>

                    {{-- Price --}}
                    <div class="text-sm font-medium text-gray-700 w-20 text-right">
                        {{ number_format($product->price, 0, '.', ' ') }} ₴
                    </div>

                    {{-- Stock status --}}
                    <div class="w-20 text-right">
                        @if ($product->in_stock)
                            <span class="inline-block px-2 py-0.5 text-xs rounded-full bg-emerald-100 text-emerald-700">В наявності</span>
                        @else
                            <span class="inline-block px-2 py-0.5 text-xs rounded-full bg-gray-100 text-gray-500">Немає</span>
                        @endif
                    </div>

                    {{-- Expand arrow --}}
                    <svg class="w-4 h-4 text-gray-400 transition-transform {{ $expandedProductId === $product->id ? 'rotate-180' : '' }}"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>

                {{-- Expanded product card --}}
                @if ($expandedProductId === $product->id)
                    <div class="border-t border-gray-100 px-4 py-4 bg-gray-50">
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            {{-- Left: Product info --}}
                            <div>
                                <h3 class="text-sm font-semibold text-gray-700 mb-3">Інформація з Розетки</h3>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Rozetka ID:</span>
                                        <span class="text-gray-800">{{ $product->rozetka_item_id }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Артикул:</span>
                                        <span class="text-gray-800">{{ $product->article }}</span>
                                    </div>
                                    @if ($product->parent_article)
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Батьківський арт.:</span>
                                        <span class="text-gray-800">{{ $product->parent_article }}</span>
                                    </div>
                                    @endif
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Ціна:</span>
                                        <span class="text-gray-800">{{ number_format($product->price, 0, '.', ' ') }} ₴</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Кількість:</span>
                                        <span class="text-gray-800">{{ $product->quantity }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Модерація:</span>
                                        <span class="text-gray-800">{{ $product->moderation_status }}</span>
                                    </div>
                                </div>

                                {{-- Photos --}}
                                @if ($product->photos && count($product->photos) > 0)
                                    <div class="mt-3">
                                        <span class="text-sm text-gray-500">Фото:</span>
                                        <div class="flex gap-2 mt-1 flex-wrap">
                                            @foreach (array_slice($product->photos, 0, 5) as $photo)
                                                <img src="{{ $photo['url'] ?? $photo }}"
                                                     class="w-16 h-16 rounded object-cover border border-gray-200" alt="">
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>

                            {{-- Right: Category + Attributes --}}
                            <div>
                                {{-- Category assignment --}}
                                <h3 class="text-sm font-semibold text-gray-700 mb-3">Категорія Розетки</h3>

                                @if ($editingCategoryProductId === $product->id)
                                    <div class="mb-3">
                                        <input type="text" wire:model.live.debounce.300ms="categorySearch"
                                               placeholder="Пошук категорії..."
                                               class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">

                                        @if (count($categorySearchResults) > 0)
                                            <div class="mt-1 max-h-48 overflow-y-auto border border-gray-200 rounded-md bg-white">
                                                @foreach ($categorySearchResults as $cat)
                                                    <button wire:click="assignCategory({{ $product->id }}, {{ $cat['rozetka_id'] }}, '{{ addslashes($cat['title_ua']) }}')"
                                                            class="w-full text-left px-3 py-2 text-sm hover:bg-emerald-50 border-b border-gray-100 last:border-b-0">
                                                        <div class="font-medium text-gray-800">{{ $cat['title_ua'] }}</div>
                                                        @if ($cat['full_path'])
                                                            <div class="text-xs text-gray-400 truncate">{{ $cat['full_path'] }}</div>
                                                        @endif
                                                    </button>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @else
                                    <div class="flex items-center gap-2 mb-3">
                                        <span class="text-sm {{ $product->rozetka_category_name ? 'text-gray-800' : 'text-gray-400 italic' }}">
                                            {{ $product->rozetka_category_name ?? 'Не вибрана' }}
                                        </span>
                                        <button wire:click="startCategoryEdit({{ $product->id }})"
                                                class="text-xs text-emerald-600 hover:text-emerald-800 font-medium">
                                            ✏️ Змінити
                                        </button>
                                    </div>
                                @endif

                                {{-- Attributes --}}
                                @if (count($categoryAttributes) > 0)
                                    <h3 class="text-sm font-semibold text-gray-700 mb-2 mt-4">Характеристики</h3>
                                    <div class="space-y-2 max-h-96 overflow-y-auto">
                                        @foreach ($categoryAttributes as $attr)
                                            <div class="flex items-center gap-2">
                                                <label class="text-xs text-gray-600 w-40 flex-shrink-0 truncate"
                                                       title="{{ $attr['name'] }}">
                                                    {{ $attr['name'] }}
                                                    @if ($attr['filter_type'] === 'main')
                                                        <span class="text-red-500">*</span>
                                                    @endif
                                                </label>

                                                @php
                                                    $saved = $productAttributes[$attr['id']] ?? null;
                                                @endphp

                                                @if (in_array($attr['attr_type'], ['ComboBox', 'ListValues', 'List']))
                                                    <select wire:change="saveAttribute({{ $product->id }}, {{ $attr['id'] }}, '{{ addslashes($attr['name']) }}', $event.target.value, null)"
                                                            class="flex-1 px-2 py-1 border border-gray-300 rounded text-xs focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                                        <option value="">—</option>
                                                        @foreach ($attr['values'] ?? [] as $val)
                                                            <option value="{{ $val['id'] }}"
                                                                    {{ ($saved['value_id'] ?? null) == $val['id'] ? 'selected' : '' }}>
                                                                {{ $val['name'] }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                @elseif (in_array($attr['attr_type'], ['TextInput', 'TextArea', 'MultiText']))
                                                    <input type="text"
                                                           value="{{ $saved['value_text'] ?? '' }}"
                                                           wire:blur="saveAttribute({{ $product->id }}, {{ $attr['id'] }}, '{{ addslashes($attr['name']) }}', null, $event.target.value)"
                                                           class="flex-1 px-2 py-1 border border-gray-300 rounded text-xs focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                                           placeholder="{{ $attr['unit'] ? 'у ' . $attr['unit'] : '' }}">
                                                @elseif (in_array($attr['attr_type'], ['Decimal', 'Integer']))
                                                    <input type="number"
                                                           value="{{ $saved['value_text'] ?? '' }}"
                                                           wire:blur="saveAttribute({{ $product->id }}, {{ $attr['id'] }}, '{{ addslashes($attr['name']) }}', null, $event.target.value)"
                                                           class="flex-1 px-2 py-1 border border-gray-300 rounded text-xs focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                                           placeholder="{{ $attr['unit'] ?? '' }}"
                                                           step="{{ $attr['attr_type'] === 'Decimal' ? '0.01' : '1' }}">
                                                @elseif ($attr['attr_type'] === 'CheckBoxGroupValues')
                                                    <div class="flex-1 flex flex-wrap gap-1">
                                                        @foreach (array_slice($attr['values'] ?? [], 0, 8) as $val)
                                                            <label class="flex items-center gap-1 text-xs">
                                                                <input type="checkbox" value="{{ $val['id'] }}" class="rounded text-emerald-600">
                                                                {{ $val['name'] }}
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @elseif ($product->rozetka_category_id)
                                    <p class="text-xs text-gray-400 italic mt-2">Завантаження характеристик...</p>
                                @else
                                    <p class="text-xs text-gray-400 italic mt-2">Виберіть категорію для перегляду характеристик</p>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="bg-white rounded-lg shadow-sm p-8 text-center text-gray-500">
                @if ($search)
                    Товари не знайдені за запитом "{{ $search }}"
                @else
                    Товари ще не завантажені. Натисніть "Синхронізувати".
                @endif
            </div>
        @endforelse
    </div>

    {{-- Pagination --}}
    <div class="mt-4">
        {{ $products->links() }}
    </div>
</div>

<div @if($syncing) wire:poll.3s="checkSyncStatus" @endif>

    {{-- Sync progress --}}
    @if ($syncMessage)
        <div class="mb-4 p-3 bg-purple-50 border border-purple-200 rounded text-sm text-purple-700">
            <div class="flex items-center justify-between mb-1">
                <span>🛍️ {{ $syncMessage }}</span>
                @if ($syncing && $syncTotal > 0)
                    <span class="text-xs font-mono text-purple-500">{{ $syncTotal }} шт.</span>
                @endif
            </div>
            @if ($syncing)
                <div class="w-full bg-purple-200 rounded-full h-2 mt-2">
                    <div class="bg-purple-600 h-2 rounded-full animate-pulse" style="width: 100%"></div>
                </div>
            @endif
        </div>
    @endif

    {{-- Stats bar --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <div class="bg-white rounded-lg shadow-sm px-4 py-3 flex items-center gap-2">
            <span class="text-2xl font-bold text-gray-800">{{ $totalProducts }}</span>
            <span class="text-sm text-gray-500">всього</span>
        </div>
        <div class="bg-white rounded-lg shadow-sm px-3 py-2 flex items-center gap-2">
            <span class="text-lg font-bold text-emerald-600">{{ $inStockCount }}</span>
            <span class="text-xs text-gray-500">В наявності</span>
        </div>
        <div class="bg-white rounded-lg shadow-sm px-3 py-2 flex items-center gap-2 cursor-pointer hover:ring-2 hover:ring-purple-300 transition {{ $matchFilter === 'matched' ? 'ring-2 ring-purple-500' : '' }}"
             wire:click="$set('matchFilter', '{{ $matchFilter === 'matched' ? '' : 'matched' }}')">
            <span class="text-lg font-bold text-purple-600">{{ $matchedCount }}</span>
            <span class="text-xs text-gray-500">🔗 Зв'язані з Розеткою</span>
        </div>
        @if ($unmatchedCount > 0)
        <div class="bg-white rounded-lg shadow-sm px-3 py-2 flex items-center gap-2 cursor-pointer hover:ring-2 hover:ring-amber-300 transition {{ $matchFilter === 'unmatched' ? 'ring-2 ring-amber-500' : '' }}"
             wire:click="$set('matchFilter', '{{ $matchFilter === 'unmatched' ? '' : 'unmatched' }}')">
            <span class="text-lg font-bold text-amber-600">{{ $unmatchedCount }}</span>
            <span class="text-xs text-gray-500">❌ Не зв'язані</span>
        </div>
        @endif

        <div class="ml-auto">
            <button wire:click="syncCatalog" wire:loading.attr="disabled"
                    class="bg-purple-600 text-white px-4 py-2 rounded-md hover:bg-purple-700 transition text-sm font-medium disabled:opacity-50"
                    @if($syncing) disabled @endif>
                <span wire:loading.remove wire:target="syncCatalog">🛍️ Синхронізувати Хорошоп</span>
                <span wire:loading wire:target="syncCatalog">⏳ Завантаження...</span>
            </button>
        </div>
    </div>

    {{-- Filters --}}
    <div class="mb-4 bg-white rounded-lg shadow-sm p-4 flex flex-wrap items-center gap-4">
        <div class="flex-1 min-w-[200px]">
            <input type="text" wire:model.live.debounce.300ms="search"
                   placeholder="Пошук за назвою або артикулом..."
                   class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-purple-500">
        </div>
        <select wire:model.live="stockFilter"
                class="px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-purple-500">
            <option value="">Наявність: всі</option>
            <option value="in_stock">В наявності</option>
            <option value="out_of_stock">Немає в наявності</option>
        </select>
        @if ($matchFilter !== '')
            <button wire:click="$set('matchFilter', '')" class="text-xs text-gray-500 hover:text-red-500 transition">
                ✕ Скинути фільтр
            </button>
        @endif
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
                        @if ($product->first_image)
                            <img src="{{ $product->first_image }}" alt="" class="w-full h-full object-cover">
                        @else
                            <div class="w-full h-full flex items-center justify-center text-gray-400 text-xs">📷</div>
                        @endif
                    </div>

                    {{-- Title + Article --}}
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium text-gray-800 truncate">{{ $product->title }}</div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-gray-500">{{ $product->article }}</span>
                            @if ($product->brand)
                                <span class="text-xs text-gray-400">{{ $product->brand }}</span>
                            @endif
                        </div>
                    </div>

                    {{-- Rozetka match badge --}}
                    <div class="hidden sm:block">
                        @if ($product->rozetka_product_id)
                            <span class="inline-block px-2 py-0.5 text-xs rounded-full bg-purple-100 text-purple-700">🔗 Розетка</span>
                        @else
                            <span class="inline-block px-2 py-0.5 text-xs rounded-full bg-gray-100 text-gray-500">Не зв'язано</span>
                        @endif
                    </div>

                    {{-- Category --}}
                    <div class="hidden lg:block text-xs text-gray-500 max-w-[180px] truncate">
                        {{ $product->category_path ?? '—' }}
                    </div>

                    {{-- Price --}}
                    <div class="text-sm font-medium text-gray-700 w-20 text-right">
                        {{ number_format($product->price, 0, '.', ' ') }} ₴
                    </div>

                    {{-- Stock --}}
                    <div class="w-20 text-right">
                        @if ($product->in_stock)
                            <span class="inline-block px-2 py-0.5 text-xs rounded-full bg-emerald-100 text-emerald-700">{{ $product->quantity ?? '✓' }} шт</span>
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

                {{-- Expanded product details --}}
                @if ($expandedProductId === $product->id)
                    @php
                        $rz = $product->rozetkaProduct;
                        $imgs = $product->images ?? [];
                    @endphp
                    <div class="border-t border-gray-100 px-4 py-4 bg-gray-50">
                        {{-- Rozetka match info --}}
                        @if ($rz)
                            <div class="mb-4 px-3 py-2 bg-purple-50 border border-purple-200 rounded-lg flex items-center gap-3">
                                <span class="text-purple-600 text-lg">🔗</span>
                                <div class="flex-1">
                                    <span class="text-sm font-medium text-purple-800">Зв'язано з Розеткою:</span>
                                    <span class="text-sm text-purple-700">{{ $rz->title }}</span>
                                    <span class="text-xs text-purple-500 ml-1">({{ $rz->article }})</span>
                                </div>
                                @php $priceDiff = $product->price - ($rz->price ?? 0); @endphp
                                @if ($priceDiff != 0)
                                    <span class="text-xs px-2 py-0.5 rounded {{ $priceDiff > 0 ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700' }}">
                                        Різниця: {{ $priceDiff > 0 ? '+' : '' }}{{ number_format($priceDiff, 0, '.', ' ') }} ₴
                                    </span>
                                @endif
                            </div>
                        @else
                            <div class="mb-4 px-3 py-2 bg-amber-50 border border-amber-200 rounded-lg flex items-center gap-2">
                                <span class="text-amber-600">❌</span>
                                <span class="text-sm text-amber-800">Не зв'язано з Розеткою — артикул не знайдено серед товарів Розетки.</span>
                            </div>
                        @endif

                        {{-- Product details grid --}}
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            {{-- Left: Images --}}
                            <div>
                                @if (is_array($imgs) && count($imgs) > 0)
                                    <div class="grid grid-cols-3 gap-2">
                                        @foreach (array_slice($imgs, 0, 6) as $img)
                                            @php $url = is_string($img) ? $img : ($img['url'] ?? $img['link'] ?? ''); @endphp
                                            @if ($url)
                                                <img src="{{ $url }}" alt="" class="w-full h-20 object-cover rounded border">
                                            @endif
                                        @endforeach
                                    </div>
                                @else
                                    <div class="bg-gray-100 rounded h-32 flex items-center justify-center text-gray-400">Немає зображень</div>
                                @endif
                            </div>

                            {{-- Right: Details --}}
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Артикул:</span>
                                    <span class="font-medium">{{ $product->article }}</span>
                                </div>
                                @if ($product->parent_article)
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Батьківський:</span>
                                    <span>{{ $product->parent_article }}</span>
                                </div>
                                @endif
                                @if ($product->brand)
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Бренд:</span>
                                    <span>{{ $product->brand }}</span>
                                </div>
                                @endif
                                @if ($product->color)
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Колір:</span>
                                    <span>{{ $product->color }}</span>
                                </div>
                                @endif
                                @if ($product->size)
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Розмір:</span>
                                    <span>{{ $product->size }}</span>
                                </div>
                                @endif
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Ціна:</span>
                                    <span class="font-medium">{{ number_format($product->price, 0, '.', ' ') }} ₴</span>
                                </div>
                                @if ($product->price_old && $product->price_old > 0)
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Стара ціна:</span>
                                    <span class="line-through text-gray-400">{{ number_format($product->price_old, 0, '.', ' ') }} ₴</span>
                                </div>
                                @endif
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Наявність:</span>
                                    <span>{{ $product->in_stock ? "Так ({$product->quantity} шт)" : 'Немає' }}</span>
                                </div>
                                @if ($product->category_path)
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Категорія:</span>
                                    <span class="text-xs">{{ $product->category_path }}</span>
                                </div>
                                @endif
                                @if ($product->synced_at)
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Синхронізовано:</span>
                                    <span class="text-xs">{{ $product->synced_at->format('d.m.Y H:i') }}</span>
                                </div>
                                @endif
                            </div>
                        </div>

                        {{-- Characteristics --}}
                        @if ($product->characteristics && count($product->characteristics) > 0)
                            <div class="mt-4">
                                <h4 class="text-xs font-semibold text-gray-600 mb-2">Характеристики</h4>
                                <div class="grid grid-cols-2 md:grid-cols-3 gap-1 text-xs">
                                    @foreach (array_slice($product->characteristics, 0, 12) as $key => $val)
                                        <div class="flex gap-1">
                                            <span class="text-gray-500">{{ is_string($key) ? $key : '' }}:</span>
                                            <span class="text-gray-700">{{ is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : $val }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- Description snippet --}}
                        @if ($product->description_ua)
                            <div class="mt-4">
                                <h4 class="text-xs font-semibold text-gray-600 mb-1">Опис</h4>
                                <div class="text-xs text-gray-600 line-clamp-3">{!! strip_tags(Str::limit($product->description_ua, 300)) !!}</div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <div class="bg-white rounded-lg shadow-sm p-8 text-center text-gray-500">
                @if ($search || $matchFilter !== '')
                    Товари не знайдені за вашими фільтрами
                @else
                    Товари ще не завантажені. Натисніть "Синхронізувати Хорошоп".
                @endif
            </div>
        @endforelse
    </div>

    {{-- Pagination --}}
    <div class="mt-4">
        {{ $products->links() }}
    </div>
</div>

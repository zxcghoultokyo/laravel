<div @if($syncing) wire:poll.3s="checkSyncStatus" @endif>
    {{-- Sync progress --}}
    @if ($syncMessage)
        <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded text-sm text-blue-700">
            <div class="flex items-center justify-between mb-1">
                <span>{{ $syncMessage }}</span>
                @if ($syncing && $syncedCount > 0)
                    <span class="text-xs font-mono text-blue-500">{{ $syncedCount }} шт.</span>
                @endif
            </div>
            @if ($syncing)
                <div class="w-full bg-blue-200 rounded-full h-2 mt-2">
                    <div class="bg-blue-600 h-2 rounded-full transition-all duration-500" style="width: {{ $syncPercent }}%"></div>
                </div>
            @endif
        </div>
    @endif

        {{-- ═══════════ Products on Rozetka ═══════════ --}}

        {{-- Status stats bar --}}
        @php
            $statusMeta = [
                0 => ['dot' => 'bg-blue-500', 'text' => 'text-blue-600', 'ring' => 'ring-blue-500', 'hover' => 'hover:ring-blue-300', 'label' => 'Нових'],
                1 => ['dot' => 'bg-yellow-500', 'text' => 'text-yellow-600', 'ring' => 'ring-yellow-500', 'hover' => 'hover:ring-yellow-300', 'label' => 'На модерації'],
                2 => ['dot' => 'bg-emerald-500', 'text' => 'text-emerald-600', 'ring' => 'ring-emerald-500', 'hover' => 'hover:ring-emerald-300', 'label' => 'Активних'],
                3 => ['dot' => 'bg-gray-500', 'text' => 'text-gray-600', 'ring' => 'ring-gray-500', 'hover' => 'hover:ring-gray-300', 'label' => 'Вимкнених'],
                4 => ['dot' => 'bg-indigo-500', 'text' => 'text-indigo-600', 'ring' => 'ring-indigo-500', 'hover' => 'hover:ring-indigo-300', 'label' => 'Архів'],
                9 => ['dot' => 'bg-red-500', 'text' => 'text-red-600', 'ring' => 'ring-red-500', 'hover' => 'hover:ring-red-300', 'label' => 'Модерація ✗'],
            ];
            $defaultMeta = ['dot' => 'bg-gray-400', 'text' => 'text-gray-600', 'ring' => 'ring-gray-400', 'hover' => 'hover:ring-gray-300'];
        @endphp
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <div class="bg-white rounded-lg shadow-sm px-4 py-3 flex items-center gap-2">
                <span class="text-2xl font-bold text-gray-800">{{ $totalProducts }}</span>
                <span class="text-sm text-gray-500">всього</span>
            </div>
            @foreach ($statusCounts as $sc)
                @php
                    $st = (int) $sc->upload_status;
                    $meta = $statusMeta[$st] ?? array_merge($defaultMeta, ['label' => $sc->upload_status_title ?? "Статус {$st}"]);
                    $isActive = $uploadStatusFilter === (string) $st;
                @endphp
                <div class="bg-white rounded-lg shadow-sm px-3 py-2 flex items-center gap-2 cursor-pointer {{ $meta['hover'] }} transition {{ $isActive ? 'ring-2 ' . $meta['ring'] : '' }}"
                     wire:click="$set('uploadStatusFilter', '{{ $isActive ? '' : $st }}')">
                    <span class="w-2.5 h-2.5 rounded-full {{ $meta['dot'] }}"></span>
                    <span class="text-lg font-bold {{ $meta['text'] }}">{{ $sc->cnt }}</span>
                    <span class="text-xs text-gray-500">{{ $meta['label'] }}</span>
                </div>
            @endforeach
            <div class="bg-white rounded-lg shadow-sm px-3 py-2 flex items-center gap-2">
                <span class="text-lg font-bold text-emerald-600">{{ $inStockCount }}</span>
                <span class="text-xs text-gray-500">В наявності</span>
            </div>
            @if ($blockedCount > 0)
            <div class="bg-white rounded-lg shadow-sm px-3 py-2 flex items-center gap-2">
                <span class="text-lg font-bold text-orange-600">{{ $blockedCount }}</span>
                <span class="text-xs text-gray-500">⚠ Заблоковані</span>
            </div>
            @endif
            <div class="bg-white rounded-lg shadow-sm px-3 py-2 flex items-center gap-2 cursor-pointer hover:ring-2 hover:ring-purple-300 transition {{ $matchFilter === 'matched' ? 'ring-2 ring-purple-500' : '' }}"
                 wire:click="$set('matchFilter', '{{ $matchFilter === 'matched' ? '' : 'matched' }}')">
                <span class="text-lg font-bold text-purple-600">{{ $matchedCount }}</span>
                <span class="text-xs text-gray-500">🔗 Зв'язані</span>
            </div>
            @if ($unmatchedCount > 0)
            <div class="bg-white rounded-lg shadow-sm px-3 py-2 flex items-center gap-2 cursor-pointer hover:ring-2 hover:ring-amber-300 transition {{ $matchFilter === 'unmatched' ? 'ring-2 ring-amber-500' : '' }}"
                 wire:click="$set('matchFilter', '{{ $matchFilter === 'unmatched' ? '' : 'unmatched' }}')">
                <span class="text-lg font-bold text-amber-600">{{ $unmatchedCount }}</span>
                <span class="text-xs text-gray-500">❌ Не зв'язані</span>
            </div>
            @endif
            @if (($duplicateCount ?? 0) > 0)
            <div class="bg-white rounded-lg shadow-sm px-3 py-2 flex items-center gap-2" title="Товари з однаковим артикулом — дублі не відправляються на Розетку">
                <span class="text-lg font-bold text-gray-400">{{ $duplicateCount }}</span>
                <span class="text-xs text-gray-500">👥 Дублі</span>
            </div>
            @endif
            <div class="ml-auto">
                <button wire:click="syncProducts" wire:loading.attr="disabled"
                        class="bg-emerald-600 text-white px-4 py-2 rounded-md hover:bg-emerald-700 transition text-sm font-medium disabled:opacity-50">
                    <span wire:loading.remove wire:target="syncProducts">🔄 Синхронізувати Розетку</span>
                    <span wire:loading wire:target="syncProducts">⏳ Завантаження...</span>
                </button>
            </div>
        </div>

        {{-- Filters --}}
        <div class="mb-4 bg-white rounded-lg shadow-sm p-4 flex flex-wrap items-center gap-4">
            <div class="flex-1 min-w-[200px]">
                <input type="text" wire:model.live.debounce.300ms="search"
                       placeholder="Пошук за назвою або артикулом..."
                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
            </div>
            <select wire:model.live="stockFilter"
                    class="px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
                <option value="">Наявність: всі</option>
                <option value="in_stock">В наявності</option>
                <option value="out_of_stock">Немає в наявності</option>
            </select>
            @if ($uploadStatusFilter !== '' || $matchFilter !== '')
                <button wire:click="$set('uploadStatusFilter', ''); $set('matchFilter', '')" class="text-xs text-gray-500 hover:text-red-500 transition">
                    ✕ Скинути фільтри
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
                            @if ($product->first_photo)
                                <img src="{{ $product->first_photo }}" alt="" class="w-full h-full object-cover">
                            @else
                                <div class="w-full h-full flex items-center justify-center text-gray-400 text-xs">📷</div>
                            @endif
                        </div>

                        {{-- Title + Article + Match --}}
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-gray-800 truncate">{{ $product->title }}</div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-gray-500">{{ $product->article }}</span>
                                @if ($product->local_product_id)
                                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-purple-500" title="Зв'язано з базою"></span>
                                @else
                                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-amber-400" title="Немає в базі"></span>
                                @endif
                            </div>
                        </div>

                        {{-- Upload status badge --}}
                        <div class="hidden sm:block">
                            @if ($product->upload_status === 2)
                                <span class="inline-block px-2 py-0.5 text-xs rounded-full bg-emerald-100 text-emerald-700">Активний</span>
                            @elseif ($product->upload_status === 0)
                                <span class="inline-block px-2 py-0.5 text-xs rounded-full bg-blue-100 text-blue-700">Новий</span>
                            @elseif ($product->upload_status === 9)
                                <span class="inline-block px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-700">Модерація ✗</span>
                            @else
                                <span class="inline-block px-2 py-0.5 text-xs rounded-full bg-gray-100 text-gray-500">{{ $product->upload_status_title ?? '?' }}</span>
                            @endif
                        </div>

                        {{-- Category --}}
                        <div class="hidden lg:block text-xs text-gray-500 max-w-[180px] truncate">
                            {{ $product->rozetka_category_name ?? '—' }}
                        </div>

                        {{-- Price --}}
                        <div class="text-sm font-medium text-gray-700 w-20 text-right">
                            {{ number_format($product->price, 0, '.', ' ') }} ₴
                        </div>

                        {{-- Stock --}}
                        <div class="w-20 text-right">
                            @if ($product->in_stock)
                                <span class="inline-block px-2 py-0.5 text-xs rounded-full bg-emerald-100 text-emerald-700">{{ $product->quantity }} шт</span>
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

                    {{-- Blocked reasons alert --}}
                    @if ($product->blocked_reasons && count($product->blocked_reasons) > 0)
                        <div class="px-4 py-2 bg-orange-50 border-t border-orange-100 text-xs text-orange-700">
                            ⚠️ @foreach ($product->blocked_reasons as $reason)
                                <span>{{ is_array($reason) ? ($reason['title'] ?? json_encode($reason, JSON_UNESCAPED_UNICODE)) : $reason }}</span>@if (!$loop->last), @endif
                            @endforeach
                        </div>
                    @endif

                    {{-- Expanded product card (CRM-like with dual rows) --}}
                    @if ($expandedProductId === $product->id)
                        @include('livewire.contractor.partials.rozetka-product-card', [
                            'product' => $product,
                            'horoshop' => $product->horoshopProduct,
                            'categoryAttributes' => $categoryAttributes,
                            'productAttributes' => $productAttributes,
                            'editingCategoryProductId' => $editingCategoryProductId,
                            'categorySearch' => $categorySearch,
                            'categorySearchResults' => $categorySearchResults,
                        ])
                    @endif
                </div>
            @empty
                <div class="bg-white rounded-lg shadow-sm p-8 text-center text-gray-500">
                    @if ($search || $uploadStatusFilter !== '' || $matchFilter !== '')
                        Товари не знайдені за вашими фільтрами
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

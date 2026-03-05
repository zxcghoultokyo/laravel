<div @if($syncing) wire:poll.3s="checkSyncStatus" @endif>
    {{-- Tabs --}}
    <div class="mb-4 flex items-center gap-1 bg-white rounded-lg shadow-sm p-1">
        <button wire:click="switchTab('rozetka')"
                class="flex-1 px-4 py-2.5 rounded-md text-sm font-medium transition {{ $activeTab === 'rozetka' ? 'bg-emerald-600 text-white shadow' : 'text-gray-600 hover:bg-gray-100' }}">
            🛒 На Розетці
            <span class="ml-1 text-xs opacity-75">({{ $totalProducts }})</span>
        </button>
        <button wire:click="switchTab('export')"
                class="flex-1 px-4 py-2.5 rounded-md text-sm font-medium transition {{ $activeTab === 'export' ? 'bg-emerald-600 text-white shadow' : 'text-gray-600 hover:bg-gray-100' }}">
            📦 Підготовка до експорту
            <span class="ml-1 text-xs opacity-75">({{ $notOnRozetkaCount }})</span>
        </button>
    </div>

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

    @if ($activeTab === 'rozetka')
        {{-- ═══════════ TAB 1: Products on Rozetka ═══════════ --}}

        {{-- Status stats bar --}}
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <div class="bg-white rounded-lg shadow-sm px-4 py-3 flex items-center gap-2">
                <span class="text-2xl font-bold text-gray-800">{{ $totalProducts }}</span>
                <span class="text-sm text-gray-500">всього</span>
            </div>
            <div class="bg-white rounded-lg shadow-sm px-3 py-2 flex items-center gap-2 cursor-pointer hover:ring-2 hover:ring-emerald-300 transition {{ $uploadStatusFilter === '2' ? 'ring-2 ring-emerald-500' : '' }}"
                 wire:click="$set('uploadStatusFilter', '{{ $uploadStatusFilter === '2' ? '' : '2' }}')">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                <span class="text-lg font-bold text-emerald-600">{{ $activeCount }}</span>
                <span class="text-xs text-gray-500">Активних</span>
            </div>
            <div class="bg-white rounded-lg shadow-sm px-3 py-2 flex items-center gap-2 cursor-pointer hover:ring-2 hover:ring-blue-300 transition {{ $uploadStatusFilter === '0' ? 'ring-2 ring-blue-500' : '' }}"
                 wire:click="$set('uploadStatusFilter', '{{ $uploadStatusFilter === '0' ? '' : '0' }}')">
                <span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span>
                <span class="text-lg font-bold text-blue-600">{{ $newCount }}</span>
                <span class="text-xs text-gray-500">Нових</span>
            </div>
            @if ($failedModerationCount > 0)
            <div class="bg-white rounded-lg shadow-sm px-3 py-2 flex items-center gap-2 cursor-pointer hover:ring-2 hover:ring-red-300 transition {{ $uploadStatusFilter === '9' ? 'ring-2 ring-red-500' : '' }}"
                 wire:click="$set('uploadStatusFilter', '{{ $uploadStatusFilter === '9' ? '' : '9' }}')">
                <span class="w-2.5 h-2.5 rounded-full bg-red-500"></span>
                <span class="text-lg font-bold text-red-600">{{ $failedModerationCount }}</span>
                <span class="text-xs text-gray-500">Модерація ✗</span>
            </div>
            @endif
            <div class="bg-white rounded-lg shadow-sm px-3 py-2 flex items-center gap-2">
                <span class="text-lg font-bold text-emerald-600">{{ $inStockCount }}</span>
                <span class="text-xs text-gray-500">В наявності</span>
            </div>
            @if ($blockedCount > 0)
            <div class="bg-white rounded-lg shadow-sm px-3 py-2 flex items-center gap-2">
                <span class="text-lg font-bold text-orange-600">{{ $blockedCount }}</span>
                <span class="text-xs text-gray-500">⚠️ Заблоковані</span>
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

            <div class="ml-auto">
                <button wire:click="syncProducts" wire:loading.attr="disabled"
                        class="bg-emerald-600 text-white px-4 py-2 rounded-md hover:bg-emerald-700 transition text-sm font-medium disabled:opacity-50">
                    <span wire:loading.remove wire:target="syncProducts">🔄 Синхронізувати</span>
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

                    {{-- Expanded product card (CRM-like) --}}
                    @if ($expandedProductId === $product->id)
                        @php
                            $raw = $product->raw ?? [];
                            $local = $product->localProduct;
                            $commission = $raw['commission_percent'] ?? null;
                            $commissionSum = $raw['commission_sum'] ?? null;
                            $sold = $raw['sold'] ?? null;
                            $priceProducer = $raw['price_producer_name'] ?? null;
                            $priceCat = $raw['price_category'] ?? [];
                            $errorType = $raw['error_type'] ?? null;
                            $errorReason = $raw['error_reason'] ?? null;
                            $changes = $raw['changes'] ?? [];
                            $canDelete = $raw['can_delete'] ?? false;
                            $duplicateMark = $raw['duplicate_mark'] ?? false;
                        @endphp
                        <div class="border-t border-gray-100 px-4 py-4 bg-gray-50">
                            {{-- Match status banner --}}
                            @if ($local)
                                <div class="mb-4 px-3 py-2 bg-purple-50 border border-purple-200 rounded-lg flex items-center gap-3">
                                    <span class="text-purple-600 text-lg">🔗</span>
                                    <div class="flex-1">
                                        <span class="text-sm font-medium text-purple-800">Зв'язано з базою:</span>
                                        <span class="text-sm text-purple-700">{{ $local->title }}</span>
                                        <span class="text-xs text-purple-500 ml-1">({{ $local->article }})</span>
                                    </div>
                                    @php
                                        $priceDiff = $product->price - ($local->price ?? 0);
                                    @endphp
                                    @if ($priceDiff != 0)
                                        <span class="text-xs px-2 py-0.5 rounded {{ $priceDiff > 0 ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700' }}">
                                            Різниця в ціні: {{ $priceDiff > 0 ? '+' : '' }}{{ number_format($priceDiff, 0, '.', ' ') }} ₴
                                        </span>
                                    @endif
                                </div>
                            @else
                                <div class="mb-4 px-3 py-2 bg-amber-50 border border-amber-200 rounded-lg flex items-center gap-2">
                                    <span class="text-amber-600 text-lg">❌</span>
                                    <span class="text-sm text-amber-800">
                                        <strong>Не зв'язано з базою</strong> — цього артикулу ({{ $product->article }}) немає в каталозі Horoshop.
                                        Можливо товар був видалений з бази.
                                    </span>
                                </div>
                            @endif

                            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                                {{-- Column 1: Photos + basic info --}}
                                <div>
                                    {{-- Photos --}}
                                    @if (count($product->clean_photo_urls) > 0)
                                        <div class="mb-4">
                                            <img src="{{ $product->clean_photo_urls[0] }}"
                                                 class="w-full max-h-48 rounded-lg object-contain bg-white border border-gray-200" alt="">
                                            @if (count($product->clean_photo_urls) > 1)
                                                <div class="flex gap-1.5 mt-2 overflow-x-auto">
                                                    @foreach (array_slice($product->clean_photo_urls, 1, 6) as $photoUrl)
                                                        <img src="{{ $photoUrl }}"
                                                             class="w-14 h-14 rounded object-cover border border-gray-200 flex-shrink-0" alt="">
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    @else
                                        <div class="mb-4 w-full h-32 bg-gray-100 rounded-lg flex items-center justify-center text-gray-400">
                                            Немає фото
                                        </div>
                                    @endif

                                    {{-- URL --}}
                                    @if ($product->url)
                                        <a href="{{ $product->url }}" target="_blank" rel="noopener"
                                           class="inline-flex items-center gap-1 text-sm text-emerald-600 hover:text-emerald-800 hover:underline mb-3">
                                            Відкрити на Розетці ↗
                                        </a>
                                    @endif

                                    {{-- Flags --}}
                                    <div class="flex flex-wrap gap-1.5 mt-2">
                                        @if ($duplicateMark)
                                            <span class="px-2 py-0.5 text-xs rounded-full bg-yellow-100 text-yellow-700">Дублікат</span>
                                        @endif
                                        @if ($raw['is_blocked_by_stop_brands'] ?? false)
                                            <span class="px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-700">Стоп-бренд</span>
                                        @endif
                                        @if ($raw['is_blocked_by_stop_categories'] ?? false)
                                            <span class="px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-700">Стоп-категорія</span>
                                        @endif
                                        @if ($raw['is_blocked_by_stop_words'] ?? false)
                                            <span class="px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-700">Стоп-слова</span>
                                        @endif
                                        @if (!empty($raw['stop_words']))
                                            <span class="px-2 py-0.5 text-xs rounded-full bg-red-50 text-red-600" title="{{ implode(', ', $raw['stop_words']) }}">
                                                Стоп: {{ implode(', ', array_slice($raw['stop_words'], 0, 3)) }}
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                {{-- Column 2: Product details --}}
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-700 mb-3 border-b pb-1">📋 Деталі товару</h3>
                                    <div class="space-y-1.5 text-sm">
                                        <div class="grid grid-cols-[120px_1fr] gap-1">
                                            <span class="text-gray-500 text-xs">Rozetka ID</span>
                                            <span class="text-gray-800 text-xs font-mono">{{ $product->rozetka_item_id ?? '—' }}</span>
                                        </div>
                                        <div class="grid grid-cols-[120px_1fr] gap-1">
                                            <span class="text-gray-500 text-xs">Артикул</span>
                                            <span class="text-gray-800 text-xs font-mono">{{ $product->article }}</span>
                                        </div>
                                        @if ($product->parent_article)
                                        <div class="grid grid-cols-[120px_1fr] gap-1">
                                            <span class="text-gray-500 text-xs">Батьківський</span>
                                            <span class="text-gray-800 text-xs font-mono">{{ $product->parent_article }}</span>
                                        </div>
                                        @endif

                                        <div class="border-t border-gray-100 my-2"></div>

                                        <div class="grid grid-cols-[120px_1fr] gap-1">
                                            <span class="text-gray-500 text-xs">Ціна</span>
                                            <span class="text-gray-800 text-xs font-semibold">{{ number_format($product->price, 0, '.', ' ') }} ₴</span>
                                        </div>
                                        @if ($product->price_old)
                                        <div class="grid grid-cols-[120px_1fr] gap-1">
                                            <span class="text-gray-500 text-xs">Стара ціна</span>
                                            <span class="text-gray-800 text-xs line-through">{{ number_format($product->price_old, 0, '.', ' ') }} ₴</span>
                                        </div>
                                        @endif
                                        @if ($local)
                                        <div class="grid grid-cols-[120px_1fr] gap-1">
                                            <span class="text-gray-500 text-xs">Ціна в базі</span>
                                            <span class="text-xs {{ $local->price != $product->price ? 'text-orange-600 font-semibold' : 'text-gray-800' }}">
                                                {{ number_format($local->price, 0, '.', ' ') }} ₴
                                                @if ($local->price != $product->price)
                                                    ⚠️
                                                @endif
                                            </span>
                                        </div>
                                        @endif

                                        <div class="border-t border-gray-100 my-2"></div>

                                        <div class="grid grid-cols-[120px_1fr] gap-1">
                                            <span class="text-gray-500 text-xs">Кількість</span>
                                            <span class="text-gray-800 text-xs">{{ $product->quantity }} шт</span>
                                        </div>
                                        <div class="grid grid-cols-[120px_1fr] gap-1">
                                            <span class="text-gray-500 text-xs">Наявність</span>
                                            <span class="text-xs {{ $product->in_stock ? 'text-emerald-700' : 'text-gray-500' }}">
                                                {{ $product->available_title ?? '—' }}
                                            </span>
                                        </div>
                                        @if ($local)
                                        <div class="grid grid-cols-[120px_1fr] gap-1">
                                            <span class="text-gray-500 text-xs">В базі</span>
                                            <span class="text-xs {{ $local->in_stock ? 'text-emerald-700' : 'text-gray-500' }}">
                                                {{ $local->in_stock ? 'В наявності' : 'Немає' }}
                                                ({{ $local->quantity ?? 0 }} шт)
                                            </span>
                                        </div>
                                        @endif

                                        <div class="border-t border-gray-100 my-2"></div>

                                        <div class="grid grid-cols-[120px_1fr] gap-1">
                                            <span class="text-gray-500 text-xs">Виробник</span>
                                            <span class="text-gray-800 text-xs">{{ $product->producer_name ?? '—' }}</span>
                                        </div>
                                        @if ($priceProducer && $priceProducer !== $product->producer_name)
                                        <div class="grid grid-cols-[120px_1fr] gap-1">
                                            <span class="text-gray-500 text-xs">Виробник (прайс)</span>
                                            <span class="text-gray-800 text-xs">{{ $priceProducer }}</span>
                                        </div>
                                        @endif
                                        @if ($commission)
                                        <div class="grid grid-cols-[120px_1fr] gap-1">
                                            <span class="text-gray-500 text-xs">Комісія</span>
                                            <span class="text-gray-800 text-xs">{{ $commission }}% ({{ $commissionSum }} ₴)</span>
                                        </div>
                                        @endif
                                        @if ($sold !== null)
                                        <div class="grid grid-cols-[120px_1fr] gap-1">
                                            <span class="text-gray-500 text-xs">Продано</span>
                                            <span class="text-gray-800 text-xs">{{ $sold }} шт</span>
                                        </div>
                                        @endif
                                    </div>

                                    {{-- Errors --}}
                                    @if ($errorType || $errorReason)
                                        <div class="mt-3 p-2 bg-red-50 rounded text-xs text-red-700">
                                            <strong>Помилка:</strong> {{ $errorType }} — {{ $errorReason }}
                                        </div>
                                    @endif

                                    {{-- Changes --}}
                                    @if (!empty($changes['changed_fields']) || !empty($changes['reasons']))
                                        <div class="mt-3 p-2 bg-blue-50 rounded text-xs text-blue-700">
                                            <strong>Зміни:</strong>
                                            @if (!empty($changes['changed_fields']))
                                                {{ is_array($changes['changed_fields']) ? implode(', ', $changes['changed_fields']) : $changes['changed_fields'] }}
                                            @endif
                                            @if (!empty($changes['reasons']))
                                                <br>Причини: {{ implode(', ', $changes['reasons']) }}
                                            @endif
                                            @if (!empty($changes['change_date']))
                                                <span class="text-blue-500 ml-1">({{ $changes['change_date'] }})</span>
                                            @endif
                                        </div>
                                    @endif
                                </div>

                                {{-- Column 3: Status + Category + Attributes --}}
                                <div>
                                    {{-- Status section --}}
                                    <h3 class="text-sm font-semibold text-gray-700 mb-3 border-b pb-1">📊 Статус</h3>
                                    <div class="space-y-1.5 text-sm mb-4">
                                        <div class="grid grid-cols-[120px_1fr] gap-1">
                                            <span class="text-gray-500 text-xs">Статус</span>
                                            <span>
                                                @if ($product->upload_status === 2)
                                                    <span class="inline-block px-2 py-0.5 text-xs rounded-full bg-emerald-100 text-emerald-700">{{ $product->upload_status_title ?? 'Активний' }}</span>
                                                @elseif ($product->upload_status === 0)
                                                    <span class="inline-block px-2 py-0.5 text-xs rounded-full bg-blue-100 text-blue-700">{{ $product->upload_status_title ?? 'Новий' }}</span>
                                                @elseif ($product->upload_status === 9)
                                                    <span class="inline-block px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-700">{{ $product->upload_status_title ?? 'Модерація ✗' }}</span>
                                                @else
                                                    <span class="text-xs text-gray-500">{{ $product->upload_status_title ?? '—' }}</span>
                                                @endif
                                            </span>
                                        </div>
                                        @if ($product->rz_status !== null)
                                        <div class="grid grid-cols-[120px_1fr] gap-1">
                                            <span class="text-gray-500 text-xs">RZ статус</span>
                                            <span class="text-gray-800 text-xs">{{ $product->rz_status }}</span>
                                        </div>
                                        @endif
                                        @if ($product->rz_sell_status !== null)
                                        <div class="grid grid-cols-[120px_1fr] gap-1">
                                            <span class="text-gray-500 text-xs">Статус продажу</span>
                                            <span class="text-gray-800 text-xs">{{ $product->rz_sell_status }}</span>
                                        </div>
                                        @endif
                                        <div class="grid grid-cols-[120px_1fr] gap-1">
                                            <span class="text-gray-500 text-xs">Синхронізовано</span>
                                            <span class="text-gray-800 text-xs">{{ $product->synced_at?->format('d.m.Y H:i') ?? '—' }}</span>
                                        </div>
                                    </div>

                                    {{-- Category --}}
                                    <h3 class="text-sm font-semibold text-gray-700 mb-2 border-b pb-1">📁 Категорія</h3>
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
                                        <div class="flex items-center gap-2 mb-2">
                                            <span class="text-sm {{ $product->rozetka_category_name ? 'text-gray-800' : 'text-gray-400 italic' }}">
                                                {{ $product->rozetka_category_name ?? 'Не вибрана' }}
                                            </span>
                                            <button wire:click="startCategoryEdit({{ $product->id }})"
                                                    class="text-xs text-emerald-600 hover:text-emerald-800 font-medium">
                                                ✏️
                                            </button>
                                        </div>
                                        @if (!empty($priceCat['title']))
                                            <div class="text-xs text-gray-400 mb-3">Прайс: {{ $priceCat['title'] }}</div>
                                        @endif
                                    @endif

                                    {{-- Attributes --}}
                                    @if (count($categoryAttributes) > 0)
                                        <h3 class="text-sm font-semibold text-gray-700 mb-2 mt-3 border-b pb-1">⚙️ Характеристики</h3>
                                        <div class="space-y-2 max-h-64 overflow-y-auto">
                                            @foreach ($categoryAttributes as $attr)
                                                <div class="flex items-center gap-2">
                                                    <label class="text-xs text-gray-600 w-32 flex-shrink-0 truncate"
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
                    @if ($search || $uploadStatusFilter !== '' || $matchFilter !== '')
                        Товари не знайдені за вашими фільтрами
                    @else
                        Товари ще не завантажені. Натисніть "Синхронізувати".
                    @endif
                </div>
            @endforelse
        </div>

    @else
        {{-- ═══════════ TAB 2: Export preparation ═══════════ --}}

        {{-- Export stats --}}
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <div class="bg-white rounded-lg shadow-sm px-4 py-3 flex items-center gap-2">
                <span class="text-2xl font-bold text-gray-800">{{ $notOnRozetkaCount }}</span>
                <span class="text-sm text-gray-500">немає на Розетці</span>
            </div>
            <div class="bg-white rounded-lg shadow-sm px-3 py-2 flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-yellow-500"></span>
                <span class="text-lg font-bold text-yellow-600">{{ $draftCount }}</span>
                <span class="text-xs text-gray-500">Чернетки</span>
            </div>
            <div class="bg-white rounded-lg shadow-sm px-3 py-2 flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                <span class="text-lg font-bold text-emerald-600">{{ $exportReadyCount }}</span>
                <span class="text-xs text-gray-500">Готові до експорту</span>
            </div>
        </div>

        {{-- Filters --}}
        <div class="mb-4 bg-white rounded-lg shadow-sm p-4 flex flex-wrap items-center gap-4">
            <div class="flex-1 min-w-[200px]">
                <input type="text" wire:model.live.debounce.300ms="search"
                       placeholder="Пошук за назвою або артикулом..."
                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
            </div>
            <select wire:model.live="exportStatusFilter"
                    class="px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
                <option value="">Всі статуси</option>
                <option value="draft">Чернетки</option>
                <option value="ready">Готові</option>
            </select>
        </div>

        {{-- Export pipeline products --}}
        @if ($products->count() > 0)
            <h3 class="text-sm font-semibold text-gray-600 mb-2">📋 Підготовлені товари</h3>
            <div class="space-y-2 mb-6">
                @foreach ($products as $product)
                    <div class="bg-white rounded-lg shadow-sm overflow-hidden">
                        <div class="flex items-center gap-4 px-4 py-3">
                            <div class="w-10 h-10 rounded overflow-hidden bg-gray-100 flex-shrink-0">
                                @if ($product->first_photo)
                                    <img src="{{ $product->first_photo }}" alt="" class="w-full h-full object-cover">
                                @else
                                    <div class="w-full h-full flex items-center justify-center text-gray-400 text-xs">📷</div>
                                @endif
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-medium text-gray-800 truncate">{{ $product->title }}</div>
                                <div class="text-xs text-gray-500">{{ $product->article }}</div>
                            </div>
                            <div class="text-sm text-gray-700">{{ number_format($product->price, 0, '.', ' ') }} ₴</div>
                            {{-- Status badge --}}
                            @if ($product->export_status === 'ready')
                                <span class="inline-block px-2 py-0.5 text-xs rounded-full bg-emerald-100 text-emerald-700">Готовий</span>
                            @else
                                <span class="inline-block px-2 py-0.5 text-xs rounded-full bg-yellow-100 text-yellow-700">Чернетка</span>
                            @endif
                            {{-- Actions --}}
                            <div class="flex items-center gap-1">
                                @if ($product->export_status === 'draft')
                                    <button wire:click="markReady({{ $product->id }})" class="text-xs text-emerald-600 hover:text-emerald-800 px-2 py-1 rounded hover:bg-emerald-50" title="Позначити готовим">✓</button>
                                @else
                                    <button wire:click="markDraft({{ $product->id }})" class="text-xs text-yellow-600 hover:text-yellow-800 px-2 py-1 rounded hover:bg-yellow-50" title="Повернути в чернетки">↩</button>
                                @endif
                                <button wire:click="removeFromExport({{ $product->id }})" class="text-xs text-red-500 hover:text-red-700 px-2 py-1 rounded hover:bg-red-50" title="Видалити">✕</button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Local products not on Rozetka --}}
        @if ($localProducts->count() > 0)
            <h3 class="text-sm font-semibold text-gray-600 mb-2">🏪 Товари з каталогу (не на Розетці)</h3>
            <div class="space-y-1">
                @foreach ($localProducts as $lp)
                    <div class="bg-white rounded-lg shadow-sm px-4 py-2 flex items-center gap-4">
                        <div class="flex-1 min-w-0">
                            <div class="text-sm text-gray-800 truncate">{{ $lp->title }}</div>
                            <div class="text-xs text-gray-500">{{ $lp->article }}</div>
                        </div>
                        <div class="text-sm text-gray-700">{{ number_format($lp->price, 0, '.', ' ') }} ₴</div>
                        <button wire:click="prepareForExport({{ $lp->id }})"
                                class="text-xs bg-emerald-50 text-emerald-700 px-3 py-1 rounded hover:bg-emerald-100 transition font-medium">
                            + Додати
                        </button>
                    </div>
                @endforeach
            </div>
        @elseif (!$search)
            <div class="bg-white rounded-lg shadow-sm p-8 text-center text-gray-500">
                Всі товари з каталогу вже є на Розетці 🎉
            </div>
        @endif
    @endif

    {{-- Pagination --}}
    <div class="mt-4">
        {{ $products->links() }}
    </div>
</div>

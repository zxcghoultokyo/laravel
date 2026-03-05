@php
    $raw = $product->raw ?? [];
    $local = $product->localProduct;
    $horoshop = $horoshop ?? $product->horoshopProduct ?? null;
    $commission = $raw['commission_percent'] ?? null;
    $commissionSum = $raw['commission_sum'] ?? null;
    $sold = $raw['sold'] ?? null;
    $priceProducer = $raw['price_producer_name'] ?? null;
    $priceCat = $raw['price_category'] ?? [];
    $errorType = $raw['error_type'] ?? null;
    $errorReason = $raw['error_reason'] ?? null;
    $changes = $raw['changes'] ?? [];
    $duplicateMark = $raw['duplicate_mark'] ?? false;
    $edited = $product->edited_fields ?? [];
    $rawDesc = $raw['description_ua'] ?? $raw['description'] ?? '';
    $rawDesc = is_array($rawDesc) ? implode(' ', $rawDesc) : (string) $rawDesc;
    $localDesc = '';
    if ($local && !empty($local->raw)) {
        $localRaw = is_array($local->raw) ? $local->raw : json_decode($local->raw, true);
        $localDesc = $localRaw['description'] ?? $localRaw['description_short'] ?? '';
        $localDesc = is_array($localDesc) ? implode(' ', $localDesc) : (string) $localDesc;
    }
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
            @php $priceDiff = $product->price - ($local->price ?? 0); @endphp
            @if ($priceDiff != 0)
                <span class="text-xs px-2 py-0.5 rounded {{ $priceDiff > 0 ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700' }}">
                    Різниця: {{ $priceDiff > 0 ? '+' : '' }}{{ number_format($priceDiff, 0, '.', ' ') }} ₴
                </span>
            @endif
        </div>
    @else
        <div class="mb-4 px-3 py-2 bg-amber-50 border border-amber-200 rounded-lg flex items-center gap-2">
            <span class="text-amber-600 text-lg">❌</span>
            <span class="text-sm text-amber-800">
                <strong>Не зв'язано з базою</strong> — артикул {{ $product->article }} не знайдено в каталозі.
            </span>
        </div>
    @endif

    {{-- Action buttons --}}
    <div class="mb-4 flex items-center gap-2 flex-wrap">
        {{-- Push to Rozetka --}}
        @if ($product->rozetka_item_id || ($product->raw['item_id'] ?? null))
            <button wire:click="pushToRozetka({{ $product->id }}, false)"
                    wire:loading.attr="disabled"
                    wire:target="pushToRozetka"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 transition disabled:opacity-50">
                <span wire:loading.remove wire:target="pushToRozetka">🚀 Відправити на Розетку</span>
                <span wire:loading wire:target="pushToRozetka">⏳ Відправляю...</span>
            </button>
            <button wire:click="pushToRozetka({{ $product->id }}, true)"
                    wire:loading.attr="disabled"
                    wire:target="pushToRozetka"
                    class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition disabled:opacity-50"
                    title="Відправити і автоматично створити заявку на модерацію">
                <span wire:loading.remove wire:target="pushToRozetka">📋 Відправити + Модерація</span>
                <span wire:loading wire:target="pushToRozetka">⏳...</span>
            </button>
        @endif

        @if ($product->has_local_changes)
            <span class="inline-flex items-center gap-1 px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-700">
                ● Є локальні зміни
            </span>
            <button wire:click="discardChanges({{ $product->id }})"
                    class="text-xs text-gray-500 hover:text-red-600 px-2 py-1 rounded hover:bg-red-50 transition">
                ↩ Скасувати зміни
            </button>
        @endif
        @if ($product->url)
            <a href="{{ $product->url }}" target="_blank" rel="noopener"
               class="text-xs text-emerald-600 hover:text-emerald-800 hover:underline ml-auto">
                Відкрити на Розетці ↗
            </a>
        @endif
    </div>

    {{-- Push feedback --}}
    @if ($pushMessage ?? '')
        <div class="mb-4 px-3 py-2 rounded-lg text-sm {{ $pushSuccess ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-red-50 border border-red-200 text-red-700' }}">
            {{ $pushSuccess ? '✅' : '❌' }} {{ $pushMessage }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-[1fr_2fr] gap-6">
        {{-- LEFT: Photos + Status --}}
        <div>
            {{-- Photos --}}
            @if (count($product->clean_photo_urls) > 0)
                <div class="mb-4">
                    <img src="{{ $product->clean_photo_urls[0] }}"
                         class="w-full max-h-48 rounded-lg object-contain bg-white border border-gray-200" alt="">
                    @if (count($product->clean_photo_urls) > 1)
                        <div class="flex gap-1.5 mt-2 overflow-x-auto">
                            @foreach (array_slice($product->clean_photo_urls, 1, 6) as $photoUrl)
                                <img src="{{ $photoUrl }}" class="w-14 h-14 rounded object-cover border border-gray-200 flex-shrink-0" alt="">
                            @endforeach
                        </div>
                    @endif
                </div>
            @else
                <div class="mb-4 w-full h-32 bg-gray-100 rounded-lg flex items-center justify-center text-gray-400">Немає фото</div>
            @endif

            {{-- Flags --}}
            <div class="flex flex-wrap gap-1.5 mb-3">
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
            </div>

            {{-- Status --}}
            <h3 class="text-sm font-semibold text-gray-700 mb-2 border-b pb-1">📊 Статус</h3>
            <div class="space-y-1.5 text-sm mb-4">
                <div class="grid grid-cols-[100px_1fr] gap-1">
                    <span class="text-gray-500 text-xs">Статус</span>
                    @if ($product->upload_status === 2)
                        <span class="inline-block px-2 py-0.5 text-xs rounded-full bg-emerald-100 text-emerald-700 w-fit">{{ $product->upload_status_title ?? 'Активний' }}</span>
                    @elseif ($product->upload_status === 0)
                        <span class="inline-block px-2 py-0.5 text-xs rounded-full bg-blue-100 text-blue-700 w-fit">{{ $product->upload_status_title ?? 'Новий' }}</span>
                    @elseif ($product->upload_status === 9)
                        <span class="inline-block px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-700 w-fit">{{ $product->upload_status_title ?? 'Модерація ✗' }}</span>
                    @else
                        <span class="text-xs text-gray-500">{{ $product->upload_status_title ?? '—' }}</span>
                    @endif
                </div>
                <div class="grid grid-cols-[100px_1fr] gap-1">
                    <span class="text-gray-500 text-xs">Rozetka ID</span>
                    <span class="text-gray-800 text-xs font-mono">{{ $product->rozetka_item_id ?? '—' }}</span>
                </div>
                <div class="grid grid-cols-[100px_1fr] gap-1">
                    <span class="text-gray-500 text-xs">Offer ID</span>
                    <span class="text-gray-800 text-xs font-mono">{{ $product->price_offer_id ?? ($raw['price_offer_id'] ?? '—') }}</span>
                </div>
                @if ($commission)
                <div class="grid grid-cols-[100px_1fr] gap-1">
                    <span class="text-gray-500 text-xs">Комісія</span>
                    <span class="text-gray-800 text-xs">{{ $commission }}% ({{ $commissionSum }} ₴)</span>
                </div>
                @endif
                @if ($sold !== null)
                <div class="grid grid-cols-[100px_1fr] gap-1">
                    <span class="text-gray-500 text-xs">Продано</span>
                    <span class="text-gray-800 text-xs">{{ $sold }} шт</span>
                </div>
                @endif
                <div class="grid grid-cols-[100px_1fr] gap-1">
                    <span class="text-gray-500 text-xs">Синхронізовано</span>
                    <span class="text-gray-800 text-xs">{{ $product->synced_at?->format('d.m.Y H:i') ?? '—' }}</span>
                </div>
            </div>

            {{-- Errors --}}
            @if ($errorType || $errorReason)
                <div class="p-2 bg-red-50 rounded text-xs text-red-700 mb-3">
                    <strong>Помилка:</strong> {{ $errorType }} — {{ $errorReason }}
                </div>
            @endif

            {{-- Changes --}}
            @if (!empty($changes['changed_fields']) || !empty($changes['reasons']))
                <div class="p-2 bg-blue-50 rounded text-xs text-blue-700">
                    <strong>Зміни:</strong>
                    @if (!empty($changes['changed_fields']))
                        {{ is_array($changes['changed_fields']) ? implode(', ', $changes['changed_fields']) : $changes['changed_fields'] }}
                    @endif
                    @if (!empty($changes['reasons']))
                        <br>Причини: {{ implode(', ', $changes['reasons']) }}
                    @endif
                </div>
            @endif
        </div>

        {{-- RIGHT: Dual-row editable fields --}}
        <div>
            <h3 class="text-sm font-semibold text-gray-700 mb-3 border-b pb-1">📋 Дані товару <span class="text-xs font-normal text-gray-400">(Horoshop → Розетка)</span></h3>

            <div class="space-y-3">
                {{-- Title --}}
                <div class="bg-white rounded-lg border border-gray-200 p-3">
                    <label class="text-xs font-medium text-gray-500 mb-1 block">Назва</label>
                    @if ($local)
                        <div class="text-xs text-purple-600 bg-purple-50 rounded px-2 py-1 mb-1.5 truncate" title="{{ $local->title }}">
                            🏪 {{ $local->title }}
                        </div>
                    @endif
                    <input type="text"
                           value="{{ $product->title }}"
                           wire:blur="saveProductField({{ $product->id }}, 'title', $event.target.value)"
                           class="w-full px-2 py-1.5 border {{ isset($edited['title']) ? 'border-yellow-400 bg-yellow-50' : 'border-gray-300' }} rounded text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"
                           placeholder="Назва товару на Розетці">
                </div>

                {{-- Description --}}
                <div class="bg-white rounded-lg border border-gray-200 p-3">
                    <label class="text-xs font-medium text-gray-500 mb-1 block">Опис</label>
                    @if ($local && $localDesc)
                        <div class="text-xs text-purple-600 bg-purple-50 rounded px-2 py-1 mb-1.5 max-h-16 overflow-y-auto">
                            🏪 {{ Str::limit(strip_tags($localDesc), 200) }}
                        </div>
                    @endif
                    <textarea wire:blur="saveProductField({{ $product->id }}, 'description', $event.target.value)"
                              rows="3"
                              class="w-full px-2 py-1.5 border {{ isset($edited['description']) ? 'border-yellow-400 bg-yellow-50' : 'border-gray-300' }} rounded text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"
                              placeholder="Опис товару на Розетці">{{ $product->description ?? $rawDesc }}</textarea>
                </div>

                {{-- Price + Price Old (side by side) --}}
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-white rounded-lg border border-gray-200 p-3">
                        <label class="text-xs font-medium text-gray-500 mb-1 block">Ціна</label>
                        @if ($local)
                            <div class="text-xs text-purple-600 bg-purple-50 rounded px-2 py-1 mb-1.5">
                                🏪 {{ number_format($local->price, 0, '.', ' ') }} ₴
                            </div>
                        @endif
                        <div class="flex items-center gap-1">
                            <input type="number"
                                   value="{{ $product->price }}"
                                   wire:blur="saveProductField({{ $product->id }}, 'price', $event.target.value)"
                                   class="flex-1 px-2 py-1.5 border {{ isset($edited['price']) ? 'border-yellow-400 bg-yellow-50' : 'border-gray-300' }} rounded text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                   step="0.01">
                            <span class="text-xs text-gray-400">₴</span>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-3">
                        <label class="text-xs font-medium text-gray-500 mb-1 block">Стара ціна</label>
                        @if ($local && $local->price_old)
                            <div class="text-xs text-purple-600 bg-purple-50 rounded px-2 py-1 mb-1.5">
                                🏪 {{ number_format($local->price_old, 0, '.', ' ') }} ₴
                            </div>
                        @endif
                        <div class="flex items-center gap-1">
                            <input type="number"
                                   value="{{ $product->price_old }}"
                                   wire:blur="saveProductField({{ $product->id }}, 'price_old', $event.target.value)"
                                   class="flex-1 px-2 py-1.5 border {{ isset($edited['price_old']) ? 'border-yellow-400 bg-yellow-50' : 'border-gray-300' }} rounded text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                   step="0.01"
                                   placeholder="—">
                            <span class="text-xs text-gray-400">₴</span>
                        </div>
                    </div>
                </div>

                {{-- Quantity + Availability --}}
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-white rounded-lg border border-gray-200 p-3">
                        <label class="text-xs font-medium text-gray-500 mb-1 block">Кількість</label>
                        @if ($local)
                            <div class="text-xs text-purple-600 bg-purple-50 rounded px-2 py-1 mb-1.5">
                                🏪 {{ $local->quantity ?? 0 }} шт
                            </div>
                        @endif
                        <div class="flex items-center gap-1">
                            <input type="number"
                                   value="{{ $product->quantity }}"
                                   wire:blur="saveProductField({{ $product->id }}, 'quantity', $event.target.value)"
                                   class="flex-1 px-2 py-1.5 border {{ isset($edited['quantity']) ? 'border-yellow-400 bg-yellow-50' : 'border-gray-300' }} rounded text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                   min="0">
                            <span class="text-xs text-gray-400">шт</span>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-3">
                        <label class="text-xs font-medium text-gray-500 mb-1 block">Наявність</label>
                        @if ($local)
                            <div class="text-xs text-purple-600 bg-purple-50 rounded px-2 py-1 mb-1.5">
                                🏪 {{ $local->in_stock ? 'В наявності' : 'Немає' }}
                            </div>
                        @endif
                        <div class="text-sm {{ $product->in_stock ? 'text-emerald-700' : 'text-gray-500' }}">
                            {{ $product->available_title ?? ($product->in_stock ? 'В наявності' : 'Немає') }}
                        </div>
                    </div>
                </div>

                {{-- Producer --}}
                <div class="bg-white rounded-lg border border-gray-200 p-3">
                    <label class="text-xs font-medium text-gray-500 mb-1 block">Виробник</label>
                    @if ($local && !empty($local->raw))
                        @php
                            $localRaw2 = is_array($local->raw) ? $local->raw : json_decode($local->raw, true);
                            $localBrand = $localRaw2['brand'] ?? $localRaw2['producer'] ?? $localRaw2['vendor'] ?? null;
                        @endphp
                        @if ($localBrand)
                            <div class="text-xs text-purple-600 bg-purple-50 rounded px-2 py-1 mb-1.5">
                                🏪 {{ $localBrand }}
                            </div>
                        @endif
                    @endif
                    <input type="text"
                           value="{{ $product->producer_name ?? '' }}"
                           wire:blur="saveProductField({{ $product->id }}, 'producer_name', $event.target.value)"
                           class="w-full px-2 py-1.5 border {{ isset($edited['producer_name']) ? 'border-yellow-400 bg-yellow-50' : 'border-gray-300' }} rounded text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"
                           placeholder="Виробник">
                    @if ($priceProducer && $priceProducer !== $product->producer_name)
                        <div class="text-xs text-gray-400 mt-1">Прайс: {{ $priceProducer }}</div>
                    @endif
                </div>

                {{-- Article (read-only) --}}
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-white rounded-lg border border-gray-200 p-3">
                        <label class="text-xs font-medium text-gray-500 mb-1 block">Артикул</label>
                        <div class="text-sm text-gray-800 font-mono">{{ $product->article }}</div>
                    </div>
                    @if ($product->parent_article)
                        <div class="bg-white rounded-lg border border-gray-200 p-3">
                            <label class="text-xs font-medium text-gray-500 mb-1 block">Батьківський артикул</label>
                            <div class="text-sm text-gray-800 font-mono">{{ $product->parent_article }}</div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Category --}}
            <h3 class="text-sm font-semibold text-gray-700 mb-2 mt-4 border-b pb-1">📁 Категорія</h3>
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
                            class="text-xs text-emerald-600 hover:text-emerald-800 font-medium">✏️</button>
                </div>
                @if (!empty($priceCat['title']))
                    <div class="text-xs text-gray-400 mb-2">Прайс: {{ $priceCat['title'] }}</div>
                @endif
            @endif

            {{-- Attributes --}}
            @if (count($categoryAttributes) > 0)
                <div class="flex items-center gap-2 mt-3 border-b pb-1">
                    <h3 class="text-sm font-semibold text-gray-700">⚙️ Характеристики</h3>
                    <span class="text-xs text-gray-400">({{ count($categoryAttributes) }})</span>
                    <button wire:click="refreshAttributes({{ $product->id }})"
                            wire:loading.attr="disabled"
                            wire:target="refreshAttributes"
                            class="ml-auto text-xs text-gray-500 hover:text-emerald-600 px-2 py-0.5 rounded hover:bg-emerald-50 transition">
                        <span wire:loading.remove wire:target="refreshAttributes">🔄 Оновити</span>
                        <span wire:loading wire:target="refreshAttributes">⏳...</span>
                    </button>
                </div>
                <div class="space-y-2 max-h-72 overflow-y-auto">
                    @foreach ($categoryAttributes as $attr)
                        <div class="flex items-center gap-2">
                            <label class="text-xs text-gray-600 w-36 flex-shrink-0 truncate" title="{{ $attr['name'] }}">
                                {{ $attr['name'] }}
                                @if ($attr['filter_type'] === 'main')
                                    <span class="text-red-500">*</span>
                                @endif
                            </label>
                            @php $saved = $productAttributes[$attr['id']] ?? null; @endphp
                            @if (in_array($attr['attr_type'], ['ComboBox', 'ListValues', 'List']))
                                <select wire:change="saveAttribute({{ $product->id }}, {{ $attr['id'] }}, '{{ addslashes($attr['name']) }}', $event.target.value, null)"
                                        class="flex-1 px-2 py-1 border border-gray-300 rounded text-xs focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                    <option value="">—</option>
                                    @foreach ($attr['values'] ?? [] as $val)
                                        <option value="{{ $val['id'] }}" {{ ($saved['value_id'] ?? null) == $val['id'] ? 'selected' : '' }}>{{ $val['name'] }}</option>
                                    @endforeach
                                </select>
                            @elseif (in_array($attr['attr_type'], ['TextInput', 'TextArea', 'MultiText']))
                                <input type="text" value="{{ $saved['value_text'] ?? '' }}"
                                       wire:blur="saveAttribute({{ $product->id }}, {{ $attr['id'] }}, '{{ addslashes($attr['name']) }}', null, $event.target.value)"
                                       class="flex-1 px-2 py-1 border border-gray-300 rounded text-xs focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                       placeholder="{{ $attr['unit'] ? 'у ' . $attr['unit'] : '' }}">
                            @elseif (in_array($attr['attr_type'], ['Decimal', 'Integer']))
                                <input type="number" value="{{ $saved['value_text'] ?? '' }}"
                                       wire:blur="saveAttribute({{ $product->id }}, {{ $attr['id'] }}, '{{ addslashes($attr['name']) }}', null, $event.target.value)"
                                       class="flex-1 px-2 py-1 border border-gray-300 rounded text-xs focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                       placeholder="{{ $attr['unit'] ?? '' }}" step="{{ $attr['attr_type'] === 'Decimal' ? '0.01' : '1' }}">
                            @elseif ($attr['attr_type'] === 'CheckBoxGroupValues')
                                <div class="flex-1 flex flex-wrap gap-1">
                                    @foreach (array_slice($attr['values'] ?? [], 0, 8) as $val)
                                        <label class="flex items-center gap-1 text-xs">
                                            <input type="checkbox" value="{{ $val['id'] }}" class="rounded text-emerald-600"> {{ $val['name'] }}
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

    {{-- HOROSHOP DATA PANEL --}}
    @if ($horoshop)
        <div class="mt-4 border-t border-purple-200 pt-4" x-data="{ hsOpen: false }">
            <button @click="hsOpen = !hsOpen" class="flex items-center gap-2 text-sm font-semibold text-purple-700 hover:text-purple-900 transition w-full text-left">
                <span>🛍️ Дані з Хорошоп</span>
                <span class="text-xs font-normal text-purple-400">({{ $horoshop->article }})</span>
                <svg class="w-4 h-4 transition-transform ml-auto" :class="hsOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div x-show="hsOpen" x-collapse class="mt-3 space-y-3">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    {{-- HS: Basic info --}}
                    <div class="space-y-2">
                        <div class="bg-purple-50 rounded-lg border border-purple-100 p-3">
                            <label class="text-xs font-medium text-purple-500 mb-1 block">Назва (Хорошоп)</label>
                            <div class="text-sm text-purple-900">{{ $horoshop->title }}</div>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div class="bg-purple-50 rounded-lg border border-purple-100 p-3">
                                <label class="text-xs font-medium text-purple-500 mb-1 block">Ціна</label>
                                <div class="text-sm font-bold text-purple-900">{{ number_format($horoshop->price, 0, '.', ' ') }} ₴</div>
                                @if ($horoshop->price_old)
                                    <div class="text-xs text-purple-400 line-through">{{ number_format($horoshop->price_old, 0, '.', ' ') }} ₴</div>
                                @endif
                            </div>
                            <div class="bg-purple-50 rounded-lg border border-purple-100 p-3">
                                <label class="text-xs font-medium text-purple-500 mb-1 block">Наявність</label>
                                <div class="text-sm {{ $horoshop->in_stock ? 'text-emerald-700' : 'text-gray-500' }}">
                                    {{ $horoshop->in_stock ? 'В наявності' : 'Немає' }}
                                    @if ($horoshop->quantity)
                                        <span class="text-xs text-purple-400">({{ $horoshop->quantity }} шт)</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        @if ($horoshop->brand || $horoshop->color || $horoshop->size)
                            <div class="flex flex-wrap gap-2">
                                @if ($horoshop->brand)
                                    <span class="px-2 py-0.5 text-xs rounded-full bg-purple-100 text-purple-700">{{ $horoshop->brand }}</span>
                                @endif
                                @if ($horoshop->color)
                                    <span class="px-2 py-0.5 text-xs rounded-full bg-purple-100 text-purple-700">🎨 {{ $horoshop->color }}</span>
                                @endif
                                @if ($horoshop->size)
                                    <span class="px-2 py-0.5 text-xs rounded-full bg-purple-100 text-purple-700">📏 {{ $horoshop->size }}</span>
                                @endif
                            </div>
                        @endif

                        @if ($horoshop->category_path)
                            <div class="bg-purple-50 rounded-lg border border-purple-100 p-3">
                                <label class="text-xs font-medium text-purple-500 mb-1 block">Категорія</label>
                                <div class="text-xs text-purple-700">{{ $horoshop->category_path }}</div>
                            </div>
                        @endif
                    </div>

                    {{-- HS: Description + Images --}}
                    <div class="space-y-2">
                        @if ($horoshop->description_ua)
                            <div class="bg-purple-50 rounded-lg border border-purple-100 p-3">
                                <label class="text-xs font-medium text-purple-500 mb-1 block">Опис (UA)</label>
                                <div class="text-xs text-purple-800 max-h-24 overflow-y-auto">
                                    {!! Str::limit(strip_tags($horoshop->description_ua), 500) !!}
                                </div>
                            </div>
                        @endif

                        {{-- Horoshop images --}}
                        @php
                            $hsImages = $horoshop->images ?? [];
                            $hsImgUrls = [];
                            foreach ($hsImages as $img) {
                                if (is_string($img)) { $hsImgUrls[] = $img; }
                                elseif (is_array($img)) { $hsImgUrls[] = $img['url'] ?? $img['link'] ?? ''; }
                            }
                            $hsImgUrls = array_filter($hsImgUrls);
                        @endphp
                        @if (count($hsImgUrls) > 0)
                            <div class="bg-purple-50 rounded-lg border border-purple-100 p-3">
                                <label class="text-xs font-medium text-purple-500 mb-1 block">Фото Хорошоп ({{ count($hsImgUrls) }})</label>
                                <div class="flex gap-1.5 overflow-x-auto">
                                    @foreach (array_slice($hsImgUrls, 0, 8) as $imgUrl)
                                        <img src="{{ $imgUrl }}" class="w-14 h-14 rounded object-cover border border-purple-200 flex-shrink-0" alt="">
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- HS: Characteristics --}}
                @if (!empty($horoshop->characteristics))
                    <div class="bg-purple-50 rounded-lg border border-purple-100 p-3">
                        <label class="text-xs font-medium text-purple-500 mb-2 block">Характеристики Хорошоп</label>
                        <div class="grid grid-cols-2 lg:grid-cols-3 gap-1.5 max-h-48 overflow-y-auto">
                            @foreach ($horoshop->characteristics as $char)
                                @php
                                    $charName = is_array($char) ? ($char['name'] ?? $char['title'] ?? '—') : '';
                                    $charVal = is_array($char) ? ($char['value'] ?? '—') : $char;
                                    if (is_array($charVal)) {
                                        $charVal = $charVal['ua'] ?? $charVal['ru'] ?? json_encode($charVal, JSON_UNESCAPED_UNICODE);
                                    }
                                @endphp
                                <div class="text-xs">
                                    <span class="text-purple-500">{{ $charName }}:</span>
                                    <span class="text-purple-800 font-medium">{{ $charVal }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($horoshop->synced_at)
                    <div class="text-xs text-purple-400 text-right">
                        Синхронізовано: {{ $horoshop->synced_at->format('d.m.Y H:i') }}
                    </div>
                @endif
            </div>
        </div>
    @else
        <div class="mt-4 border-t border-gray-100 pt-3">
            <p class="text-xs text-gray-400 italic">🛍️ Дані Хорошоп не завантажені. Синхронізуйте каталог Хорошоп.</p>
        </div>
    @endif
</div>

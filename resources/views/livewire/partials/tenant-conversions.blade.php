<div>
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-xl font-bold text-gray-900">🎯 Конверсії</h2>
            <p class="text-sm text-gray-500 mt-1">Повна воронка: від відвідувача до замовлення</p>
        </div>
        
        <div class="flex items-center gap-4">
            <select wire:model.live="conversionsDays" class="rounded-lg border-gray-300 text-sm">
                <option value="1">Сьогодні</option>
                <option value="7">7 днів</option>
                <option value="30">30 днів</option>
                <option value="90">90 днів</option>
            </select>
            
            <button wire:click="loadConversionsData" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 flex items-center gap-2">
                <svg wire:loading wire:target="loadConversionsData" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span wire:loading.remove wire:target="loadConversionsData">🔄</span>
                Оновити
            </button>
        </div>
    </div>

    <!-- Sub-tabs -->
    <div class="flex gap-1 mb-6 bg-gray-100 p-1 rounded-lg w-fit">
        <button wire:click="setConversionsTab('funnel')" 
                class="px-4 py-2 rounded-md text-sm font-medium transition {{ $conversionsActiveTab === 'funnel' ? 'bg-white shadow text-blue-600' : 'text-gray-600 hover:text-gray-900' }}">
            🔄 Воронка
        </button>
        @php
            // Tab counts: show only chat-attributed conversions (not all events)
            $chatCartCount = count($chatAttributedConversions);
            $chatOrderCount = count($checkoutOrders);
        @endphp
        <button wire:click="setConversionsTab('cart')" 
                class="px-4 py-2 rounded-md text-sm font-medium transition {{ $conversionsActiveTab === 'cart' ? 'bg-white shadow text-blue-600' : 'text-gray-600 hover:text-gray-900' }}">
            🛒 Кошик ({{ $chatCartCount }})
        </button>
        <button wire:click="setConversionsTab('orders')" 
                class="px-4 py-2 rounded-md text-sm font-medium transition {{ $conversionsActiveTab === 'orders' ? 'bg-white shadow text-blue-600' : 'text-gray-600 hover:text-gray-900' }}">
            ✅ Замовлення ({{ $chatOrderCount }})
        </button>
    </div>

    <!-- Funnel Tab -->
    @if($conversionsActiveTab === 'funnel')
        <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-lg font-semibold text-gray-900">Воронка конверсії</h3>
                @php
                    $stages = $funnelData['stages'] ?? [];
                    $firstStage = $stages[0]['count'] ?? 0;
                    $lastStage = count($stages) > 0 ? ($stages[count($stages) - 1]['count'] ?? 0) : 0;
                    $overallRate = $funnelData['overall_rate'] ?? 0;
                @endphp
                @if($overallRate > 0)
                    <span class="text-sm bg-green-100 text-green-700 px-3 py-1 rounded-full">
                        {{ $overallRate }}% загальна конверсія
                    </span>
                @endif
            </div>
            
            @if(!empty($funnelData['stages']))
                <div class="space-y-4">
                    @php $maxCount = max(array_column($funnelData['stages'], 'count')) ?: 1; @endphp
                    @foreach($funnelData['stages'] as $index => $stage)
                        <div class="group">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-3">
                                    <span class="text-2xl">{{ $stage['icon'] }}</span>
                                    <div>
                                        <span class="text-base font-medium text-gray-700">{{ $stage['label'] }}</span>
                                        <span class="text-sm text-gray-400 ml-2 hidden group-hover:inline" title="{{ $stage['hint'] }}">
                                            {{ $stage['hint'] }}
                                        </span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="text-xl font-bold text-gray-900">{{ number_format($stage['count']) }}</span>
                                    @if($index > 0 && $stage['rate'] > 0)
                                        <span class="text-sm px-3 py-1 rounded-full font-medium {{ $stage['rate'] >= 50 ? 'bg-green-100 text-green-700' : ($stage['rate'] >= 20 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') }}">
                                            {{ $stage['rate'] }}%
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <!-- Progress bar -->
                            <div class="h-8 bg-gray-100 rounded-lg overflow-hidden relative">
                                <div class="h-full rounded-lg transition-all duration-500 {{ $index === count($funnelData['stages']) - 1 ? 'bg-green-500' : 'bg-blue-500' }}"
                                     style="width: {{ ($stage['count'] / $maxCount) * 100 }}%">
                                </div>
                                @if($index > 0 && $stage['dropoff'] > 0)
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-gray-500">
                                        -{{ $stage['dropoff'] }}% відсіялось
                                    </span>
                                @endif
                            </div>
                        </div>
                        @if($index < count($funnelData['stages']) - 1)
                            <div class="flex justify-center py-1">
                                <svg class="w-5 h-5 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </div>
                        @endif
                    @endforeach
                </div>
                
                <!-- Insights -->
                <div class="mt-6 p-4 bg-blue-50 rounded-lg">
                    <h4 class="font-medium text-blue-800 mb-2">💡 Що означають ці числа?</h4>
                    <div class="grid grid-cols-2 gap-4 text-sm text-blue-700">
                        <div>
                            <span class="font-medium">Відвідувачі → Відкрили чат:</span>
                            Скільки людей зацікавились ботом
                        </div>
                        <div>
                            <span class="font-medium">Написали → Клік на товар:</span>
                            Наскільки релевантні відповіді
                        </div>
                        <div>
                            <span class="font-medium">Клік → Кошик:</span>
                            Ефективність карток товарів
                        </div>
                        <div>
                            <span class="font-medium">Кошик → Замовлення:</span>
                            Ефективність checkout процесу
                        </div>
                    </div>
                </div>
            @else
                <div class="text-center py-12 text-gray-400">
                    <span class="text-4xl">📊</span>
                    <p class="mt-2">Ще немає даних воронки</p>
                </div>
            @endif
        </div>
    @endif

    <!-- Cart Tab -->
    @if($conversionsActiveTab === 'cart')
        @php
            // Only count chat-attributed carts (where user had chat interaction before adding to cart)
            $chatCarts = collect($chatAttributedConversions);
            $uniqueBuyers = $chatCarts->pluck('session_id')->unique()->count();
            // Each cart event = 1 item (add_to_cart fires per product)
            $totalItems = $chatCarts->count();
            $avgItemsPerBuyer = $uniqueBuyers > 0 ? round($totalItems / $uniqueBuyers, 1) : 0;
            // Sum product prices - handle string/null values
            $totalCartSum = $chatCarts->reduce(function($sum, $c) {
                $price = $c['product_price'] ?? null;
                if ($price === null) return $sum;
                return $sum + (float) str_replace([' ', ','], ['', '.'], (string)$price);
            }, 0);
        @endphp
        <div class="grid grid-cols-4 gap-4 mb-6">
            <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-lg shadow p-4 border border-green-200">
                <div class="text-3xl font-bold text-green-700">{{ count($chatAttributedConversions) }}</div>
                <div class="text-sm text-green-600">🛒 Кошиків з чату</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-3xl font-bold text-blue-600">{{ $uniqueBuyers }}</div>
                <div class="text-sm text-gray-500">👤 Унікальних покупців</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-3xl font-bold text-purple-600">{{ $avgItemsPerBuyer }}</div>
                <div class="text-sm text-gray-500">📦 Товарів на покупця</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-3xl font-bold text-gray-700">{{ number_format($totalCartSum, 0, ',', ' ') }} ₴</div>
                <div class="text-sm text-gray-500">💰 Сума кошиків</div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-6">
            <!-- Conversions List -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-4 border-b border-gray-200">
                    <h2 class="font-semibold text-gray-700">📋 Конверсії пов'язані з чатом</h2>
                </div>
                <div class="max-h-[600px] overflow-y-auto">
                    @forelse($chatAttributedConversions as $conv)
                        <div class="p-4 border-b border-gray-100 hover:bg-gray-50 cursor-pointer {{ $selectedConversionSessionId === $conv['session_id'] ? 'bg-blue-50' : '' }}"
                             wire:click="viewConversionSession('{{ $conv['session_id'] }}')">
                            <div class="flex justify-between items-start">
                                <div>
                                    <div class="text-sm font-medium text-gray-900">
                                        @if($conv['product_title'])
                                            {{ Str::limit($conv['product_title'], 50) }}
                                        @else
                                            Товар #{{ $conv['product_id'] ?? $conv['product_article'] ?? 'Unknown' }}
                                        @endif
                                    </div>
                                    @if($conv['product_article'])
                                        <div class="text-xs text-gray-500">Арт: {{ $conv['product_article'] }}</div>
                                    @endif
                                    @if($conv['product_price'])
                                        <div class="text-sm text-green-600 font-medium">{{ number_format($conv['product_price'], 0) }} ₴</div>
                                    @endif
                                    <div class="text-xs text-gray-400 mt-1">
                                        {{ \Carbon\Carbon::parse($conv['created_at'])->format('d.m H:i') }}
                                    </div>
                                </div>
                                <div class="flex flex-col items-end gap-1">
                                    @if($conv['from_chat'])
                                        <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs">
                                            👆 Клікнув в чаті
                                        </span>
                                    @endif
                                    @if($conv['had_chat'])
                                        <span class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded text-xs">
                                            💬 Спілкувався
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="p-8 text-center text-gray-400">
                            <div class="text-4xl mb-2">📭</div>
                            <p>Немає конверсій з чату за цей період</p>
                            <p class="text-xs mt-2">Конверсії з'являться коли користувач поспілкується в чаті, а потім натисне "Купити"</p>
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- Session Detail -->
            <div class="bg-white rounded-lg shadow">
                @if($selectedConversionSession)
                    <div class="p-4 border-b border-gray-200 flex justify-between items-center">
                        <h2 class="font-semibold text-gray-700">🔍 Деталі сесії</h2>
                        <button wire:click="closeConversionSession" class="text-gray-400 hover:text-gray-600">✕</button>
                    </div>
                    <div class="p-4 max-h-[600px] overflow-y-auto">
                        <!-- Session Info -->
                        <div class="mb-4 p-3 bg-gray-50 rounded-lg">
                            <div class="text-xs text-gray-500">Session ID</div>
                            <div class="font-mono text-xs text-gray-700 break-all">{{ $selectedConversionSession['session_id'] }}</div>
                            @if($selectedConversionSession['created_at'])
                                <div class="text-xs text-gray-500 mt-2">Почато: {{ \Carbon\Carbon::parse($selectedConversionSession['created_at'])->format('d.m.Y H:i') }}</div>
                            @endif
                            @if($selectedConversionSession['utm'] ?? null)
                                <div class="mt-2 text-xs">
                                    <span class="text-gray-500">Джерело:</span>
                                    <span class="text-blue-600">{{ $selectedConversionSession['utm']['utm_source'] }}</span>
                                    @if($selectedConversionSession['utm']['utm_campaign'])
                                        <span class="text-gray-400">/</span>
                                        <span class="text-purple-600">{{ $selectedConversionSession['utm']['utm_campaign'] }}</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                        
                        <!-- Checkouts/Orders -->
                        @if(count($selectedConversionSession['checkouts'] ?? []) > 0)
                            <div class="mb-4">
                                <h3 class="text-sm font-semibold text-gray-600 mb-2">✅ Замовлення ({{ count($selectedConversionSession['checkouts']) }})</h3>
                                <div class="space-y-2">
                                    @foreach($selectedConversionSession['checkouts'] as $idx => $checkout)
                                        <div x-data="{ open: false }" class="border border-green-200 rounded overflow-hidden">
                                            <button @click="open = !open" class="w-full p-3 bg-green-50 hover:bg-green-100 flex justify-between items-center text-left">
                                                <div class="flex items-center gap-2">
                                                    <svg class="w-4 h-4 text-green-600 transition-transform" :class="{ 'rotate-90': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                                    </svg>
                                                    <div>
                                                        @if($checkout['order_id'])
                                                            <span class="font-medium text-green-800">Замовлення #{{ $checkout['order_id'] }}</span>
                                                        @else
                                                            <span class="font-medium text-green-800">Оформлення замовлення</span>
                                                        @endif
                                                        <span class="text-xs text-green-600 ml-2">{{ \Carbon\Carbon::parse($checkout['created_at'])->format('d.m H:i') }}</span>
                                                    </div>
                                                </div>
                                                @if($checkout['order_total'])
                                                    <span class="font-bold text-green-700">{{ number_format($checkout['order_total'], 0) }} ₴</span>
                                                @endif
                                            </button>
                                            <div x-show="open" x-collapse class="p-3 bg-white border-t border-green-100 text-sm">
                                                @if($checkout['customer_name'])
                                                    <div class="flex items-center gap-2 text-gray-700">
                                                        <span class="text-gray-400">👤</span>
                                                        <span>{{ $checkout['customer_name'] }}</span>
                                                    </div>
                                                @endif
                                                @if($checkout['customer_phone'])
                                                    <div class="flex items-center gap-2 text-gray-600">
                                                        <span class="text-gray-400">📱</span>
                                                        <a href="tel:{{ $checkout['customer_phone'] }}" class="hover:text-blue-600">{{ $checkout['customer_phone'] }}</a>
                                                    </div>
                                                @endif
                                                @if($checkout['customer_email'])
                                                    <div class="flex items-center gap-2 text-gray-600">
                                                        <span class="text-gray-400">📧</span>
                                                        <span>{{ $checkout['customer_email'] }}</span>
                                                    </div>
                                                @endif
                                                @if($checkout['delivery_type'])
                                                    <div class="flex items-center gap-2 text-gray-600 mt-1">
                                                        <span class="text-gray-400">🚚</span>
                                                        <span>{{ $checkout['delivery_type'] }}</span>
                                                    </div>
                                                @endif
                                                @if($checkout['payment_type'])
                                                    <div class="flex items-center gap-2 text-gray-600">
                                                        <span class="text-gray-400">💳</span>
                                                        <span>{{ $checkout['payment_type'] }}</span>
                                                    </div>
                                                @endif
                                                @if($checkout['items_count'])
                                                    <div class="flex items-center gap-2 text-gray-600 mt-1">
                                                        <span class="text-gray-400">📦</span>
                                                        <span>{{ $checkout['items_count'] }} товарів</span>
                                                    </div>
                                                @endif
                                                @if(!$checkout['customer_name'] && !$checkout['customer_phone'] && !$checkout['items_count'])
                                                    <div class="text-gray-400 text-xs">Деталі замовлення недоступні</div>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        
                        <!-- Chat Messages -->
                        @if(count($selectedConversionSession['messages']) > 0)
                            <div class="mb-4">
                                <h3 class="text-sm font-semibold text-gray-600 mb-2">💬 Розмова</h3>
                                <div class="space-y-2 max-h-48 overflow-y-auto">
                                    @foreach($selectedConversionSession['messages'] as $msg)
                                        <div class="p-2 rounded {{ $msg['role'] === 'user' ? 'bg-blue-50 ml-4' : 'bg-gray-50 mr-4' }}">
                                            <div class="text-xs text-gray-500 mb-1">
                                                {{ $msg['role'] === 'user' ? '👤 Клієнт' : '🤖 Бот' }}
                                                • {{ \Carbon\Carbon::parse($msg['created_at'])->format('H:i') }}
                                            </div>
                                            <div class="text-sm text-gray-700">{{ Str::limit($msg['content'], 200) }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <div class="mb-4 p-3 bg-yellow-50 rounded-lg text-sm text-yellow-700">
                                ⚠️ Повідомлення не знайдено
                            </div>
                        @endif
                        
                        <!-- Products Shown -->
                        @if(count($selectedConversionSession['products_shown']) > 0)
                            <div class="mb-4">
                                <h3 class="text-sm font-semibold text-gray-600 mb-2">👁️ Показані товари ({{ count($selectedConversionSession['products_shown']) }})</h3>
                                <div class="space-y-1">
                                    @foreach($selectedConversionSession['products_shown'] as $product)
                                        <div class="flex justify-between items-center p-2 bg-gray-50 rounded text-sm">
                                            <div>
                                                @if($product['url'] ?? null)
                                                    <a href="{{ $product['url'] }}" target="_blank" class="text-blue-600 hover:underline">{{ Str::limit($product['title'], 40) }}</a>
                                                @else
                                                    <span class="text-gray-700">{{ Str::limit($product['title'], 40) }}</span>
                                                @endif
                                            </div>
                                            @if($product['product_price'] ?? false)
                                                <span class="text-gray-600">{{ number_format($product['product_price'], 0) }} ₴</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        
                        <!-- Products Clicked -->
                        @if(count($selectedConversionSession['products_clicked']) > 0)
                            <div class="mb-4">
                                <h3 class="text-sm font-semibold text-gray-600 mb-2">👆 Клікнув на товари ({{ count($selectedConversionSession['products_clicked']) }})</h3>
                                <div class="space-y-1">
                                    @foreach($selectedConversionSession['products_clicked'] as $product)
                                        <div class="flex justify-between items-center p-2 bg-blue-50 rounded text-sm">
                                            @if($product['url'] ?? null)
                                                <a href="{{ $product['url'] }}" target="_blank" class="text-blue-700 hover:underline">{{ Str::limit($product['title'], 40) }}</a>
                                            @else
                                                <span class="text-blue-700">{{ Str::limit($product['title'], 40) }}</span>
                                            @endif
                                            @if($product['product_price'] ?? false)
                                                <span class="text-blue-600">{{ number_format($product['product_price'], 0) }} ₴</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        
                        <!-- Added to Cart -->
                        @if(count($selectedConversionSession['added_to_cart']) > 0)
                            <div class="mb-4">
                                <h3 class="text-sm font-semibold text-gray-600 mb-2">🛒 Додав в кошик ({{ count($selectedConversionSession['added_to_cart']) }})</h3>
                                <div class="space-y-1">
                                    @foreach($selectedConversionSession['added_to_cart'] as $product)
                                        <div class="flex justify-between items-center p-2 bg-green-50 border border-green-200 rounded text-sm">
                                            <div>
                                                @if($product['url'] ?? null)
                                                    <a href="{{ $product['url'] }}" target="_blank" class="text-green-700 font-medium hover:underline">{{ Str::limit($product['title'], 40) }}</a>
                                                @else
                                                    <span class="text-green-700 font-medium">{{ Str::limit($product['title'], 40) }}</span>
                                                @endif
                                                <div class="text-xs text-green-600">{{ \Carbon\Carbon::parse($product['created_at'])->format('H:i') }}</div>
                                            </div>
                                            @if($product['product_price'] ?? false)
                                                <span class="text-green-700 font-bold">{{ number_format($product['product_price'], 0) }} ₴</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="p-8 text-center text-gray-400">
                        <div class="text-4xl mb-2">👈</div>
                        <p>Виберіть конверсію щоб побачити деталі</p>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- Orders Tab -->
    @if($conversionsActiveTab === 'orders')
        @php
            $totalRevenue = collect($checkoutOrders)->sum('order_total');
            $avgOrder = count($checkoutOrders) > 0 ? $totalRevenue / count($checkoutOrders) : 0;
        @endphp
        <div class="grid grid-cols-3 gap-4 mb-6">
            <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-lg shadow p-4 border border-green-200">
                <div class="text-3xl font-bold text-green-700">{{ count($checkoutOrders) }}</div>
                <div class="text-sm text-green-600">Замовлень після чату</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-3xl font-bold text-blue-600">{{ number_format($totalRevenue, 0, '.', ' ') }} ₴</div>
                <div class="text-sm text-gray-500">Загальна виручка</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-3xl font-bold text-purple-600">{{ number_format($avgOrder, 0, '.', ' ') }} ₴</div>
                <div class="text-sm text-gray-500">Середній чек</div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow">
            <div class="p-4 border-b border-gray-200">
                <h2 class="font-semibold text-gray-700">✅ Замовлення після чату</h2>
                <p class="text-xs text-gray-400 mt-1">Показуються тільки замовлення від клієнтів які спілкувались в чаті</p>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse($checkoutOrders as $checkout)
                    <div x-data="{ expanded: false }" class="hover:bg-gray-50">
                        {{-- Header row --}}
                        <button @click="expanded = !expanded" class="w-full p-4 flex justify-between items-center text-left">
                            <div class="flex items-center gap-3">
                                <svg class="w-5 h-5 text-gray-400 transition-transform" :class="{ 'rotate-90': expanded }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                                <div>
                                    <div class="flex items-center gap-2 flex-wrap">
                                        @if($checkout['order_id'])
                                            <span class="font-medium text-gray-900">Замовлення #{{ $checkout['order_id'] }}</span>
                                        @else
                                            <span class="font-medium text-gray-900">Checkout</span>
                                        @endif
                                        <span class="text-xs px-2 py-0.5 rounded-full {{ $checkout['status'] === 'delivered' ? 'bg-green-100 text-green-700' : ($checkout['status'] === 'cancelled' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700') }}">
                                            {{ $checkout['status_label'] }}
                                        </span>
                                        @if($checkout['payed'] ?? false)
                                            <span class="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700">💰</span>
                                        @endif
                                    </div>
                                    <div class="text-sm text-gray-500 mt-0.5">
                                        {{ \Carbon\Carbon::parse($checkout['created_at'])->format('d.m.Y H:i') }}
                                        @if($checkout['customer_name'])
                                            • {{ $checkout['customer_name'] }}
                                        @endif
                                        @if($checkout['items_count'])
                                            • {{ $checkout['items_count'] }} тов.
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                @if($checkout['order_total'])
                                    <div class="text-lg font-bold text-green-600">{{ number_format($checkout['order_total'], 0) }} ₴</div>
                                @endif
                            </div>
                        </button>
                        
                        {{-- Expanded details --}}
                        <div x-show="expanded" x-collapse class="px-4 pb-4 border-t border-gray-100 bg-gray-50">
                            @php
                                $hasCustomerInfo = $checkout['customer_name'] || $checkout['customer_phone'] || $checkout['customer_email'] || $checkout['customer_city'] || $checkout['customer_address'];
                                $hasDeliveryInfo = $checkout['delivery_type'] || $checkout['payment_type'];
                            @endphp
                            
                            @if($hasCustomerInfo || $hasDeliveryInfo)
                                <div class="grid grid-cols-2 gap-4 pt-3">
                                    {{-- Customer info --}}
                                    @if($hasCustomerInfo)
                                        <div class="space-y-2">
                                            <h4 class="text-xs font-semibold text-gray-500 uppercase">Клієнт</h4>
                                            @if($checkout['customer_name'])
                                                <div class="flex items-center gap-2 text-sm">
                                                    <span class="text-gray-400">👤</span>
                                                    <span class="font-medium text-gray-800">{{ $checkout['customer_name'] }}</span>
                                                </div>
                                            @endif
                                            @if($checkout['customer_phone'])
                                                <div class="flex items-center gap-2 text-sm">
                                                    <span class="text-gray-400">📱</span>
                                                    <a href="tel:{{ $checkout['customer_phone'] }}" class="text-blue-600 hover:underline">{{ $checkout['customer_phone'] }}</a>
                                                </div>
                                            @endif
                                            @if($checkout['customer_email'])
                                                <div class="flex items-center gap-2 text-sm">
                                                    <span class="text-gray-400">📧</span>
                                                    <a href="mailto:{{ $checkout['customer_email'] }}" class="text-blue-600 hover:underline">{{ $checkout['customer_email'] }}</a>
                                                </div>
                                            @endif
                                            @if($checkout['customer_city'] || $checkout['customer_address'])
                                                <div class="flex items-start gap-2 text-sm">
                                                    <span class="text-gray-400">📍</span>
                                                    <span class="text-gray-600">{{ $checkout['customer_city'] }}{{ $checkout['customer_address'] ? ', ' . $checkout['customer_address'] : '' }}</span>
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                    
                                    {{-- Delivery & Payment --}}
                                    @if($hasDeliveryInfo)
                                        <div class="space-y-2">
                                            <h4 class="text-xs font-semibold text-gray-500 uppercase">Доставка та оплата</h4>
                                            @if($checkout['delivery_type'])
                                                <div class="flex items-center gap-2 text-sm">
                                                    <span class="text-gray-400">🚚</span>
                                                    <span class="text-gray-700">{{ $checkout['delivery_type'] }}</span>
                                                    @if(($checkout['delivery_price'] ?? 0) > 0)
                                                        <span class="text-gray-500">({{ number_format($checkout['delivery_price'], 0) }} ₴)</span>
                                                    @endif
                                                </div>
                                            @endif
                                            @if($checkout['delivery_comment'] ?? null)
                                                <div class="text-xs text-gray-500 pl-6">{{ $checkout['delivery_comment'] }}</div>
                                            @endif
                                            @if($checkout['payment_type'])
                                                <div class="flex items-center gap-2 text-sm">
                                                    <span class="text-gray-400">💳</span>
                                                    <span class="text-gray-700">{{ $checkout['payment_type'] }}</span>
                                                    @if($checkout['payed'] ?? false)
                                                        <span class="text-green-600 text-xs">(оплачено)</span>
                                                    @endif
                                                </div>
                                            @endif
                                            @if(($checkout['source'] ?? null) === 'horoshop')
                                                <div class="flex items-center gap-2 text-xs text-purple-600 mt-2">
                                                    <span>📦</span>
                                                    <span>Синхронізовано з Horoshop</span>
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @else
                                <div class="pt-3 text-center text-gray-400 text-sm">
                                    <p>ℹ️ Детальна інформація буде доступна після синхронізації з Horoshop</p>
                                </div>
                            @endif
                            
                            {{-- Products list (lazy loading) --}}
                            @if($checkout['has_products'] ?? false)
                                <div class="mt-4 pt-3 border-t border-gray-200">
                                    <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2">Товари</h4>
                                    @if($selectedCheckoutId === $checkout['id'])
                                        <div class="space-y-1">
                                            @foreach($selectedCheckoutProducts as $product)
                                                <div class="flex justify-between items-center p-2 bg-white rounded border text-sm">
                                                    <div class="flex-1">
                                                        @if($product['url'] ?? null)
                                                            <a href="{{ $product['url'] }}" target="_blank" class="text-blue-600 hover:underline">{{ $product['title'] }}</a>
                                                        @else
                                                            <span class="text-gray-800">{{ $product['title'] }}</span>
                                                        @endif
                                                        @if($product['article'])
                                                            <span class="text-gray-400 text-xs ml-2">[{{ $product['article'] }}]</span>
                                                        @endif
                                                        @if(($product['quantity'] ?? 1) > 1)
                                                            <span class="text-gray-500 ml-2">× {{ $product['quantity'] }}</span>
                                                        @endif
                                                    </div>
                                                    @if($product['price'])
                                                        <span class="text-gray-700 font-medium ml-4">{{ number_format($product['price'], 0) }} ₴</span>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                        <button wire:click="closeConversionCheckoutProducts" class="mt-2 text-xs text-gray-500 hover:text-gray-700">
                                            Згорнути товари
                                        </button>
                                    @else
                                        <button wire:click="loadConversionCheckoutProducts({{ $checkout['id'] }})" class="text-sm text-blue-600 hover:text-blue-800 flex items-center gap-1">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                                            </svg>
                                            Показати {{ $checkout['items_count'] }} товарів
                                        </button>
                                    @endif
                                </div>
                            @endif
                            
                            {{-- Chat link — navigates to Chats tab with this session --}}
                            @if($checkout['session_id'])
                                <div class="mt-3 pt-3 border-t border-gray-200">
                                    <button wire:click="openChatFromEvent('{{ $checkout['session_id'] }}')" 
                                            class="text-sm text-blue-600 hover:underline inline-flex items-center gap-1">
                                        💬 Перейти до чату
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                                        </svg>
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="p-12 text-center text-gray-400">
                        <div class="text-5xl mb-3">📭</div>
                        <p class="text-lg">Ще немає замовлень після чату</p>
                        <p class="text-sm mt-2">Замовлення з'являться коли хтось спілкується в чаті а потім оформить покупку</p>
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    {{-- Session Detail Modal (shows when viewing session from Orders tab) --}}
    @if($selectedConversionSession && $conversionsActiveTab === 'orders')
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="session-modal" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                {{-- Background overlay --}}
                <div wire:click="closeConversionSession" class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>

                {{-- Modal panel --}}
                <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full">
                    <div class="bg-white">
                        <div class="p-4 border-b border-gray-200 flex justify-between items-center">
                            <h2 class="font-semibold text-gray-700">🔍 Деталі сесії</h2>
                            <button wire:click="closeConversionSession" class="text-gray-400 hover:text-gray-600">✕</button>
                        </div>
                        <div class="p-4 max-h-[70vh] overflow-y-auto">
                            {{-- Session Info --}}
                            <div class="mb-4 p-3 bg-gray-50 rounded-lg">
                                <div class="font-mono text-xs text-gray-700 break-all">{{ $selectedConversionSession['session_id'] }}</div>
                                @if($selectedConversionSession['created_at'])
                                    <div class="text-xs text-gray-500 mt-2">Почато: {{ \Carbon\Carbon::parse($selectedConversionSession['created_at'])->format('d.m.Y H:i') }}</div>
                                @endif
                                @if($selectedConversionSession['utm'] ?? null)
                                    <div class="text-xs mt-2">
                                        UTM: 
                                        <span class="text-blue-600">{{ $selectedConversionSession['utm']['utm_source'] }}</span>
                                        @if($selectedConversionSession['utm']['utm_campaign'])
                                            / 
                                            <span class="text-purple-600">{{ $selectedConversionSession['utm']['utm_campaign'] }}</span>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            {{-- Checkouts/Orders --}}
                            @if(count($selectedConversionSession['checkouts'] ?? []) > 0)
                                <div class="mb-4">
                                    <h3 class="text-sm font-semibold text-gray-600 mb-2">✅ Замовлення ({{ count($selectedConversionSession['checkouts']) }})</h3>
                                    <div class="space-y-2">
                                        @foreach($selectedConversionSession['checkouts'] as $idx => $checkout)
                                            <div class="p-3 bg-green-50 rounded border border-green-100">
                                                <div class="flex justify-between items-start">
                                                    <div>
                                                        @if($checkout['order_id'] ?? null)
                                                            <span class="font-medium text-gray-900">Замовлення #{{ $checkout['order_id'] }}</span>
                                                        @else
                                                            <span class="font-medium text-gray-900">Checkout #{{ $idx + 1 }}</span>
                                                        @endif
                                                        @if($checkout['status_label'] ?? null)
                                                            <span class="ml-2 text-xs px-2 py-0.5 rounded-full {{ ($checkout['status'] ?? '') === 'delivered' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">
                                                                {{ $checkout['status_label'] }}
                                                            </span>
                                                        @endif
                                                    </div>
                                                    @if($checkout['order_total'] ?? null)
                                                        <span class="font-bold text-green-600">{{ number_format($checkout['order_total'], 0) }} ₴</span>
                                                    @endif
                                                </div>
                                                @if($checkout['customer_name'] ?? null)
                                                    <div class="text-sm text-gray-600 mt-1">👤 {{ $checkout['customer_name'] }}</div>
                                                @endif
                                                <div class="text-xs text-gray-400 mt-1">{{ \Carbon\Carbon::parse($checkout['created_at'])->format('d.m.Y H:i') }}</div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- Chat messages --}}
                            @if(count($selectedConversionSession['messages']) > 0)
                                <div class="mb-4">
                                    <h3 class="text-sm font-semibold text-gray-600 mb-2">💬 Діалог ({{ count($selectedConversionSession['messages']) }} повідомлень)</h3>
                                    <div class="space-y-2 max-h-60 overflow-y-auto border rounded p-2 bg-white">
                                        @foreach($selectedConversionSession['messages'] as $msg)
                                            <div class="text-sm {{ $msg['role'] === 'user' ? 'text-blue-800 bg-blue-50' : 'text-gray-700 bg-gray-50' }} p-2 rounded">
                                                <span class="font-semibold">{{ $msg['role'] === 'user' ? '👤' : '🤖' }}</span>
                                                {{ Str::limit($msg['content'], 200) }}
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- Products shown --}}
                            @if(count($selectedConversionSession['products_shown']) > 0)
                                <div class="mb-4">
                                    <h3 class="text-sm font-semibold text-gray-600 mb-2">👁️ Показані товари ({{ count($selectedConversionSession['products_shown']) }})</h3>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($selectedConversionSession['products_shown'] as $product)
                                            <span class="px-2 py-1 bg-gray-100 rounded text-xs">
                                                {{ Str::limit($product['title'] ?? $product['article'] ?? '?', 30) }}
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- Products clicked --}}
                            @if(count($selectedConversionSession['products_clicked']) > 0)
                                <div class="mb-4">
                                    <h3 class="text-sm font-semibold text-gray-600 mb-2">👆 Клікнув на товари ({{ count($selectedConversionSession['products_clicked']) }})</h3>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($selectedConversionSession['products_clicked'] as $product)
                                            <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded text-xs">
                                                {{ Str::limit($product['title'] ?? $product['article'] ?? '?', 30) }}
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- Added to cart --}}
                            @if(count($selectedConversionSession['added_to_cart']) > 0)
                                <div class="mb-4">
                                    <h3 class="text-sm font-semibold text-gray-600 mb-2">🛒 Додано в кошик ({{ count($selectedConversionSession['added_to_cart']) }})</h3>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($selectedConversionSession['added_to_cart'] as $product)
                                            <span class="px-2 py-1 bg-green-100 text-green-800 rounded text-xs">
                                                {{ Str::limit($product['title'] ?? $product['article'] ?? '?', 30) }}
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

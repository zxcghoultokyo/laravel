<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">🎯 Конверсії</h1>
            <p class="text-sm text-gray-500 mt-1">Повна воронка: від відвідувача до замовлення</p>
        </div>
        
        <div class="flex items-center gap-4">
            <select wire:model.live="days" class="rounded-lg border-gray-300 text-sm">
                <option value="1">Сьогодні</option>
                <option value="7">7 днів</option>
                <option value="30">30 днів</option>
                <option value="90">90 днів</option>
            </select>
            
            <button wire:click="loadData" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 flex items-center gap-2">
                <svg wire:loading wire:target="loadData" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span wire:loading.remove wire:target="loadData">🔄</span>
                Оновити
            </button>
        </div>
    </div>

    <!-- Tabs -->
    <div class="flex gap-1 mb-6 bg-gray-100 p-1 rounded-lg w-fit">
        <button wire:click="setTab('funnel')" 
                class="px-4 py-2 rounded-md text-sm font-medium transition {{ $activeTab === 'funnel' ? 'bg-white shadow text-blue-600' : 'text-gray-600 hover:text-gray-900' }}">
            🔄 Воронка
        </button>
        <button wire:click="setTab('cart')" 
                class="px-4 py-2 rounded-md text-sm font-medium transition {{ $activeTab === 'cart' ? 'bg-white shadow text-blue-600' : 'text-gray-600 hover:text-gray-900' }}">
            🛒 Кошик ({{ count($conversions) }})
        </button>
        <button wire:click="setTab('orders')" 
                class="px-4 py-2 rounded-md text-sm font-medium transition {{ $activeTab === 'orders' ? 'bg-white shadow text-blue-600' : 'text-gray-600 hover:text-gray-900' }}">
            ✅ Замовлення ({{ count($checkouts) }})
        </button>
    </div>

    <!-- Tab Content -->
    @if($activeTab === 'funnel')
        <!-- Funnel Tab -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-lg font-semibold text-gray-900">Воронка конверсії</h3>
                @php
                    $firstStage = $funnel[0]['count'] ?? 0;
                    $lastStage = $funnel[count($funnel) - 1]['count'] ?? 0;
                    $overallRate = $firstStage > 0 ? round(($lastStage / $firstStage) * 100, 2) : 0;
                @endphp
                @if($overallRate > 0)
                    <span class="text-sm bg-green-100 text-green-700 px-3 py-1 rounded-full">
                        {{ $overallRate }}% загальна конверсія
                    </span>
                @endif
            </div>
            
            @if(!empty($funnel))
                <div class="space-y-4">
                    @php $maxCount = max(array_column($funnel, 'count')) ?: 1; @endphp
                    @foreach($funnel as $index => $stage)
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
                                <div class="h-full rounded-lg transition-all duration-500 {{ $index === count($funnel) - 1 ? 'bg-green-500' : 'bg-blue-500' }}"
                                     style="width: {{ ($stage['count'] / $maxCount) * 100 }}%">
                                </div>
                                @if($index > 0 && $stage['dropoff'] > 0)
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-gray-500">
                                        -{{ $stage['dropoff'] }}% відсіялось
                                    </span>
                                @endif
                            </div>
                        </div>
                        @if($index < count($funnel) - 1)
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
        
    @elseif($activeTab === 'cart')
        <!-- Cart (Add to Cart) Tab -->
        <div class="grid grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-3xl font-bold text-gray-700">{{ count($conversions) }}</div>
                <div class="text-sm text-gray-500">Всього add to cart</div>
            </div>
            <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-lg shadow p-4 border border-green-200">
                <div class="text-3xl font-bold text-green-700">{{ count($chatAttributedConversions) }}</div>
                <div class="text-sm text-green-600">Після чату</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                @php
                    $conversionRate = count($conversions) > 0 
                        ? round(count($chatAttributedConversions) / count($conversions) * 100, 1) 
                        : 0;
                @endphp
                <div class="text-3xl font-bold text-blue-600">{{ $conversionRate }}%</div>
                <div class="text-sm text-gray-500">Атрибуція чату</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                @php
                    $uniqueSessions = collect($chatAttributedConversions)->pluck('session_id')->unique()->count();
                @endphp
                <div class="text-3xl font-bold text-purple-600">{{ $uniqueSessions }}</div>
                <div class="text-sm text-gray-500">Сесій з конверсією</div>
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
                        <div class="p-4 border-b border-gray-100 hover:bg-gray-50 cursor-pointer {{ $selectedSessionId === $conv['session_id'] ? 'bg-blue-50' : '' }}"
                             wire:click="viewSession('{{ $conv['session_id'] }}')">
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
                @if($selectedSession)
                    <div class="p-4 border-b border-gray-200 flex justify-between items-center">
                        <h2 class="font-semibold text-gray-700">🔍 Деталі сесії</h2>
                        <button wire:click="closeSession" class="text-gray-400 hover:text-gray-600">✕</button>
                    </div>
                    <div class="p-4 max-h-[600px] overflow-y-auto">
                        <!-- Session Info -->
                        <div class="mb-4 p-3 bg-gray-50 rounded-lg">
                            <div class="text-xs text-gray-500">Session ID</div>
                            <div class="font-mono text-xs text-gray-700 break-all">{{ $selectedSession['session_id'] }}</div>
                            @if($selectedSession['created_at'])
                                <div class="text-xs text-gray-500 mt-2">Почато: {{ \Carbon\Carbon::parse($selectedSession['created_at'])->format('d.m.Y H:i') }}</div>
                            @endif
                            @if($selectedSession['utm'] ?? null)
                                <div class="mt-2 text-xs">
                                    <span class="text-gray-500">Джерело:</span>
                                    <span class="text-blue-600">{{ $selectedSession['utm']['utm_source'] }}</span>
                                    @if($selectedSession['utm']['utm_campaign'])
                                        <span class="text-gray-400">/</span>
                                        <span class="text-purple-600">{{ $selectedSession['utm']['utm_campaign'] }}</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                        
                        <!-- Checkouts/Orders -->
                        @if(count($selectedSession['checkouts'] ?? []) > 0)
                            <div class="mb-4">
                                <h3 class="text-sm font-semibold text-gray-600 mb-2">✅ Замовлення ({{ count($selectedSession['checkouts']) }})</h3>
                                <div class="space-y-1">
                                    @foreach($selectedSession['checkouts'] as $checkout)
                                        <div class="p-3 bg-green-50 border border-green-200 rounded">
                                            <div class="flex justify-between items-center">
                                                <div>
                                                    @if($checkout['order_id'])
                                                        <span class="font-medium text-green-800">Замовлення #{{ $checkout['order_id'] }}</span>
                                                    @else
                                                        <span class="font-medium text-green-800">Оформлення замовлення</span>
                                                    @endif
                                                    <div class="text-xs text-green-600 mt-1">
                                                        {{ \Carbon\Carbon::parse($checkout['created_at'])->format('d.m.Y H:i') }}
                                                        @if($checkout['items_count'])
                                                            • {{ $checkout['items_count'] }} товарів
                                                        @endif
                                                    </div>
                                                </div>
                                                @if($checkout['order_total'])
                                                    <span class="text-lg font-bold text-green-700">{{ number_format($checkout['order_total'], 0) }} ₴</span>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        
                        <!-- Chat Messages -->
                        @if(count($selectedSession['messages']) > 0)
                            <div class="mb-4">
                                <h3 class="text-sm font-semibold text-gray-600 mb-2">💬 Розмова</h3>
                                <div class="space-y-2 max-h-48 overflow-y-auto">
                                    @foreach($selectedSession['messages'] as $msg)
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
                        @if(count($selectedSession['products_shown']) > 0)
                            <div class="mb-4">
                                <h3 class="text-sm font-semibold text-gray-600 mb-2">👁️ Показані товари ({{ count($selectedSession['products_shown']) }})</h3>
                                <div class="space-y-1">
                                    @foreach($selectedSession['products_shown'] as $product)
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
                        @if(count($selectedSession['products_clicked']) > 0)
                            <div class="mb-4">
                                <h3 class="text-sm font-semibold text-gray-600 mb-2">👆 Клікнув на товари ({{ count($selectedSession['products_clicked']) }})</h3>
                                <div class="space-y-1">
                                    @foreach($selectedSession['products_clicked'] as $product)
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
                        @if(count($selectedSession['added_to_cart']) > 0)
                            <div class="mb-4">
                                <h3 class="text-sm font-semibold text-gray-600 mb-2">🛒 Додав в кошик ({{ count($selectedSession['added_to_cart']) }})</h3>
                                <div class="space-y-1">
                                    @foreach($selectedSession['added_to_cart'] as $product)
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
                        
                        <!-- Link to full chat -->
                        <div class="mt-4 pt-4 border-t">
                            <a href="{{ route('admin.chats.show', $selectedSession['session_id']) }}" 
                               class="text-blue-600 hover:underline text-sm">
                                Відкрити повний чат →
                            </a>
                        </div>
                    </div>
                @else
                    <div class="p-8 text-center text-gray-400">
                        <div class="text-4xl mb-2">👈</div>
                        <p>Виберіть конверсію щоб побачити деталі</p>
                    </div>
                @endif
            </div>
        </div>
        
    @elseif($activeTab === 'orders')
        <!-- Orders (Checkout) Tab -->
        <div class="grid grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-3xl font-bold text-gray-700">{{ count($checkouts) }}</div>
                <div class="text-sm text-gray-500">Всього замовлень</div>
            </div>
            <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-lg shadow p-4 border border-green-200">
                @php
                    $chatOrders = collect($checkouts)->filter(fn($c) => $c['had_chat'])->count();
                @endphp
                <div class="text-3xl font-bold text-green-700">{{ $chatOrders }}</div>
                <div class="text-sm text-green-600">Після чату</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                @php
                    $totalRevenue = collect($checkouts)->sum('order_total');
                @endphp
                <div class="text-3xl font-bold text-blue-600">{{ number_format($totalRevenue, 0, '.', ' ') }} ₴</div>
                <div class="text-sm text-gray-500">Загальна виручка</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                @php
                    $chatRevenue = collect($checkouts)->filter(fn($c) => $c['had_chat'])->sum('order_total');
                @endphp
                <div class="text-3xl font-bold text-purple-600">{{ number_format($chatRevenue, 0, '.', ' ') }} ₴</div>
                <div class="text-sm text-gray-500">Виручка з чату</div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow">
            <div class="p-4 border-b border-gray-200">
                <h2 class="font-semibold text-gray-700">✅ Замовлення</h2>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse($checkouts as $checkout)
                    <div class="p-4 hover:bg-gray-50" x-data="{ expanded: false }">
                        <div class="flex justify-between items-start">
                            <div>
                                <div class="flex items-center gap-2">
                                    @if($checkout['order_id'])
                                        <span class="font-medium text-gray-900">Замовлення #{{ $checkout['order_id'] }}</span>
                                    @else
                                        <span class="font-medium text-gray-900">Checkout</span>
                                    @endif
                                    <span class="text-xs px-2 py-0.5 rounded-full {{ $checkout['event_type'] === 'checkout_success' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">
                                        {{ $checkout['status_label'] ?? ($checkout['event_type'] === 'checkout_success' ? 'Успіх' : 'Відправлено') }}
                                    </span>
                                    @if($checkout['source'] ?? '' === 'horoshop')
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-purple-100 text-purple-700">
                                            Horoshop
                                        </span>
                                    @endif
                                </div>
                                <div class="text-sm text-gray-500 mt-1">
                                    {{ \Carbon\Carbon::parse($checkout['created_at'])->format('d.m.Y H:i') }}
                                    @if($checkout['items_count'])
                                        • {{ $checkout['items_count'] }} товарів
                                    @endif
                                    @if($checkout['customer_name'] ?? null)
                                        • {{ $checkout['customer_name'] }}
                                    @endif
                                </div>
                                @if($checkout['utm_source'] ?? null)
                                    <div class="text-xs text-gray-400 mt-1">
                                        UTM: {{ $checkout['utm_source'] }}{{ $checkout['utm_campaign'] ? ' / ' . $checkout['utm_campaign'] : '' }}
                                    </div>
                                @endif
                            </div>
                            <div class="text-right">
                                @if($checkout['order_total'])
                                    <div class="text-lg font-bold text-gray-900">{{ number_format($checkout['order_total'], 0) }} ₴</div>
                                @endif
                                <div class="flex gap-1 mt-1">
                                    @if($checkout['had_chat'])
                                        <span class="text-xs px-2 py-0.5 bg-blue-100 text-blue-700 rounded">
                                            💬 Чат
                                        </span>
                                    @endif
                                    @if($checkout['products_from_chat'] > 0)
                                        <span class="text-xs px-2 py-0.5 bg-green-100 text-green-700 rounded">
                                            📦 {{ $checkout['products_from_chat'] }} з чату
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        
                        {{-- Products list (lazy loading) --}}
                        @if($checkout['has_products'] ?? false)
                            <div class="mt-3">
                                @if($selectedCheckoutId === $checkout['id'])
                                    {{-- Products loaded --}}
                                    <button wire:click="closeCheckoutProducts" class="text-sm text-blue-600 hover:text-blue-800 flex items-center gap-1">
                                        <svg class="w-4 h-4 rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                        </svg>
                                        Товари у замовленні ({{ $checkout['items_count'] }})
                                    </button>
                                    <div class="mt-2 space-y-2">
                                        @foreach($selectedCheckoutProducts as $product)
                                            <div class="flex justify-between items-center p-2 bg-gray-50 rounded text-sm">
                                                <div class="flex-1">
                                                    <span class="text-gray-800">{{ $product['title'] }}</span>
                                                    @if($product['article'])
                                                        <span class="text-gray-400 text-xs ml-2">[{{ $product['article'] }}]</span>
                                                    @endif
                                                    @if($product['quantity'] > 1)
                                                        <span class="text-gray-500 ml-2">× {{ $product['quantity'] }}</span>
                                                    @endif
                                                </div>
                                                @if($product['price'])
                                                    <span class="text-gray-700 font-medium ml-4">{{ number_format($product['price'], 0) }} ₴</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    {{-- Click to load products --}}
                                    <button wire:click="loadCheckoutProducts({{ $checkout['id'] }})" class="text-sm text-blue-600 hover:text-blue-800 flex items-center gap-1">
                                        <svg class="w-4 h-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                        </svg>
                                        Товари у замовленні ({{ $checkout['items_count'] }})
                                    </button>
                                @endif
                            </div>
                        @endif
                        
                        @if($checkout['session_id'])
                            <div class="mt-2">
                                <a href="{{ route('admin.chats.show', $checkout['session_id']) }}" 
                                   class="text-xs text-blue-600 hover:underline">
                                    Переглянути чат →
                                </a>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="p-12 text-center text-gray-400">
                        <div class="text-5xl mb-3">📭</div>
                        <p class="text-lg">Ще немає замовлень</p>
                        <p class="text-sm mt-2">Замовлення з'являться коли хтось оформить покупку на сайті</p>
                        <p class="text-xs mt-4 text-gray-300">Трекінг: checkout_success event через Horoshop marketingEvents</p>
                    </div>
                @endforelse
            </div>
        </div>
    @endif
</div>

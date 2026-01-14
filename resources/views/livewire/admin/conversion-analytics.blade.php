<div class="p-6">
    <!-- Navigation -->
    <div class="mb-4 flex gap-2 flex-wrap">
        <a href="{{ route('admin.dashboard') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200">Dashboard</a>
        <a href="{{ route('admin.analytics') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200">📊 Аналітика</a>
        <a href="{{ route('admin.conversions') }}" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm">🛒 Конверсії</a>
        <a href="{{ route('admin.chats.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200">💬 Чати</a>
        <a href="{{ route('admin.widget.settings') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200">⚙️ Віджет</a>
        <a href="{{ route('admin.greetings') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200">🎯 Привітання</a>
    </div>

    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">🛒 Конверсії з чату</h1>
            <p class="text-sm text-gray-500 mt-1">Товари додані в кошик після спілкування</p>
        </div>
        
        <div class="flex items-center gap-4">
            <select wire:model.live="days" class="rounded-lg border-gray-300 text-sm">
                <option value="1">Сьогодні</option>
                <option value="7">7 днів</option>
                <option value="30">30 днів</option>
                <option value="90">90 днів</option>
            </select>
            
            <button wire:click="loadData" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">
                🔄 Оновити
            </button>
        </div>
    </div>

    <!-- Summary Cards -->
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
                    </div>
                    
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
                            ⚠️ Повідомлення не знайдено (можливо сесія з віджету без запису в DB)
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
                                            <span class="text-gray-700">{{ Str::limit($product['title'], 40) }}</span>
                                            @if($product['product_article'] ?? false)
                                                <span class="text-gray-400 text-xs">({{ $product['product_article'] }})</span>
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
                                        <div>
                                            <span class="text-blue-700">{{ Str::limit($product['title'], 40) }}</span>
                                        </div>
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
                                            <span class="text-green-700 font-medium">{{ Str::limit($product['title'], 40) }}</span>
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
</div>

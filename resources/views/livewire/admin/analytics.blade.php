<div class="p-6">
    <!-- Navigation -->
    <div class="mb-4 flex gap-2">
        <a href="{{ route('admin.dashboard') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200">Dashboard</a>
        <a href="{{ route('admin.analytics') }}" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm">📊 Аналітика</a>
        <a href="{{ route('admin.chats.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200">💬 Чати</a>
        <a href="{{ route('admin.widget.settings') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200">⚙️ Віджет</a>
    </div>

    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">📊 Аналітика чатів</h1>
            @if($lastUpdated)
                <p class="text-sm text-gray-500 mt-1">Оновлено: {{ $lastUpdated }}</p>
            @endif
        </div>
        
        <div class="flex items-center gap-4">
            <select wire:model.live="days" class="rounded-lg border-gray-300 text-sm">
                <option value="1">Сьогодні</option>
                <option value="7">7 днів</option>
                <option value="30">30 днів</option>
                <option value="90">90 днів</option>
            </select>
            
            <button 
                wire:click="loadStats" 
                wire:loading.attr="disabled"
                wire:loading.class="opacity-50 cursor-not-allowed"
                class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 flex items-center gap-2"
            >
                <svg wire:loading wire:target="loadStats" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span wire:loading.remove wire:target="loadStats">🔄</span>
                Оновити
            </button>
        </div>
    </div>

    @if(!$tablesExist)
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 text-center">
            <div class="text-4xl mb-4">⚠️</div>
            <h2 class="text-lg font-semibold text-yellow-800 mb-2">Таблиці аналітики не створені</h2>
            <p class="text-yellow-700 mb-4">Потрібно запустити міграцію:</p>
            <code class="bg-yellow-100 px-3 py-1 rounded text-sm">php artisan migrate</code>
        </div>
    @else
        <!-- Visitors Funnel -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <h3 class="text-sm font-semibold text-gray-500 mb-3">📈 Воронка відвідувачів</h3>
            <div class="grid grid-cols-3 gap-4">
                <div class="text-center p-3 bg-gray-50 rounded-lg">
                    <div class="text-3xl font-bold text-gray-700">{{ number_format($stats['page_visitors'] ?? 0) }}</div>
                    <div class="text-sm text-gray-500">Відвідувачів</div>
                    <div class="text-xs text-gray-400 mt-1">{{ number_format($stats['page_views'] ?? 0) }} переглядів</div>
                </div>
                <div class="text-center p-3 bg-blue-50 rounded-lg border-2 border-blue-200">
                    <div class="text-3xl font-bold text-blue-600">{{ number_format($stats['chat_opened_users'] ?? 0) }}</div>
                    <div class="text-sm text-gray-500">Відкрили чат</div>
                    <div class="text-xs text-blue-600 mt-1 font-medium">{{ $stats['widget_open_rate'] ?? 0 }}% конверсія</div>
                </div>
                <div class="text-center p-3 bg-green-50 rounded-lg">
                    <div class="text-3xl font-bold text-green-600">{{ number_format($stats['sessions'] ?? 0) }}</div>
                    <div class="text-sm text-gray-500">Активних сесій</div>
                    <div class="text-xs text-gray-400 mt-1">{{ $stats['avg_messages'] ?? 0 }} msg/сесія</div>
                </div>
            </div>
        </div>
    
        <!-- Stats Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['sessions'] ?? 0) }}</div>
                <div class="text-sm text-gray-500">Сесій</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-2xl font-bold text-purple-600">{{ number_format($stats['unique_users'] ?? 0) }}</div>
                <div class="text-sm text-gray-500">Унікальних</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-2xl font-bold text-gray-700">{{ number_format($stats['messages'] ?? 0) }}</div>
                <div class="text-sm text-gray-500">Повідомлень</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-2xl font-bold text-cyan-600">{{ $stats['avg_messages'] ?? 0 }}</div>
                <div class="text-sm text-gray-500">Avg msg/сесія</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-2xl font-bold text-orange-600">{{ number_format($stats['products_shown'] ?? 0) }}</div>
                <div class="text-sm text-gray-500">Показано товарів</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-2xl font-bold text-green-600">{{ number_format($stats['products_clicked'] ?? 0) }}</div>
                <div class="text-sm text-gray-500">Кліків</div>
            </div>
        </div>

        <!-- Conversion Stats -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-lg shadow p-4 border border-green-200">
                <div class="flex items-center gap-2 mb-1">
                    <span class="text-2xl">🛒</span>
                    <span class="text-sm text-green-700">Add to Cart</span>
                </div>
                <div class="text-3xl font-bold text-green-700">{{ $stats['add_to_cart'] ?? 0 }}</div>
            </div>
            <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg shadow p-4 border border-blue-200">
                <div class="flex items-center gap-2 mb-1">
                    <span class="text-2xl">💰</span>
                    <span class="text-sm text-blue-700">Покупок</span>
                </div>
                <div class="text-3xl font-bold text-blue-700">{{ $stats['purchases'] ?? 0 }}</div>
            </div>
            <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-lg shadow p-4 border border-purple-200">
                <div class="flex items-center gap-2 mb-1">
                    <span class="text-2xl">💵</span>
                    <span class="text-sm text-purple-700">Виручка</span>
                </div>
                <div class="text-3xl font-bold text-purple-700">{{ number_format($stats['revenue'] ?? 0, 0, '.', ' ') }} ₴</div>
            </div>
            <div class="bg-gradient-to-br from-amber-50 to-amber-100 rounded-lg shadow p-4 border border-amber-200">
                <div class="flex items-center gap-2 mb-1">
                    <span class="text-2xl">📊</span>
                    <span class="text-sm text-amber-700">CTR товарів</span>
                </div>
                <div class="text-3xl font-bold text-amber-700">{{ $stats['ctr'] ?? 0 }}%</div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Daily Chart -->
            <div class="bg-white rounded-lg shadow p-4">
                <h2 class="font-semibold text-gray-700 mb-4">📈 Активність по днях</h2>
                @if(count($dailyChart) > 0)
                    <div class="space-y-2">
                        @php $maxSessions = max(array_column($dailyChart, 'sessions')) ?: 1; @endphp
                        @foreach($dailyChart as $day)
                            <div class="flex items-center gap-2">
                                <div class="w-20 text-xs text-gray-500">{{ \Carbon\Carbon::parse($day['date'])->format('d.m') }}</div>
                                <div class="flex-1 bg-gray-100 rounded-full h-4 relative">
                                    <div class="bg-blue-500 h-4 rounded-full" style="width: {{ ($day['sessions'] / $maxSessions) * 100 }}%"></div>
                                </div>
                                <div class="w-12 text-xs text-right text-gray-600">{{ $day['sessions'] }}</div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-gray-400 text-center py-8">Немає даних</div>
                @endif
            </div>

            <!-- Top Clicked Products -->
            <div class="bg-white rounded-lg shadow p-4">
                <h2 class="font-semibold text-gray-700 mb-4">🔥 Топ кліки по товарах</h2>
                @if(count($topProducts) > 0)
                    <div class="space-y-2">
                        @foreach($topProducts as $product)
                            <div class="flex items-center gap-2 p-2 bg-gray-50 rounded">
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-gray-800 truncate">{{ $product['title'] }}</div>
                                    <div class="text-xs text-gray-500">{{ $product['article'] }} • {{ number_format($product['price'], 0) }} ₴</div>
                                </div>
                                <div class="bg-green-100 text-green-700 px-2 py-1 rounded text-sm font-medium">
                                    {{ $product['clicks'] }} кліків
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-gray-400 text-center py-8">Немає даних про кліки</div>
                @endif
            </div>
        </div>

        <!-- Outcomes -->
        @if(count($outcomes) > 0)
            <div class="bg-white rounded-lg shadow p-4 mt-6">
                <h2 class="font-semibold text-gray-700 mb-4">📋 Результати сесій</h2>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    @foreach($outcomes as $outcome)
                        @php
                            $color = match($outcome->outcome_category) {
                                'success' => 'green',
                                'failure' => 'red',
                                default => 'gray'
                            };
                            $icon = match($outcome->outcome) {
                                'order_placed' => '✅',
                                'add_to_cart' => '🛒',
                                'lead_captured' => '📧',
                                'handoff_to_manager' => '👤',
                                'no_answer' => '❌',
                                'no_relevant_products' => '🔍',
                                'user_abandoned' => '👋',
                                'out_of_scope' => '❓',
                                default => '📊'
                            };
                        @endphp
                        <div class="bg-{{ $color }}-50 border border-{{ $color }}-200 rounded-lg p-3">
                            <div class="flex items-center gap-2">
                                <span>{{ $icon }}</span>
                                <span class="text-sm text-{{ $color }}-700">{{ $outcome->outcome }}</span>
                            </div>
                            <div class="text-xl font-bold text-{{ $color }}-800 mt-1">{{ $outcome->count }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Recent Events -->
        <div class="bg-white rounded-lg shadow p-4 mt-6">
            <h2 class="font-semibold text-gray-700 mb-4">🕐 Останні події</h2>
            @php
                $recentEvents = \Illuminate\Support\Facades\DB::table('chat_events')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['event_type', 'session_id', 'product_id', 'created_at']);
            @endphp
            @if($recentEvents->count() > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-gray-500 text-left">
                            <tr>
                                <th class="pb-2">Час</th>
                                <th class="pb-2">Подія</th>
                                <th class="pb-2">Сесія</th>
                                <th class="pb-2">Товар</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-700">
                            @foreach($recentEvents as $event)
                                <tr class="border-t border-gray-100">
                                    <td class="py-2 text-xs text-gray-500">{{ \Carbon\Carbon::parse($event->created_at)->format('H:i:s') }}</td>
                                    <td class="py-2">
                                        @php
                                            $eventIcon = match($event->event_type) {
                                                'product_shown' => '👁️',
                                                'product_click' => '👆',
                                                'message' => '💬',
                                                'session_start' => '🚀',
                                                'add_to_cart' => '🛒',
                                                default => '📌'
                                            };
                                        @endphp
                                        <span class="inline-flex items-center gap-1">
                                            {{ $eventIcon }} {{ $event->event_type }}
                                        </span>
                                    </td>
                                    <td class="py-2 font-mono text-xs">{{ substr($event->session_id, 0, 16) }}...</td>
                                    <td class="py-2">{{ $event->product_id ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-gray-400 text-center py-8">Немає подій</div>
            @endif
        </div>
    @endif
</div>

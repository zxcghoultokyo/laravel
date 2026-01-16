<div wire:poll.60s="loadData" x-data="dashboardCharts()" x-init="initCharts()">
    <!-- Header with Period Selector -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-900">Dashboard</h2>
            <p class="mt-1 text-sm text-gray-500">Бізнес-метрики та аналітика</p>
        </div>
        <div class="flex items-center gap-3">
            <!-- Period Selector -->
            <div class="flex bg-gray-100 rounded-lg p-1">
                <button wire:click="setPeriod('today')" class="px-3 py-1.5 text-sm rounded-md transition {{ $period === 'today' ? 'bg-white shadow text-blue-600 font-medium' : 'text-gray-600 hover:text-gray-900' }}">
                    Сьогодні
                </button>
                <button wire:click="setPeriod('7d')" class="px-3 py-1.5 text-sm rounded-md transition {{ $period === '7d' ? 'bg-white shadow text-blue-600 font-medium' : 'text-gray-600 hover:text-gray-900' }}">
                    7 днів
                </button>
                <button wire:click="setPeriod('30d')" class="px-3 py-1.5 text-sm rounded-md transition {{ $period === '30d' ? 'bg-white shadow text-blue-600 font-medium' : 'text-gray-600 hover:text-gray-900' }}">
                    30 днів
                </button>
                <button wire:click="setPeriod('90d')" class="px-3 py-1.5 text-sm rounded-md transition {{ $period === '90d' ? 'bg-white shadow text-blue-600 font-medium' : 'text-gray-600 hover:text-gray-900' }}">
                    90 днів
                </button>
            </div>
            <button 
                wire:click="refreshData" 
                wire:loading.attr="disabled"
                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 flex items-center gap-2"
            >
                <svg wire:loading wire:target="refreshData" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span wire:loading.remove wire:target="refreshData">🔄</span>
                Оновити
            </button>
        </div>
    </div>

    <!-- Live Stats Bar -->
    <div class="bg-gradient-to-r from-blue-600 to-indigo-600 rounded-xl p-4 mb-6 text-white">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-8">
                <div class="flex items-center gap-2">
                    <span class="flex h-2 w-2 relative">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-green-400"></span>
                    </span>
                    <span class="text-sm font-medium">{{ $liveStats['active_now'] ?? 0 }} активних зараз</span>
                </div>
                <div class="text-sm">
                    <span class="opacity-70">Оператор веде:</span>
                    <span class="font-medium">{{ $liveStats['operator_sessions'] ?? 0 }}</span>
                </div>
                <div class="text-sm">
                    <span class="opacity-70">Запитів сьогодні:</span>
                    <span class="font-medium">{{ number_format($liveStats['today_requests'] ?? 0) }}</span>
                </div>
            </div>
            <div class="flex items-center gap-4">
                @if(($health['overall'] ?? 'healthy') === 'healthy')
                    <span class="flex items-center gap-1 text-sm bg-white/20 px-3 py-1 rounded-full">
                        <span class="w-2 h-2 bg-green-400 rounded-full"></span>
                        Все працює
                    </span>
                @else
                    <span class="flex items-center gap-1 text-sm bg-yellow-500/30 px-3 py-1 rounded-full">
                        <span class="w-2 h-2 bg-yellow-400 rounded-full"></span>
                        Є проблеми
                    </span>
                @endif
            </div>
        </div>
    </div>

    <!-- Horizontal Conversion Funnel (replaces KPI cards) -->
    <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900">🔄 Воронка конверсії</h3>
            @if(($funnelData['overall_rate'] ?? 0) > 0)
                <span class="text-sm bg-green-100 text-green-700 px-3 py-1 rounded-full">
                    {{ $funnelData['overall_rate'] }}% загальна конверсія
                </span>
            @endif
        </div>
        
        @if(!empty($funnelData['stages']))
            <!-- Horizontal funnel on desktop, vertical on mobile -->
            <div class="hidden lg:flex items-stretch gap-2">
                @foreach($funnelData['stages'] as $index => $stage)
                    <div class="flex-1 relative group">
                        <!-- Stage card -->
                        <div class="bg-gradient-to-b from-gray-50 to-white border border-gray-200 rounded-xl p-4 h-full hover:shadow-md transition">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-2xl">{{ $stage['icon'] }}</span>
                                @if($index > 0 && $stage['rate'] > 0)
                                    <span class="text-xs px-2 py-0.5 rounded-full {{ $stage['rate'] >= 50 ? 'bg-green-100 text-green-700' : ($stage['rate'] >= 20 ? 'bg-yellow-100 text-yellow-700' : 'bg-orange-100 text-orange-700') }}">
                                        {{ $stage['rate'] }}%
                                    </span>
                                @endif
                            </div>
                            <p class="text-2xl font-bold text-gray-900">{{ number_format($stage['count']) }}</p>
                            <p class="text-sm text-gray-500 mt-1">{{ $stage['label'] }}</p>
                            @if($index > 0 && $stage['dropoff'] > 0)
                                <p class="text-xs text-gray-400 mt-2">-{{ $stage['dropoff'] }}% відсіялось</p>
                            @endif
                        </div>
                        <!-- Arrow between stages -->
                        @if($index < count($funnelData['stages']) - 1)
                            <div class="absolute right-0 top-1/2 -translate-y-1/2 translate-x-1/2 z-10 w-6 h-6 flex items-center justify-center">
                                <svg class="w-5 h-5 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            
            <!-- Vertical funnel on mobile -->
            <div class="lg:hidden space-y-3">
                @php $maxCount = max(array_column($funnelData['stages'], 'count')) ?: 1; @endphp
                @foreach($funnelData['stages'] as $index => $stage)
                    <div class="group">
                        <div class="flex items-center justify-between mb-1">
                            <div class="flex items-center gap-2">
                                <span class="text-lg">{{ $stage['icon'] }}</span>
                                <span class="text-sm font-medium text-gray-700">{{ $stage['label'] }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-bold text-gray-900">{{ number_format($stage['count']) }}</span>
                                @if($index > 0 && $stage['rate'] > 0)
                                    <span class="text-xs px-2 py-0.5 rounded-full {{ $stage['rate'] >= 50 ? 'bg-green-100 text-green-700' : ($stage['rate'] >= 20 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') }}">
                                        {{ $stage['rate'] }}%
                                    </span>
                                @endif
                            </div>
                        </div>
                        <div class="h-6 bg-gray-100 rounded-lg overflow-hidden relative">
                            <div class="h-full rounded-lg transition-all duration-500 {{ $index === count($funnelData['stages']) - 1 ? 'bg-green-500' : 'bg-blue-500' }}"
                                 style="width: {{ ($stage['count'] / $maxCount) * 100 }}%">
                            </div>
                            @if($index > 0 && $stage['dropoff'] > 0)
                                <span class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-gray-500">
                                    -{{ $stage['dropoff'] }}% відсіялось
                                </span>
                            @endif
                        </div>
                    </div>
                    @if($index < count($funnelData['stages']) - 1)
                        <div class="flex justify-center">
                            <svg class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    @endif
                @endforeach
            </div>
        @else
            <div class="flex flex-col items-center justify-center h-32 text-gray-400">
                <span class="text-4xl mb-2">📊</span>
                <p class="text-sm">Ще немає даних</p>
            </div>
        @endif
    </div>

    <!-- Charts Row - Full Width -->
    <div class="mb-6">
        <!-- Conversations Chart -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">📈 Діалоги</h3>
            <div class="h-64 min-h-[16rem]" style="position: relative;">
                <canvas id="conversationsChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Bottom Row: Top Products & Recent Chats -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Top Products -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900">🔥 Топ товарів у чаті</h3>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse($topProducts as $product)
                <div class="px-6 py-3 hover:bg-gray-50 transition">
                    <div class="flex items-center justify-between">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ $product['title'] }}</p>
                            <p class="text-xs text-gray-500">арт. {{ $product['article'] }}</p>
                        </div>
                        <div class="flex items-center gap-4 text-sm">
                            <div class="text-center">
                                <p class="font-medium text-gray-900">{{ $product['shows'] }}</p>
                                <p class="text-xs text-gray-500">показів</p>
                            </div>
                            <div class="text-center">
                                <p class="font-medium text-blue-600">{{ $product['ctr'] }}%</p>
                                <p class="text-xs text-gray-500">CTR</p>
                            </div>
                            @if($product['price'])
                            <div class="text-right">
                                <p class="font-medium text-green-600">₴{{ number_format($product['price'], 0) }}</p>
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
                @empty
                <div class="px-6 py-8 text-center text-gray-500">
                    <p class="text-4xl mb-2">📦</p>
                    <p>Ще немає даних про товари</p>
                </div>
                @endforelse
            </div>
        </div>

        <!-- Recent Chats -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">💬 Останні чати</h3>
                <a href="{{ route('admin.chats.index') }}" class="text-sm text-blue-600 hover:text-blue-700">
                    Всі чати →
                </a>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse($recentChats as $chat)
                <a href="{{ route('admin.chats.show', $chat['session_id']) }}" class="block px-6 py-3 hover:bg-gray-50 transition">
                    <div class="flex items-center justify-between">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-gray-900 truncate">{{ $chat['preview'] }}</p>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="text-xs text-gray-500">{{ $chat['messages_count'] }} повідомлень</span>
                                @if($chat['outcome'])
                                    <span class="text-xs px-2 py-0.5 rounded-full 
                                        {{ $chat['outcome_category'] === 'success' ? 'bg-green-100 text-green-700' : '' }}
                                        {{ $chat['outcome_category'] === 'failure' ? 'bg-red-100 text-red-700' : '' }}
                                        {{ !in_array($chat['outcome_category'], ['success', 'failure']) ? 'bg-gray-100 text-gray-600' : '' }}
                                    ">
                                        {{ $chat['outcome'] }}
                                    </span>
                                @endif
                            </div>
                        </div>
                        <div class="text-right">
                            <span class="px-2 py-1 text-xs rounded-full {{ $chat['status'] === 'operator' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700' }}">
                                {{ $chat['status'] === 'operator' ? 'Оператор' : 'AI' }}
                            </span>
                            <p class="text-xs text-gray-400 mt-1">{{ $chat['time_ago'] }}</p>
                        </div>
                    </div>
                </a>
                @empty
                <div class="px-6 py-8 text-center text-gray-500">
                    <p class="text-4xl mb-2">💭</p>
                    <p>Ще немає чатів</p>
                </div>
                @endforelse
            </div>
        </div>
    </div>

    <!-- Advanced Analytics (collapsible) -->
    <div class="mt-6 bg-white rounded-xl shadow-sm overflow-hidden">
        <button wire:click="toggleAdvanced" class="w-full px-6 py-4 flex items-center justify-between text-left hover:bg-gray-50 transition">
            <span class="text-sm font-medium text-gray-600">
                📊 Розширена аналітика
                @if(!empty($aiQuality) && isset($aiQuality['score']))
                    <span class="ml-2 text-xs px-2 py-0.5 rounded-full 
                        {{ $aiQuality['score'] >= 80 ? 'bg-green-100 text-green-700' : ($aiQuality['score'] >= 60 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') }}">
                        AI: {{ $aiQuality['grade'] ?? 'N/A' }}
                    </span>
                @endif
                @if(!empty($abTestStats) && ($abTestStats['enabled'] ?? false))
                    <span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-purple-100 text-purple-700">
                        A/B тест активний
                    </span>
                @endif
            </span>
            <svg class="w-5 h-5 text-gray-400 transform transition {{ $showAdvanced ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </button>
        
        @if($showAdvanced)
        <div class="px-6 pb-6 border-t border-gray-100 pt-4">
            <div class="grid grid-cols-2 gap-6">
                <!-- AI Quality Score -->
                <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-xl p-5 border border-blue-100">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="font-semibold text-gray-900">🤖 AI Index Quality</h4>
                        @if(isset($aiQuality['score']))
                            <div class="text-3xl font-bold {{ $aiQuality['score'] >= 80 ? 'text-green-600' : ($aiQuality['score'] >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                                {{ $aiQuality['score'] }}%
                            </div>
                        @endif
                    </div>
                    
                    @if(isset($aiQuality['error']))
                        <p class="text-sm text-red-600">{{ $aiQuality['error'] }}</p>
                    @else
                        <div class="space-y-3">
                            <div>
                                <div class="flex justify-between text-sm mb-1">
                                    <span class="text-gray-600">Покриття продуктів</span>
                                    <span class="font-medium">{{ $aiQuality['coverage'] ?? 0 }}%</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="bg-blue-600 h-2 rounded-full" style="width: {{ $aiQuality['coverage'] ?? 0 }}%"></div>
                                </div>
                            </div>
                            <div>
                                <div class="flex justify-between text-sm mb-1">
                                    <span class="text-gray-600">Сленг/ключові слова</span>
                                    <span class="font-medium">{{ $aiQuality['slang_coverage'] ?? 0 }}%</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="bg-purple-600 h-2 rounded-full" style="width: {{ $aiQuality['slang_coverage'] ?? 0 }}%"></div>
                                </div>
                            </div>
                            <div class="pt-2 flex justify-between text-xs text-gray-500">
                                <span>{{ number_format($aiQuality['total_indexed'] ?? 0) }}/{{ number_format($aiQuality['total_products'] ?? 0) }} проіндексовано</span>
                                @if(($aiQuality['high_priority_issues'] ?? 0) > 0)
                                    <span class="text-orange-600">⚠ {{ $aiQuality['high_priority_issues'] }} важливих рекомендацій</span>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
                
                <!-- A/B Testing -->
                <div class="bg-gradient-to-br from-purple-50 to-pink-50 rounded-xl p-5 border border-purple-100">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="font-semibold text-gray-900">🧪 A/B Тестування</h4>
                        @if($abTestStats['enabled'] ?? false)
                            <span class="text-xs px-2 py-1 bg-green-100 text-green-700 rounded-full">Активно</span>
                        @else
                            <span class="text-xs px-2 py-1 bg-gray-100 text-gray-500 rounded-full">Вимкнено</span>
                        @endif
                    </div>
                    
                    @if(isset($abTestStats['error']))
                        <p class="text-sm text-red-600">{{ $abTestStats['error'] }}</p>
                    @elseif($abTestStats['has_data'] ?? false)
                        <div class="space-y-3">
                            <p class="text-sm text-gray-600 mb-3">{{ $abTestStats['name'] ?? 'Експеримент' }}</p>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="bg-white/60 rounded-lg p-3">
                                    <p class="text-xs text-gray-500 mb-1">Control</p>
                                    <p class="text-lg font-bold text-gray-700">{{ $abTestStats['control']['total_searches'] ?? 0 }}</p>
                                    <p class="text-xs text-gray-500">пошуків</p>
                                </div>
                                <div class="bg-white/60 rounded-lg p-3">
                                    <p class="text-xs text-gray-500 mb-1">Treatment</p>
                                    <p class="text-lg font-bold text-purple-700">{{ $abTestStats['treatment']['total_searches'] ?? 0 }}</p>
                                    <p class="text-xs text-gray-500">пошуків</p>
                                </div>
                            </div>
                            @if(!empty($abTestStats['comparison']))
                                <div class="pt-2 text-xs text-gray-500">
                                    CTR: {{ $abTestStats['comparison']['ctr_improvement'] ?? 0 }}% {{ ($abTestStats['comparison']['ctr_improvement'] ?? 0) > 0 ? '↑' : '↓' }}
                                </div>
                            @endif
                        </div>
                    @else
                        <div class="text-center py-4 text-gray-400">
                            <p class="text-sm">Немає даних</p>
                            <p class="text-xs mt-1">Експеримент ще не накопичив достатньо даних</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        @endif
    </div>

    <!-- Health Status (collapsed by default) -->
    <details class="mt-6 bg-white rounded-xl shadow-sm">
        <summary class="px-6 py-4 cursor-pointer text-sm font-medium text-gray-600 hover:text-gray-900">
            ⚙️ Технічний статус сервісів
        </summary>
        <div class="px-6 pb-4 grid grid-cols-3 gap-4">
            <div class="p-3 rounded-lg {{ ($health['database']['status'] ?? 'error') === 'ok' ? 'bg-green-50' : 'bg-red-50' }}">
                <p class="text-sm font-medium {{ ($health['database']['status'] ?? 'error') === 'ok' ? 'text-green-700' : 'text-red-700' }}">
                    Database: {{ ($health['database']['status'] ?? 'error') === 'ok' ? '✓ OK' : '✗ Error' }}
                </p>
                @if(isset($health['database']['latency_ms']))
                    <p class="text-xs text-gray-500">{{ $health['database']['latency_ms'] }}ms</p>
                @endif
            </div>
            <div class="p-3 rounded-lg {{ ($health['meilisearch']['status'] ?? 'error') === 'ok' ? 'bg-green-50' : (($health['meilisearch']['status'] ?? '') === 'disabled' ? 'bg-gray-50' : 'bg-red-50') }}">
                <p class="text-sm font-medium {{ ($health['meilisearch']['status'] ?? 'error') === 'ok' ? 'text-green-700' : (($health['meilisearch']['status'] ?? '') === 'disabled' ? 'text-gray-500' : 'text-red-700') }}">
                    Meilisearch: {{ ($health['meilisearch']['status'] ?? 'error') === 'ok' ? '✓ OK' : (($health['meilisearch']['status'] ?? '') === 'disabled' ? '— Disabled' : '✗ Error') }}
                </p>
                @if(isset($health['meilisearch']['documents']))
                    <p class="text-xs text-gray-500">{{ number_format($health['meilisearch']['documents']) }} документів</p>
                @endif
            </div>
            <div class="p-3 rounded-lg {{ ($health['openai']['status'] ?? 'error') === 'ok' ? 'bg-green-50' : (($health['openai']['status'] ?? '') === 'circuit_open' ? 'bg-yellow-50' : 'bg-red-50') }}">
                <p class="text-sm font-medium {{ ($health['openai']['status'] ?? 'error') === 'ok' ? 'text-green-700' : (($health['openai']['status'] ?? '') === 'circuit_open' ? 'text-yellow-700' : 'text-red-700') }}">
                    OpenAI: {{ ($health['openai']['status'] ?? 'error') === 'ok' ? '✓ OK' : (($health['openai']['status'] ?? '') === 'circuit_open' ? '⚠ Circuit Open' : '✗ Error') }}
                </p>
            </div>
        </div>
    </details>

    <!-- Chart.js Script -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        function dashboardCharts() {
            return {
                conversationsChart: null,
                resizeTimeout: null,
                
                initCharts() {
                    this.$nextTick(() => {
                        this.createConversationsChart();
                    });
                    
                    // Re-init charts when Livewire updates
                    Livewire.on('data-refreshed', () => {
                        this.$nextTick(() => {
                            this.createConversationsChart();
                        });
                    });
                    
                    // Handle window resize with debounce
                    window.addEventListener('resize', () => {
                        clearTimeout(this.resizeTimeout);
                        this.resizeTimeout = setTimeout(() => {
                            if (this.conversationsChart) {
                                this.conversationsChart.resize();
                            }
                        }, 100);
                    });
                },
                
                createConversationsChart() {
                    const ctx = document.getElementById('conversationsChart');
                    if (!ctx) return;
                    
                    // Get parent container dimensions
                    const container = ctx.parentElement;
                    if (!container) return;
                    
                    if (this.conversationsChart) {
                        this.conversationsChart.destroy();
                        this.conversationsChart = null;
                    }
                    
                    const chartData = @json($chartData);
                    
                    this.conversationsChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: chartData.labels || [],
                            datasets: [{
                                label: 'Діалоги',
                                data: chartData.datasets?.conversations || [],
                                borderColor: '#3B82F6',
                                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                fill: true,
                                tension: 0.4,
                                borderWidth: 2,
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            resizeDelay: 0,
                            plugins: {
                                legend: { display: false }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    grid: { color: '#F3F4F6' }
                                },
                                x: {
                                    grid: { display: false }
                                }
                            }
                        }
                    });
                }
            };
        }
    </script>
</div>

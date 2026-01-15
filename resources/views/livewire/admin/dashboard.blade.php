<div wire:poll.60s="loadData" x-data="dashboardCharts()" x-init="initCharts()">
    <!-- Header with Period Selector -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Dashboard</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Бізнес-метрики та аналітика</p>
        </div>
        <div class="flex items-center gap-3">
            <!-- Period Selector -->
            <div class="flex bg-gray-100 dark:bg-gray-700 rounded-lg p-1">
                <button wire:click="setPeriod('today')" class="px-3 py-1.5 text-sm rounded-md transition {{ $period === 'today' ? 'bg-white dark:bg-gray-600 shadow text-blue-600 dark:text-blue-400 font-medium' : 'text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white' }}">
                    Сьогодні
                </button>
                <button wire:click="setPeriod('7d')" class="px-3 py-1.5 text-sm rounded-md transition {{ $period === '7d' ? 'bg-white dark:bg-gray-600 shadow text-blue-600 dark:text-blue-400 font-medium' : 'text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white' }}">
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

    <!-- KPI Cards -->
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
        @foreach($kpis as $key => $kpi)
        <div class="bg-white rounded-xl shadow-sm p-4 hover:shadow-md transition">
            <div class="flex items-center justify-between mb-2">
                <span class="text-2xl">{{ $kpi['icon'] }}</span>
                @if($kpi['change'] != 0)
                    <span class="text-xs px-2 py-0.5 rounded-full {{ $kpi['change'] > 0 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                        {{ $kpi['change'] > 0 ? '+' : '' }}{{ $kpi['change'] }}%
                    </span>
                @endif
            </div>
            <p class="text-2xl font-bold text-gray-900">
                @if(($kpi['format'] ?? '') === 'currency')
                    ₴{{ number_format($kpi['value'], 0, ',', ' ') }}
                @elseif(($kpi['format'] ?? '') === 'percent')
                    {{ $kpi['value'] }}%
                @elseif(($kpi['format'] ?? '') === 'ms')
                    {{ $kpi['value'] }}<span class="text-sm font-normal text-gray-500">ms</span>
                @else
                    {{ number_format($kpi['value']) }}
                @endif
            </p>
            <p class="text-sm text-gray-500 mt-1">{{ $kpi['label'] }}</p>
        </div>
        @endforeach
    </div>

    <!-- Charts Row -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Conversations Chart -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">📈 Діалоги</h3>
            <div class="h-64">
                <canvas id="conversationsChart"></canvas>
            </div>
        </div>
        
        <!-- Revenue Chart -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">💰 Конверсії та виручка</h3>
            <div class="h-64">
                <canvas id="revenueChart"></canvas>
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
                revenueChart: null,
                
                initCharts() {
                    this.$nextTick(() => {
                        this.createConversationsChart();
                        this.createRevenueChart();
                    });
                    
                    // Re-init charts when Livewire updates
                    Livewire.on('data-refreshed', () => {
                        this.$nextTick(() => {
                            this.createConversationsChart();
                            this.createRevenueChart();
                        });
                    });
                },
                
                createConversationsChart() {
                    const ctx = document.getElementById('conversationsChart');
                    if (!ctx) return;
                    
                    if (this.conversationsChart) {
                        this.conversationsChart.destroy();
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
                },
                
                createRevenueChart() {
                    const ctx = document.getElementById('revenueChart');
                    if (!ctx) return;
                    
                    if (this.revenueChart) {
                        this.revenueChart.destroy();
                    }
                    
                    const chartData = @json($chartData);
                    
                    this.revenueChart = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: chartData.labels || [],
                            datasets: [
                                {
                                    label: 'Конверсії',
                                    data: chartData.datasets?.conversions || [],
                                    backgroundColor: '#10B981',
                                    borderRadius: 4,
                                    yAxisID: 'y',
                                },
                                {
                                    label: 'Виручка (₴)',
                                    data: chartData.datasets?.revenue || [],
                                    type: 'line',
                                    borderColor: '#F59E0B',
                                    backgroundColor: 'transparent',
                                    borderWidth: 2,
                                    tension: 0.4,
                                    yAxisID: 'y1',
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: { usePointStyle: true, padding: 20 }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    position: 'left',
                                    grid: { color: '#F3F4F6' },
                                    title: { display: true, text: 'Конверсії' }
                                },
                                y1: {
                                    beginAtZero: true,
                                    position: 'right',
                                    grid: { display: false },
                                    title: { display: true, text: 'Виручка (₴)' }
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

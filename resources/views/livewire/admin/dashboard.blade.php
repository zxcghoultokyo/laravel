<div wire:poll.60s="loadData">
    <!-- Navigation -->
    <div class="mb-4 flex gap-2">
        <a href="{{ route('admin.dashboard') }}" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm">Dashboard</a>
        <a href="{{ route('admin.analytics') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200">📊 Аналітика</a>
        <a href="{{ route('admin.chats.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200">💬 Чати</a>
        <a href="{{ route('admin.widget.settings') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200">⚙️ Віджет</a>
    </div>

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-900">Dashboard</h2>
            <p class="mt-1 text-sm text-gray-500">Моніторинг системи в реальному часі</p>
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
            Оновити
        </button>
    </div>

    <!-- Health Status -->
    <div class="grid grid-cols-5 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow-sm p-4">
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-gray-500">Загальний стан</span>
                @if($health['overall'] === 'healthy')
                    <span class="flex h-3 w-3 relative">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-3 w-3 bg-green-500"></span>
                    </span>
                @else
                    <span class="flex h-3 w-3 relative">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-yellow-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-3 w-3 bg-yellow-500"></span>
                    </span>
                @endif
            </div>
            <p class="mt-2 text-xl font-semibold {{ $health['overall'] === 'healthy' ? 'text-green-600' : 'text-yellow-600' }}">
                {{ $health['overall'] === 'healthy' ? 'OK' : 'Degraded' }}
            </p>
        </div>

        @foreach(['database' => 'MySQL', 'cache' => 'Cache', 'meilisearch' => 'Meili', 'openai' => 'OpenAI'] as $key => $label)
        <div class="bg-white rounded-lg shadow-sm p-4">
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-gray-500">{{ $label }}</span>
                @if(($health[$key]['status'] ?? 'error') === 'ok')
                    <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                @elseif(($health[$key]['status'] ?? 'error') === 'disabled')
                    <span class="w-2 h-2 bg-gray-400 rounded-full"></span>
                @elseif(($health[$key]['status'] ?? 'error') === 'circuit_open')
                    <span class="w-2 h-2 bg-red-500 rounded-full"></span>
                @else
                    <span class="w-2 h-2 bg-yellow-500 rounded-full"></span>
                @endif
            </div>
            <p class="mt-2 text-lg font-semibold text-gray-900">
                @if(isset($health[$key]['latency_ms']))
                    {{ $health[$key]['latency_ms'] }}ms
                @elseif(($health[$key]['status'] ?? '') === 'circuit_open')
                    Circuit Open
                @else
                    {{ ucfirst($health[$key]['status'] ?? 'unknown') }}
                @endif
            </p>
            @if($key === 'meilisearch' && isset($health[$key]['documents']))
                <p class="text-xs text-gray-500">{{ number_format($health[$key]['documents']) }} docs</p>
            @endif
        </div>
        @endforeach
    </div>

    <!-- Circuit Breakers -->
    @if(!empty($metrics['circuit_breakers']))
    <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Circuit Breakers</h3>
        <div class="grid grid-cols-3 gap-4">
            @foreach($metrics['circuit_breakers'] as $service => $state)
            <div class="border rounded-lg p-4 {{ ($state['status'] ?? 'closed') === 'closed' ? 'border-green-200 bg-green-50' : (($state['status'] ?? '') === 'open' ? 'border-red-200 bg-red-50' : 'border-yellow-200 bg-yellow-50') }}">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-medium text-gray-900">{{ ucfirst($service) }}</span>
                    <span class="px-2 py-1 text-xs font-bold rounded {{ ($state['status'] ?? 'closed') === 'closed' ? 'bg-green-100 text-green-800' : (($state['status'] ?? '') === 'open' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800') }}">
                        {{ strtoupper($state['status'] ?? 'closed') }}
                    </span>
                </div>
                <div class="text-sm text-gray-600">
                    <p>Failures: {{ $state['failures'] ?? 0 }}</p>
                    @if(($state['status'] ?? 'closed') !== 'closed')
                        <p>Retry after: {{ $state['retry_after'] ?? 'N/A' }}s</p>
                        <button 
                            wire:click="resetCircuitBreaker('{{ $service }}')"
                            class="mt-2 px-3 py-1 text-xs bg-white border rounded hover:bg-gray-50"
                        >
                            Reset
                        </button>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    <!-- Metrics Grid -->
    <div class="grid grid-cols-4 gap-4 mb-6">
        <!-- Last Hour Stats -->
        <div class="bg-white rounded-lg shadow-sm p-6">
            <p class="text-sm font-medium text-gray-500">Запитів за годину</p>
            <p class="mt-2 text-3xl font-bold text-gray-900">{{ number_format($metrics['last_hour']['requests'] ?? 0) }}</p>
        </div>

        <div class="bg-white rounded-lg shadow-sm p-6">
            <p class="text-sm font-medium text-gray-500">Середній час відповіді</p>
            <p class="mt-2 text-3xl font-bold text-gray-900">{{ $metrics['last_hour']['avg_response_ms'] ?? 0 }}<span class="text-lg font-normal text-gray-500">ms</span></p>
        </div>

        <div class="bg-white rounded-lg shadow-sm p-6">
            <p class="text-sm font-medium text-gray-500">Cache Hit Rate</p>
            <p class="mt-2 text-3xl font-bold {{ ($metrics['last_hour']['cache_hit_rate'] ?? 0) > 50 ? 'text-green-600' : 'text-gray-900' }}">{{ $metrics['last_hour']['cache_hit_rate'] ?? 0 }}%</p>
        </div>

        <div class="bg-white rounded-lg shadow-sm p-6">
            <p class="text-sm font-medium text-gray-500">Fallback Rate</p>
            <p class="mt-2 text-3xl font-bold {{ ($metrics['last_hour']['fallback_rate'] ?? 0) < 10 ? 'text-green-600' : 'text-yellow-600' }}">{{ $metrics['last_hour']['fallback_rate'] ?? 0 }}%</p>
        </div>
    </div>

    <!-- Live Sessions -->
    <div class="grid grid-cols-2 gap-6 mb-6">
        <div class="bg-white rounded-lg shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Активні сесії</h3>
                <span class="px-3 py-1 text-sm font-bold bg-blue-100 text-blue-800 rounded-full">
                    {{ $metrics['live']['active_sessions'] ?? 0 }}
                </span>
            </div>
            <div class="flex items-center gap-4">
                <div class="flex-1 text-center p-4 bg-gray-50 rounded-lg">
                    <p class="text-2xl font-bold text-gray-900">{{ ($metrics['live']['active_sessions'] ?? 0) - ($metrics['live']['operator_sessions'] ?? 0) }}</p>
                    <p class="text-xs text-gray-500">AI обробляє</p>
                </div>
                <div class="flex-1 text-center p-4 bg-green-50 rounded-lg">
                    <p class="text-2xl font-bold text-green-600">{{ $metrics['live']['operator_sessions'] ?? 0 }}</p>
                    <p class="text-xs text-gray-500">Оператор веде</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Сьогодні</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-sm text-gray-500">Всього запитів</p>
                    <p class="text-xl font-bold text-gray-900">{{ number_format($metrics['today']['total_requests'] ?? 0) }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Унікальних сесій</p>
                    <p class="text-xl font-bold text-gray-900">{{ number_format($metrics['today']['unique_sessions'] ?? 0) }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Пошуків товарів</p>
                    <p class="text-xl font-bold text-gray-900">{{ number_format($metrics['today']['product_searches'] ?? 0) }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">AI помилок</p>
                    <p class="text-xl font-bold {{ ($metrics['today']['ai_failures'] ?? 0) > 0 ? 'text-red-600' : 'text-green-600' }}">{{ $metrics['today']['ai_failures'] ?? 0 }}</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Active Sessions List -->
    @if(count($activeSessions) > 0)
    <div class="bg-white rounded-lg shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Останні активні чати</h3>
        </div>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Сесія</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Статус</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Остання активність</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Дії</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @foreach($activeSessions as $session)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <a href="{{ route('admin.chats.show', $session->session_id) }}" class="text-sm font-mono text-blue-600 hover:underline">
                            {{ substr($session->session_id, 0, 12) }}...
                        </a>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @if(($session->status ?? 'ai') === 'operator')
                            <span class="px-2 py-1 text-xs font-bold bg-green-100 text-green-800 rounded-full">
                                Оператор
                            </span>
                        @else
                            <span class="px-2 py-1 text-xs font-bold bg-blue-100 text-blue-800 rounded-full">
                                AI
                            </span>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {{ \Carbon\Carbon::parse($session->last_message_at)->diffForHumans() }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right">
                        <a href="{{ route('admin.chats.show', $session->session_id) }}" class="text-blue-600 hover:text-blue-900 text-sm font-medium">
                            Переглянути →
                        </a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <!-- Quick Actions -->
    <div class="mt-6 flex gap-4">
        <a href="{{ route('admin.chats.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-sm font-medium">
            Всі діалоги →
        </a>
        <a href="{{ route('admin.widget.settings') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-sm font-medium">
            Налаштування віджету →
        </a>
    </div>
</div>

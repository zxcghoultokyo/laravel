<div class="p-6">
    {{-- Flash Messages --}}
    @if (session()->has('message'))
        <div class="mb-4 p-4 bg-green-100 border border-green-200 text-green-700 rounded-lg">
            {{ session('message') }}
        </div>
    @endif
    @if (session()->has('error'))
        <div class="mb-4 p-4 bg-red-100 border border-red-200 text-red-700 rounded-lg">
            {{ session('error') }}
        </div>
    @endif

    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">🔄 Звіти синхронізації</h1>
            <p class="text-sm text-gray-500 mt-1">Моніторинг даних та scheduled tasks</p>
        </div>
        <button wire:click="loadStats" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 flex items-center gap-2">
            <svg wire:loading wire:target="loadStats" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <span wire:loading.remove wire:target="loadStats">🔄</span>
            Оновити
        </button>
    </div>

    {{-- Stats Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        {{-- Products --}}
        <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-blue-500">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-sm text-gray-500 font-medium">🛒 Товари</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">{{ number_format($stats['products']['total'] ?? 0) }}</p>
                </div>
                <span class="text-2xl">📦</span>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-2 text-sm">
                <div class="bg-green-50 rounded p-2">
                    <span class="text-green-600">✅ В наявності</span>
                    <p class="font-bold text-green-700">{{ number_format($stats['products']['in_stock'] ?? 0) }}</p>
                    <p class="text-xs text-green-500">{{ $stats['products']['in_stock_percent'] ?? 0 }}%</p>
                </div>
                <div class="bg-red-50 rounded p-2">
                    <span class="text-red-600">❌ Немає</span>
                    <p class="font-bold text-red-700">{{ number_format($stats['products']['out_of_stock'] ?? 0) }}</p>
                </div>
            </div>
            <div class="mt-3 pt-3 border-t border-gray-100 text-xs text-gray-500">
                <span class="text-green-600">+{{ $stats['products']['new_today'] ?? 0 }} нових</span> • 
                <span class="text-blue-600">{{ $stats['products']['updated_today'] ?? 0 }} оновлено</span> сьогодні
            </div>
        </div>

        {{-- AI Index --}}
        <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-purple-500">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-sm text-gray-500 font-medium">🤖 AI Індекс</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">{{ number_format($stats['ai_index']['with_ai'] ?? 0) }}</p>
                </div>
                <span class="text-2xl">🧠</span>
            </div>
            <div class="mt-4">
                <div class="flex justify-between text-sm mb-1">
                    <span class="text-gray-600">Покриття</span>
                    <span class="font-medium {{ ($stats['ai_index']['coverage_percent'] ?? 0) >= 80 ? 'text-green-600' : 'text-yellow-600' }}">
                        {{ $stats['ai_index']['coverage_percent'] ?? 0 }}%
                    </span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="bg-purple-500 h-2 rounded-full" style="width: {{ $stats['ai_index']['coverage_percent'] ?? 0 }}%"></div>
                </div>
            </div>
            <div class="mt-3 pt-3 border-t border-gray-100 text-xs">
                <span class="text-gray-500">Без AI: {{ number_format($stats['ai_index']['without_ai'] ?? 0) }}</span> • 
                <span class="text-purple-600">Embeddings: {{ $stats['ai_index']['embeddings_percent'] ?? 0 }}%</span>
            </div>
        </div>

        {{-- Meilisearch --}}
        <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 {{ ($stats['meilisearch']['connected'] ?? false) ? 'border-green-500' : 'border-red-500' }}">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-sm text-gray-500 font-medium">🔍 Meilisearch</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">{{ number_format($stats['meilisearch']['documents'] ?? 0) }}</p>
                </div>
                @if($stats['meilisearch']['connected'] ?? false)
                    <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">🟢 Online</span>
                @else
                    <span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-700">🔴 Offline</span>
                @endif
            </div>
            <div class="mt-4 text-sm">
                @if($stats['meilisearch']['is_indexing'] ?? false)
                    <div class="flex items-center gap-2 text-yellow-600">
                        <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Індексується...
                    </div>
                @else
                    <span class="text-gray-500">Готовий до пошуку</span>
                @endif
            </div>
            <div class="mt-3 pt-3 border-t border-gray-100 text-xs text-gray-500">
                @php
                    $diff = ($stats['products']['total'] ?? 0) - ($stats['meilisearch']['documents'] ?? 0);
                @endphp
                @if($diff > 0)
                    <span class="text-yellow-600">⚠️ {{ $diff }} товарів не індексовано</span>
                @else
                    <span class="text-green-600">✅ Всі товари індексовані</span>
                @endif
            </div>
        </div>

        {{-- Orders --}}
        <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-amber-500">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-sm text-gray-500 font-medium">📦 Замовлення</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">{{ number_format($stats['orders']['total'] ?? 0) }}</p>
                </div>
                <span class="text-2xl">🛍️</span>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-2 text-sm">
                <div class="bg-amber-50 rounded p-2">
                    <span class="text-amber-600">Сьогодні</span>
                    <p class="font-bold text-amber-700">{{ $stats['orders']['today'] ?? 0 }}</p>
                </div>
                <div class="bg-blue-50 rounded p-2">
                    <span class="text-blue-600">За тиждень</span>
                    <p class="font-bold text-blue-700">{{ $stats['orders']['week'] ?? 0 }}</p>
                </div>
            </div>
            <div class="mt-3 pt-3 border-t border-gray-100 text-xs text-gray-500">
                <span class="text-green-600">💬 {{ $stats['orders']['chat_attributed'] ?? 0 }} з чату</span>
            </div>
        </div>
    </div>

    {{-- Schedule Info --}}
    <div class="bg-white rounded-xl shadow-sm mb-8">
        <div class="p-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">⏰ Розклад синхронізацій</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Задача</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Розклад</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Остання синхронізація</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Команда</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Дія</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($scheduleInfo as $schedule)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $schedule['name'] }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ $schedule['schedule'] }}</td>
                            <td class="px-4 py-3 text-sm text-gray-500">{{ $schedule['last_run'] }}</td>
                            <td class="px-4 py-3 text-xs font-mono text-gray-400">{{ $schedule['command'] }}</td>
                            <td class="px-4 py-3 text-right">
                                <button wire:click="runSync('{{ $schedule['command'] }}')" 
                                        class="px-3 py-1 text-xs bg-blue-100 text-blue-700 rounded hover:bg-blue-200 transition">
                                    ▶️ Запустити
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Sync History --}}
    <div class="bg-white rounded-xl shadow-sm">
        <div class="p-4 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-lg font-semibold text-gray-900">📜 Історія синхронізацій</h2>
            <div class="flex gap-2">
                <select wire:change="setHistoryDays($event.target.value)" class="text-sm border-gray-300 rounded-lg">
                    <option value="1" {{ $historyDays == 1 ? 'selected' : '' }}>Сьогодні</option>
                    <option value="7" {{ $historyDays == 7 ? 'selected' : '' }}>7 днів</option>
                    <option value="30" {{ $historyDays == 30 ? 'selected' : '' }}>30 днів</option>
                </select>
                <select wire:change="filterByType($event.target.value)" class="text-sm border-gray-300 rounded-lg">
                    <option value="">Всі типи</option>
                    <option value="horoshop_products">🛒 Товари</option>
                    <option value="orders">📦 Замовлення</option>
                    <option value="ai_enrichment">🤖 AI</option>
                    <option value="meilisearch">🔍 Meili</option>
                    <option value="categories">📁 Категорії</option>
                    <option value="embeddings">🧬 Embeddings</option>
                    <option value="stats">📊 Stats</option>
                </select>
            </div>
        </div>
        
        @if(empty($syncHistory))
            <div class="p-12 text-center text-gray-400">
                <div class="text-5xl mb-3">📭</div>
                <p class="text-lg">Історія синхронізацій порожня</p>
                <p class="text-sm mt-2">Логи з'являться після виконання scheduled tasks</p>
                <p class="text-xs mt-4 text-gray-300">Таблиця sync_logs створюється міграцією</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Тип</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Статус</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Дата</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Тривалість</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Результат</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Деталі</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($syncHistory as $log)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-sm">
                                    {{ \App\Models\SyncLog::$typeLabels[$log['sync_type']] ?? $log['sync_type'] }}
                                </td>
                                <td class="px-4 py-3">
                                    @if($log['status'] === 'completed')
                                        <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">✅ Завершено</span>
                                    @elseif($log['status'] === 'running')
                                        <span class="px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-700">⏳ Виконується</span>
                                    @else
                                        <span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-700">❌ Помилка</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600">
                                    {{ \Carbon\Carbon::parse($log['started_at'])->format('d.m.Y H:i') }}
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500">
                                    @if($log['duration_seconds'])
                                        {{ $log['duration_seconds'] < 60 ? $log['duration_seconds'] . ' сек' : floor($log['duration_seconds'] / 60) . ' хв' }}
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <div class="flex gap-2 text-xs">
                                        @if($log['created'] > 0)
                                            <span class="text-green-600">+{{ $log['created'] }}</span>
                                        @endif
                                        @if($log['updated'] > 0)
                                            <span class="text-blue-600">↻{{ $log['updated'] }}</span>
                                        @endif
                                        @if($log['skipped'] > 0)
                                            <span class="text-gray-400">⊘{{ $log['skipped'] }}</span>
                                        @endif
                                        @if($log['failed'] > 0)
                                            <span class="text-red-600">✗{{ $log['failed'] }}</span>
                                        @endif
                                        @if($log['total_processed'] > 0 && $log['created'] == 0 && $log['updated'] == 0)
                                            <span class="text-gray-500">{{ $log['total_processed'] }} оброблено</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-400">
                                    @if($log['error_message'])
                                        <span class="text-red-500" title="{{ $log['error_message'] }}">
                                            {{ Str::limit($log['error_message'], 50) }}
                                        </span>
                                    @elseif($log['metrics'])
                                        @php $metrics = is_array($log['metrics']) ? $log['metrics'] : json_decode($log['metrics'], true); @endphp
                                        @if(!empty($metrics))
                                            @foreach(array_slice($metrics, 0, 3) as $key => $value)
                                                <span class="mr-2">{{ $key }}: {{ is_numeric($value) ? number_format($value) : $value }}</span>
                                            @endforeach
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Data Flow Diagram --}}
    <div class="mt-8 bg-gradient-to-br from-gray-50 to-gray-100 rounded-xl p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">🔄 Потік даних</h3>
        <div class="flex flex-wrap items-center justify-center gap-4 text-sm">
            <div class="bg-white rounded-lg p-3 shadow text-center">
                <div class="text-2xl mb-1">🌐</div>
                <div class="font-medium">Horoshop</div>
                <div class="text-xs text-gray-400">API</div>
            </div>
            <div class="text-gray-400">→</div>
            <div class="bg-white rounded-lg p-3 shadow text-center">
                <div class="text-2xl mb-1">🗄️</div>
                <div class="font-medium">Products</div>
                <div class="text-xs text-gray-400">03:00</div>
            </div>
            <div class="text-gray-400">→</div>
            <div class="bg-white rounded-lg p-3 shadow text-center">
                <div class="text-2xl mb-1">🤖</div>
                <div class="font-medium">AI Index</div>
                <div class="text-xs text-gray-400">04:00</div>
            </div>
            <div class="text-gray-400">→</div>
            <div class="bg-white rounded-lg p-3 shadow text-center">
                <div class="text-2xl mb-1">🔍</div>
                <div class="font-medium">Meilisearch</div>
                <div class="text-xs text-gray-400">05:00</div>
            </div>
            <div class="text-gray-400">→</div>
            <div class="bg-white rounded-lg p-3 shadow text-center">
                <div class="text-2xl mb-1">💬</div>
                <div class="font-medium">Chat Bot</div>
                <div class="text-xs text-gray-400">Ready</div>
            </div>
        </div>
    </div>
</div>

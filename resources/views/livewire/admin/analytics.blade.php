<div class="p-6">
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
        <!-- Stats Cards (unified) -->
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 mb-6">
            {{-- Page visitors (from analytics.js tracking) --}}
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-2xl font-bold text-gray-600">{{ number_format($stats['page_visitors'] ?? 0) }}</div>
                <div class="text-sm text-gray-500">Відвідувачів</div>
                <div class="text-xs text-gray-400">{{ number_format($stats['page_views'] ?? 0) }} переглядів</div>
            </div>
            {{-- Widget opens --}}
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['chat_opened_users'] ?? 0) }}</div>
                <div class="text-sm text-gray-500">Відкрили чат</div>
                <div class="text-xs text-blue-600">{{ $stats['widget_open_rate'] ?? 0 }}% конверсія</div>
            </div>
            {{-- Chat sessions --}}
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-2xl font-bold text-green-600">{{ number_format($stats['sessions'] ?? 0) }}</div>
                <div class="text-sm text-gray-500">Сесій з діалогом</div>
            </div>
            {{-- Messages --}}
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-2xl font-bold text-purple-600">{{ number_format($stats['messages'] ?? 0) }}</div>
                <div class="text-sm text-gray-500">Повідомлень</div>
                <div class="text-xs text-gray-400">{{ $stats['avg_messages'] ?? 0 }} msg/сесія</div>
            </div>
            {{-- Products shown --}}
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-2xl font-bold text-orange-600">{{ number_format($stats['products_shown'] ?? 0) }}</div>
                <div class="text-sm text-gray-500">Показано товарів</div>
            </div>
            {{-- Product clicks --}}
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-2xl font-bold text-cyan-600">{{ number_format($stats['products_clicked'] ?? 0) }}</div>
                <div class="text-sm text-gray-500">Кліків</div>
                <div class="text-xs text-gray-400">{{ $stats['ctr'] ?? 0 }}% CTR</div>
            </div>
        </div>

        <!-- Conversion Funnel -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-sm font-semibold text-gray-500">🔄 Воронка конверсії</h3>
                @php
                    $firstStage = $funnel[0]['count'] ?? 0;
                    $lastStage = $funnel[count($funnel) - 1]['count'] ?? 0;
                    $overallConversion = $firstStage > 0 ? round(($lastStage / $firstStage) * 100, 2) : 0;
                @endphp
                @if($firstStage > 0)
                    <span class="text-sm bg-green-100 text-green-700 px-3 py-1 rounded-full">
                        {{ $overallConversion }}% загальна конверсія
                    </span>
                @endif
            </div>
            
            @if(!empty($funnel))
                <div class="space-y-3">
                    @foreach($funnel as $index => $stage)
                        <div class="group">
                            <div class="flex items-center justify-between mb-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-xl">{{ $stage['icon'] }}</span>
                                    <span class="text-sm font-medium text-gray-700">{{ $stage['label'] }}</span>
                                    <span class="text-xs text-gray-400 hidden group-hover:inline">{{ $stage['hint'] }}</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-lg font-bold text-gray-900">{{ number_format($stage['count']) }}</span>
                                    @if($index > 0 && $stage['rate'] > 0)
                                        <span class="text-xs px-2 py-0.5 rounded-full font-medium {{ $stage['rate'] >= 50 ? 'bg-green-100 text-green-700' : ($stage['rate'] >= 20 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') }}">
                                            {{ $stage['rate'] }}%
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <!-- Progress bar -->
                            @php
                                $maxCount = $funnel[0]['count'] ?? 1;
                                $width = $maxCount > 0 ? ($stage['count'] / $maxCount) * 100 : 0;
                            @endphp
                            <div class="h-6 bg-gray-100 rounded overflow-hidden relative">
                                <div class="h-full rounded transition-all duration-500 {{ $index === count($funnel) - 1 ? 'bg-green-500' : 'bg-blue-500' }}" 
                                     style="width: {{ $width }}%"></div>
                                @if($stage['dropoff'] > 0 && $index > 0)
                                    <span class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-gray-500">
                                        -{{ $stage['dropoff'] }}%
                                    </span>
                                @endif
                            </div>
                        </div>
                        @if(!$loop->last)
                            <div class="flex justify-center">
                                <svg class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </div>
                        @endif
                    @endforeach
                </div>
                
                @if(($funnel[0]['count'] ?? 0) == 0)
                    <div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800">
                        ℹ️ Дані з'являться коли відвідувачі почнуть взаємодіяти з віджетом
                    </div>
                @endif
            @else
                <div class="text-center py-8 text-gray-400">
                    <p>Немає даних за вибраний період</p>
                </div>
            @endif
        </div>

        <!-- AI Index Quality Score -->
        @if(!empty($aiQuality) && !isset($aiQuality['error']))
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="flex justify-between items-center mb-3">
                <h3 class="text-sm font-semibold text-gray-500">🤖 Якість AI-пошуку</h3>
                <a href="{{ url('/api/diagnostic/ai-index-problems?key=diagnostic_secret_key_2025') }}" 
                   target="_blank" 
                   class="text-xs text-blue-600 hover:underline">Детальніше →</a>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <!-- Overall Score -->
                <div class="text-center p-3 rounded-lg {{ 
                    $aiQuality['score'] >= 90 ? 'bg-green-50 border border-green-200' : 
                    ($aiQuality['score'] >= 70 ? 'bg-yellow-50 border border-yellow-200' : 
                    'bg-red-50 border border-red-200') 
                }}">
                    <div class="text-3xl font-bold {{ 
                        $aiQuality['score'] >= 90 ? 'text-green-600' : 
                        ($aiQuality['score'] >= 70 ? 'text-yellow-600' : 'text-red-600') 
                    }}">
                        {{ $aiQuality['grade'] }}
                    </div>
                    <div class="text-lg font-semibold text-gray-700">{{ $aiQuality['score'] }}%</div>
                    <div class="text-xs text-gray-500">Quality Score</div>
                </div>
                
                <!-- Coverage -->
                <div class="text-center p-3 bg-gray-50 rounded-lg">
                    <div class="text-2xl font-bold text-gray-700">{{ $aiQuality['coverage'] }}%</div>
                    <div class="text-xs text-gray-500">Покриття</div>
                    <div class="text-xs text-gray-400">{{ number_format($aiQuality['total_indexed']) }}/{{ number_format($aiQuality['total_products']) }}</div>
                </div>
                
                <!-- Type Coverage -->
                <div class="text-center p-3 bg-gray-50 rounded-lg">
                    <div class="text-2xl font-bold text-blue-600">{{ $aiQuality['type_coverage'] }}%</div>
                    <div class="text-xs text-gray-500">З типом</div>
                </div>
                
                <!-- Slang Coverage -->
                <div class="text-center p-3 bg-gray-50 rounded-lg">
                    <div class="text-2xl font-bold text-purple-600">{{ $aiQuality['slang_coverage'] }}%</div>
                    <div class="text-xs text-gray-500">Зі сленгом</div>
                    <div class="text-xs text-gray-400">avg {{ $aiQuality['avg_slang'] }} слів</div>
                </div>
                
                <!-- Issues -->
                <div class="text-center p-3 rounded-lg {{ $aiQuality['high_priority_issues'] > 0 ? 'bg-red-50 border border-red-200' : 'bg-green-50' }}">
                    <div class="text-2xl font-bold {{ $aiQuality['high_priority_issues'] > 0 ? 'text-red-600' : 'text-green-600' }}">
                        {{ $aiQuality['high_priority_issues'] }}
                    </div>
                    <div class="text-xs text-gray-500">Критичних</div>
                    <div class="text-xs text-gray-400">{{ $aiQuality['recommendations_count'] }} всього</div>
                </div>
            </div>
            
            @if($aiQuality['score'] < 70)
            <div class="mt-3 p-2 bg-yellow-50 border border-yellow-200 rounded text-sm text-yellow-800">
                ⚠️ Низький score впливає на якість пошуку. Запустіть: <code class="bg-yellow-100 px-1 rounded">php artisan products:build-ai-index --only-missing</code>
            </div>
            @endif
        </div>
        @endif

        <!-- A/B Testing Stats -->
        @if(!empty($abTestStats) && !isset($abTestStats['error']))
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="flex justify-between items-center mb-3">
                <h3 class="text-sm font-semibold text-gray-500">🧪 A/B Тестування пошуку</h3>
                <div class="flex items-center gap-2">
                    <span class="text-xs px-2 py-1 rounded {{ $abTestStats['enabled'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                        {{ $abTestStats['enabled'] ? '✓ Активний' : '○ Неактивний' }}
                    </span>
                    <a href="{{ url('/api/diagnostic/ab-test-stats?key=diagnostic_secret_key_2025') }}" 
                       target="_blank" 
                       class="text-xs text-blue-600 hover:underline">JSON →</a>
                </div>
            </div>
            
            @if($abTestStats['has_data'])
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Control Variant -->
                <div class="p-4 bg-gray-50 rounded-lg border border-gray-200">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="text-lg">🔵</span>
                        <span class="font-semibold text-gray-700">Control</span>
                        <span class="text-xs text-gray-500">(тільки keyword)</span>
                    </div>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <div class="text-2xl font-bold text-gray-700">{{ $abTestStats['control']['total_searches'] ?? 0 }}</div>
                            <div class="text-xs text-gray-500">Пошуків</div>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-gray-700">{{ $abTestStats['control']['unique_sessions'] ?? 0 }}</div>
                            <div class="text-xs text-gray-500">Сесій</div>
                        </div>
                        <div>
                            <div class="text-xl font-bold {{ ($abTestStats['control']['zero_results_rate'] ?? 0) > 10 ? 'text-red-600' : 'text-gray-700' }}">
                                {{ $abTestStats['control']['zero_results_rate'] ?? 0 }}%
                            </div>
                            <div class="text-xs text-gray-500">Zero Results</div>
                        </div>
                        <div>
                            <div class="text-xl font-bold text-blue-600">{{ $abTestStats['control']['click_through_rate'] ?? 0 }}%</div>
                            <div class="text-xs text-gray-500">CTR</div>
                        </div>
                    </div>
                </div>
                
                <!-- Treatment Variant -->
                <div class="p-4 bg-blue-50 rounded-lg border border-blue-200">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="text-lg">🟢</span>
                        <span class="font-semibold text-blue-700">Treatment</span>
                        <span class="text-xs text-blue-500">(keyword + AI)</span>
                    </div>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <div class="text-2xl font-bold text-blue-700">{{ $abTestStats['treatment']['total_searches'] ?? 0 }}</div>
                            <div class="text-xs text-gray-500">Пошуків</div>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-blue-700">{{ $abTestStats['treatment']['unique_sessions'] ?? 0 }}</div>
                            <div class="text-xs text-gray-500">Сесій</div>
                        </div>
                        <div>
                            <div class="text-xl font-bold {{ ($abTestStats['treatment']['zero_results_rate'] ?? 0) > 10 ? 'text-red-600' : 'text-green-600' }}">
                                {{ $abTestStats['treatment']['zero_results_rate'] ?? 0 }}%
                            </div>
                            <div class="text-xs text-gray-500">Zero Results</div>
                        </div>
                        <div>
                            <div class="text-xl font-bold text-green-600">{{ $abTestStats['treatment']['click_through_rate'] ?? 0 }}%</div>
                            <div class="text-xs text-gray-500">CTR</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Comparison Summary -->
            @if(!empty($abTestStats['comparison']) && $abTestStats['comparison']['winner'] !== 'tie')
            <div class="mt-4 p-3 rounded-lg {{ $abTestStats['comparison']['winner'] === 'treatment' ? 'bg-green-50 border border-green-200' : 'bg-gray-50 border border-gray-200' }}">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">{{ $abTestStats['comparison']['winner'] === 'treatment' ? '🏆' : '📊' }}</span>
                        <span class="font-medium {{ $abTestStats['comparison']['winner'] === 'treatment' ? 'text-green-700' : 'text-gray-700' }}">
                            Лідер: {{ $abTestStats['comparison']['winner'] === 'treatment' ? 'Treatment (AI)' : 'Control' }}
                        </span>
                    </div>
                    <div class="text-sm text-gray-500">
                        {{ $abTestStats['comparison']['confidence'] ?? 'insufficient_data' }}
                    </div>
                </div>
                @if(isset($abTestStats['comparison']['zero_results_improvement']))
                <div class="mt-2 grid grid-cols-3 gap-2 text-xs">
                    <div class="text-center">
                        <div class="font-bold {{ $abTestStats['comparison']['zero_results_improvement'] < 0 ? 'text-green-600' : 'text-red-600' }}">
                            {{ $abTestStats['comparison']['zero_results_improvement'] > 0 ? '+' : '' }}{{ $abTestStats['comparison']['zero_results_improvement'] }}%
                        </div>
                        <div class="text-gray-500">Zero Results</div>
                    </div>
                    <div class="text-center">
                        <div class="font-bold {{ $abTestStats['comparison']['ctr_improvement'] > 0 ? 'text-green-600' : 'text-red-600' }}">
                            {{ $abTestStats['comparison']['ctr_improvement'] > 0 ? '+' : '' }}{{ $abTestStats['comparison']['ctr_improvement'] }}%
                        </div>
                        <div class="text-gray-500">CTR</div>
                    </div>
                    <div class="text-center">
                        <div class="font-bold {{ $abTestStats['comparison']['add_to_cart_improvement'] > 0 ? 'text-green-600' : 'text-red-600' }}">
                            {{ $abTestStats['comparison']['add_to_cart_improvement'] > 0 ? '+' : '' }}{{ $abTestStats['comparison']['add_to_cart_improvement'] }}%
                        </div>
                        <div class="text-gray-500">Add to Cart</div>
                    </div>
                </div>
                @endif
            </div>
            @else
            <div class="mt-4 p-3 bg-gray-50 border border-gray-200 rounded-lg text-center text-sm text-gray-500">
                📊 Недостатньо даних для визначення переможця. Потрібно мінімум 100 пошуків на варіант.
            </div>
            @endif
            
            @else
            <div class="text-center py-8">
                <div class="text-4xl mb-2">🧪</div>
                <div class="text-gray-500">Експеримент активний, але ще немає даних</div>
                <div class="text-xs text-gray-400 mt-2">
                    Дані з'являться після того як користувачі почнуть шукати товари
                </div>
            </div>
            @endif
        </div>
        @endif

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

            <!-- Top Viewed Products (NEW) -->
            <div class="bg-white rounded-lg shadow p-4">
                <h2 class="font-semibold text-gray-700 mb-4">👁️ Найчастіше переглядають</h2>
                @if(count($topViewedProducts) > 0)
                    <div class="space-y-2">
                        @foreach($topViewedProducts as $product)
                            <div class="flex items-center gap-2 p-2 bg-gray-50 rounded">
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-gray-800 truncate">{{ $product['title'] }}</div>
                                    <div class="text-xs text-gray-500">{{ $product['article'] }} • {{ number_format($product['price'], 0) }} ₴</div>
                                </div>
                                <div class="bg-blue-100 text-blue-700 px-2 py-1 rounded text-sm font-medium">
                                    {{ $product['views'] }} показів
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-gray-400 text-center py-8">Немає даних</div>
                @endif
            </div>
        </div>
        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
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
            
            <!-- Recent Chat Events Only -->
            <div class="bg-white rounded-lg shadow p-4">
                <h2 class="font-semibold text-gray-700 mb-4">💬 Останні події чатів</h2>
                @if(count($recentChatEvents) > 0)
                    <div class="space-y-1 max-h-80 overflow-y-auto">
                        @foreach($recentChatEvents as $event)
                            @php
                                $eventIcon = match($event->event_type) {
                                    'message' => '💬',
                                    'chat_opened' => '📬',
                                    'chat_closed' => '📭',
                                    'session_start' => '🚀',
                                    'quick_action_click' => '⚡',
                                    default => '📌'
                                };
                            @endphp
                            <div class="flex items-center gap-2 text-sm py-1 border-b border-gray-50">
                                <span class="text-xs text-gray-400 w-16">{{ \Carbon\Carbon::parse($event->created_at)->format('H:i:s') }}</span>
                                <span>{{ $eventIcon }}</span>
                                <span class="text-gray-600">{{ $event->event_type }}</span>
                                @if($embedded)
                                    {{-- In embedded mode, link to dashboard with chat params --}}
                                    <a href="{{ url('/dashboard') }}?activeTab=chats&selectedChatId={{ $event->session_id }}"
                                       class="text-xs text-blue-500 hover:underline truncate ml-auto" style="max-width: 120px;">
                                        {{ substr($event->session_id, -12) }}
                                    </a>
                                @else
                                    <a href="{{ route('admin.chats.show', $event->session_id) }}" 
                                       class="text-xs text-blue-500 hover:underline truncate ml-auto" style="max-width: 120px;">
                                        {{ substr($event->session_id, -12) }}
                                    </a>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-gray-400 text-center py-8">Немає подій</div>
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
                        <div class="bg-{{ $color }}-50 $color }}-900/30 border border-{{ $color }}-200 $color }}-700 rounded-lg p-3">
                            <div class="flex items-center gap-2">
                                <span>{{ $icon }}</span>
                                <span class="text-sm text-{{ $color }}-700 $color }}-300">{{ $outcome->outcome }}</span>
                            </div>
                            <div class="text-xl font-bold text-{{ $color }}-800 $color }}-200 mt-1">{{ $outcome->count }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</div>

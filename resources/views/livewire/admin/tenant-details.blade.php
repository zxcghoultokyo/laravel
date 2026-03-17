<div>
    @section('title', "Тенант: {$tenant->name}")

    <!-- Header with back button -->
    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <a href="{{ route('admin.tenants') }}" class="p-2 text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-lg transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $tenant->name }}</h1>
                <p class="text-gray-500">{{ $tenant->slug }} • {{ $tenant->domain ?? 'Без домену' }}</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <span class="px-3 py-1 text-sm font-medium rounded-full 
                {{ $tenant->status === 'active' ? 'bg-green-100 text-green-700' : '' }}
                {{ $tenant->status === 'trial' ? 'bg-blue-100 text-blue-700' : '' }}
                {{ $tenant->status === 'suspended' ? 'bg-red-100 text-red-700' : '' }}">
                {{ $tenant->status === 'active' ? 'Активний' : ($tenant->status === 'trial' ? 'Тріал' : 'Призупинено') }}
            </span>
            <span class="px-3 py-1 text-sm font-medium rounded-full bg-purple-100 text-purple-700">
                {{ ucfirst($tenant->subscription?->plan ?? 'trial') }}
            </span>
        </div>
    </div>

    <!-- Flash Messages -->
    @if(session()->has('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 text-green-700 rounded-lg">
            {{ session('success') }}
        </div>
    @endif
    @if(session()->has('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg">
            {{ session('error') }}
        </div>
    @endif
    @if(session()->has('warning'))
        <div class="mb-4 p-4 bg-yellow-50 border border-yellow-200 text-yellow-700 rounded-lg">
            {{ session('warning') }}
        </div>
    @endif

    <!-- Stats Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm p-4 border border-gray-100">
            <div class="text-2xl font-bold text-gray-900">{{ number_format($stats['products_count']) }}</div>
            <div class="text-sm text-gray-500">Товарів</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 border border-green-100">
            <div class="text-2xl font-bold text-green-600">{{ number_format($stats['products_in_stock']) }}</div>
            <div class="text-sm text-gray-500">В наявності</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 border border-blue-100">
            <div class="text-2xl font-bold text-blue-600">{{ $stats['categories_count'] }}</div>
            <div class="text-sm text-gray-500">Категорій</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 border border-purple-100">
            <div class="text-2xl font-bold text-purple-600">{{ number_format($stats['sessions_count']) }}</div>
            <div class="text-sm text-gray-500">Чатів</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 border border-orange-100">
            <div class="text-2xl font-bold text-orange-600">{{ number_format($stats['messages_count']) }}</div>
            <div class="text-sm text-gray-500">Повідомлень</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 border border-gray-100">
            <div class="text-sm font-medium text-gray-900">{{ $stats['last_sync'] }}</div>
            <div class="text-sm text-gray-500">Остання синхр.</div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="border-b border-gray-200 mb-6">
        <nav class="flex gap-4">
            <button wire:click="$set('activeTab', 'overview')" 
                    class="py-3 px-1 border-b-2 font-medium text-sm transition
                           {{ $activeTab === 'overview' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                Огляд
            </button>
            <button wire:click="$set('activeTab', 'analytics')" 
                    class="py-3 px-1 border-b-2 font-medium text-sm transition
                           {{ $activeTab === 'analytics' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                📊 Аналітика
            </button>
            <button wire:click="$set('activeTab', 'sync')" 
                    class="py-3 px-1 border-b-2 font-medium text-sm transition
                           {{ $activeTab === 'sync' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                Синхронізація
            </button>
            <button wire:click="$set('activeTab', 'chats')" 
                    class="py-3 px-1 border-b-2 font-medium text-sm transition
                           {{ $activeTab === 'chats' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                Чати
            </button>
            <button wire:click="$set('activeTab', 'settings')" 
                    class="py-3 px-1 border-b-2 font-medium text-sm transition
                           {{ $activeTab === 'settings' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                Налаштування
            </button>
        </nav>
    </div>

    <!-- Tab Content -->
    <div>
        @if($activeTab === 'overview')
            <!-- Overview Tab -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Tenant Info -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold mb-4">Інформація про тенанта</h3>
                    <dl class="space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">ID</dt>
                            <dd class="font-mono text-sm">{{ $tenant->id }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Slug</dt>
                            <dd class="font-mono text-sm">{{ $tenant->slug }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Домен</dt>
                            <dd>{{ $tenant->domain ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Платформа</dt>
                            <dd>
                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-orange-100 text-orange-700">
                                    {{ $tenant->platform ?? 'Не вказано' }}
                                </span>
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">API Key</dt>
                            <dd class="font-mono text-xs">{{ substr($tenant->api_key ?? '', 0, 20) }}...</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Створено</dt>
                            <dd>{{ $tenant->created_at->format('d.m.Y H:i') }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Тріал до</dt>
                            <dd>{{ $tenant->trial_ends_at?->format('d.m.Y') ?? '—' }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Owner Info -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold mb-4">Власник</h3>
                    @if($tenant->owner)
                        <dl class="space-y-3">
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Ім'я</dt>
                                <dd>{{ $tenant->owner->name }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Email</dt>
                                <dd>{{ $tenant->owner->email }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Роль</dt>
                                <dd>{{ $tenant->owner->role }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Зареєстрований</dt>
                                <dd>{{ $tenant->owner->created_at->format('d.m.Y H:i') }}</dd>
                            </div>
                        </dl>
                    @else
                        <p class="text-gray-500">Власника не знайдено</p>
                    @endif
                </div>

                <!-- Credentials Info -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold mb-4">API Credentials</h3>
                    @if(!empty($tenant->platform_credentials))
                        <dl class="space-y-3">
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Домен API</dt>
                                <dd class="font-mono text-sm">{{ $tenant->platform_credentials['domain'] ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Логін</dt>
                                <dd class="font-mono text-sm">{{ $tenant->platform_credentials['login'] ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Пароль</dt>
                                <dd class="font-mono text-sm">••••••••</dd>
                            </div>
                        </dl>
                        <button wire:click="testConnection" class="mt-4 px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition">
                            🔌 Тестувати підключення
                        </button>
                    @else
                        <p class="text-gray-500">Credentials не налаштовані</p>
                    @endif
                </div>

                <!-- Usage Limits -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold mb-4">Ліміти</h3>
                    <dl class="space-y-3">
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <dt class="text-gray-500">Повідомлення</dt>
                                <dd>{{ number_format($tenant->messages_used) }} / {{ number_format($tenant->messages_limit) }}</dd>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-blue-600 h-2 rounded-full" style="width: {{ min(100, ($tenant->messages_limit > 0 ? $tenant->messages_used / $tenant->messages_limit * 100 : 0)) }}%"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <dt class="text-gray-500">Товари</dt>
                                <dd>{{ number_format($stats['products_count']) }} / {{ number_format($tenant->products_limit) }}</dd>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-green-600 h-2 rounded-full" style="width: {{ min(100, ($tenant->products_limit > 0 ? $stats['products_count'] / $tenant->products_limit * 100 : 0)) }}%"></div>
                            </div>
                        </div>
                    </dl>
                </div>
            </div>

        @elseif($activeTab === 'analytics')
            <!-- Analytics Tab -->
            <div class="space-y-6">
                <!-- Period Selector -->
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold">📊 Аналітика тенанта</h3>
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-gray-500">Період:</span>
                        <select wire:change="setAnalyticsDays($event.target.value)" class="text-sm border border-gray-300 rounded-lg px-3 py-1.5 focus:ring-2 focus:ring-blue-500">
                            <option value="7" {{ $analyticsDays == 7 ? 'selected' : '' }}>7 днів</option>
                            <option value="14" {{ $analyticsDays == 14 ? 'selected' : '' }}>14 днів</option>
                            <option value="30" {{ $analyticsDays == 30 ? 'selected' : '' }}>30 днів</option>
                            <option value="90" {{ $analyticsDays == 90 ? 'selected' : '' }}>90 днів</option>
                        </select>
                        <button wire:click="loadAnalyticsData" class="px-3 py-1.5 text-sm bg-gray-100 hover:bg-gray-200 rounded-lg transition">
                            🔄 Оновити
                        </button>
                    </div>
                </div>

                <!-- Conversion Funnel -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="font-semibold text-lg">🔄 Воронка конверсії</h4>
                        @if(($funnelData['overall_rate'] ?? 0) > 0)
                            <span class="text-sm bg-green-100 text-green-700 px-3 py-1 rounded-full">
                                {{ $funnelData['overall_rate'] }}% загальна конверсія
                            </span>
                        @endif
                    </div>
                    
                    @if(!empty($funnelData['stages']) && collect($funnelData['stages'])->sum('count') > 0)
                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                            @foreach($funnelData['stages'] as $index => $stage)
                                <div class="bg-gradient-to-b from-gray-50 to-white border border-gray-200 rounded-xl p-4 text-center relative" title="{{ $stage['hint'] }}">
                                    <span class="text-2xl">{{ $stage['icon'] }}</span>
                                    <p class="text-2xl font-bold text-gray-900 mt-2">{{ number_format($stage['count']) }}</p>
                                    <p class="text-xs text-gray-500">{{ $stage['label'] }}</p>
                                    @if($index > 0 && $stage['rate'] > 0)
                                        <span class="absolute top-2 right-2 text-xs px-2 py-0.5 rounded-full {{ $stage['rate'] >= 50 ? 'bg-green-100 text-green-700' : ($stage['rate'] >= 20 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') }}">
                                            {{ $stage['rate'] }}%
                                        </span>
                                    @endif
                                    @if($index > 0 && $stage['dropoff'] > 0)
                                        <p class="text-xs text-gray-400 mt-1">-{{ $stage['dropoff'] }}% відсіялось</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-8 text-gray-400">
                            <span class="text-4xl">📊</span>
                            <p class="mt-2">Ще немає даних для воронки</p>
                            <p class="text-sm mt-1">Дані з'являться коли відвідувачі почнуть взаємодіяти з віджетом</p>
                        </div>
                    @endif
                </div>

                <!-- Usage Chart -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h4 class="font-semibold text-lg mb-4">📈 Використання за період</h4>
                    
                    @if(!empty($usageChartData) && collect($usageChartData)->sum('messages') > 0)
                        <!-- Summary Stats -->
                        <div class="grid grid-cols-3 gap-4 mb-6">
                            <div class="text-center p-4 bg-blue-50 rounded-lg">
                                <p class="text-2xl font-bold text-blue-600">{{ number_format(collect($usageChartData)->sum('sessions')) }}</p>
                                <p class="text-xs text-gray-500">Сесій</p>
                            </div>
                            <div class="text-center p-4 bg-purple-50 rounded-lg">
                                <p class="text-2xl font-bold text-purple-600">{{ number_format(collect($usageChartData)->sum('messages')) }}</p>
                                <p class="text-xs text-gray-500">Повідомлень</p>
                            </div>
                            <div class="text-center p-4 bg-green-50 rounded-lg">
                                <p class="text-2xl font-bold text-green-600">{{ number_format(collect($usageChartData)->sum('ai_responses')) }}</p>
                                <p class="text-xs text-gray-500">AI-відповідей</p>
                            </div>
                        </div>

                        <!-- Simple Bar Chart -->
                        <div class="relative h-48 flex items-end gap-1">
                            @php
                                $maxVal = max(collect($usageChartData)->max('messages'), 1);
                            @endphp
                            @foreach($usageChartData as $day)
                                <div class="flex-1 flex flex-col items-center group">
                                    <div class="relative w-full flex flex-col items-center justify-end h-40">
                                        <!-- Messages bar -->
                                        <div class="w-full bg-blue-500 rounded-t transition-all duration-300 hover:bg-blue-600" 
                                             style="height: {{ ($day['messages'] / $maxVal) * 100 }}%"
                                             title="{{ $day['date'] }}: {{ $day['messages'] }} повідомлень">
                                        </div>
                                    </div>
                                    <span class="text-xs text-gray-400 mt-1 {{ count($usageChartData) > 14 ? 'hidden md:block' : '' }}">
                                        {{ $day['date'] }}
                                    </span>
                                    <!-- Tooltip -->
                                    <div class="absolute bottom-full mb-2 hidden group-hover:block bg-gray-800 text-white text-xs rounded px-2 py-1 whitespace-nowrap z-10">
                                        {{ $day['date'] }}: {{ $day['sessions'] }} сесій, {{ $day['messages'] }} повідомлень
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        
                        <div class="flex items-center justify-center gap-4 mt-4 text-xs text-gray-500">
                            <span class="flex items-center gap-1"><span class="w-3 h-3 bg-blue-500 rounded"></span> Повідомлення</span>
                        </div>
                    @else
                        <div class="text-center py-8 text-gray-400">
                            <span class="text-4xl">📈</span>
                            <p class="mt-2">Немає даних за обраний період</p>
                        </div>
                    @endif
                </div>

                <!-- Quick Stats Table -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h4 class="font-semibold text-lg mb-4">📋 Детальна статистика</h4>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left font-medium text-gray-700">Метрика</th>
                                    <th class="px-4 py-3 text-right font-medium text-gray-700">Значення</th>
                                    <th class="px-4 py-3 text-right font-medium text-gray-700">Сьогодні</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr>
                                    <td class="px-4 py-3">💬 Всього чатів</td>
                                    <td class="px-4 py-3 text-right font-medium">{{ number_format($stats['sessions_count']) }}</td>
                                    <td class="px-4 py-3 text-right text-green-600">+{{ $stats['sessions_today'] }}</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3">✉️ Всього повідомлень</td>
                                    <td class="px-4 py-3 text-right font-medium">{{ number_format($stats['messages_count']) }}</td>
                                    <td class="px-4 py-3 text-right text-green-600">+{{ $stats['messages_today'] }}</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3">🤖 AI-відповідей використано</td>
                                    <td class="px-4 py-3 text-right font-medium">{{ number_format($tenant->messages_used) }}</td>
                                    <td class="px-4 py-3 text-right text-gray-400">
                                        @if(empty($tenant->messages_limit) || $tenant->messages_limit <= 0)
                                            ∞
                                        @else
                                            {{ round($tenant->messages_used / $tenant->messages_limit * 100, 1) }}%
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3">📦 Товарів</td>
                                    <td class="px-4 py-3 text-right font-medium">{{ number_format($stats['products_count']) }}</td>
                                    <td class="px-4 py-3 text-right text-gray-400">{{ $stats['products_in_stock'] }} в наявності</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3">🗂 Категорій</td>
                                    <td class="px-4 py-3 text-right font-medium">{{ $stats['categories_count'] }}</td>
                                    <td class="px-4 py-3 text-right text-gray-400">—</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Chat Sessions Drilldown -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h4 class="font-semibold text-lg mb-4">💬 Останні чати за період ({{ count($analyticsSessions) }})</h4>
                    
                    @if($analyticsSessions->isEmpty())
                        <div class="text-center py-6 text-gray-400">
                            <span class="text-3xl">💬</span>
                            <p class="mt-2">Немає чатів за обраний період</p>
                        </div>
                    @else
                        <div class="space-y-2">
                            @foreach($analyticsSessions as $session)
                                @php
                                    $isExpanded = in_array($session->id, $expandedSessions);
                                    $preview = $session->last_user_query ? Str::limit($session->last_user_query, 80) : '—';
                                @endphp
                                <div class="border border-gray-200 rounded-lg overflow-hidden">
                                    <!-- Session Header (clickable) -->
                                    <button wire:click="toggleSession({{ $session->id }})" 
                                            class="w-full flex items-center justify-between px-4 py-3 bg-gray-50 hover:bg-gray-100 transition text-left">
                                        <div class="flex items-center gap-3 min-w-0">
                                            <svg class="w-4 h-4 text-gray-500 shrink-0 transition-transform {{ $isExpanded ? 'rotate-90' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                            </svg>
                                            <div class="min-w-0">
                                                <span class="text-sm font-medium text-gray-700 block truncate">{{ $preview }}</span>
                                                <span class="text-xs text-gray-400">{{ $session->created_at->format('d.m H:i') }} · {{ $session->messages_count }} повід.</span>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2 shrink-0 ml-3">
                                            @if($session->last_intent)
                                                <span class="px-2 py-0.5 text-xs rounded-full bg-blue-50 text-blue-600">{{ $session->last_intent }}</span>
                                            @endif
                                            <span class="px-2 py-0.5 text-xs rounded-full {{ $session->status === 'open' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                                {{ $session->status === 'open' ? 'Відкритий' : 'Закритий' }}
                                            </span>
                                        </div>
                                    </button>

                                    <!-- Expanded Messages -->
                                    @if($isExpanded)
                                        <div class="px-4 py-3 bg-white border-t border-gray-100 max-h-96 overflow-y-auto space-y-2">
                                            @forelse($session->messages->sortBy('created_at') as $msg)
                                                <div class="flex {{ $msg->role === 'user' ? 'justify-end' : 'justify-start' }}">
                                                    <div class="max-w-[85%] px-3 py-2 rounded-lg text-sm 
                                                        {{ $msg->role === 'user' ? 'bg-blue-500 text-white' : ($msg->role === 'system' ? 'bg-yellow-50 text-yellow-800 border border-yellow-200' : 'bg-gray-100 text-gray-800') }}">
                                                        @if($msg->role === 'system')
                                                            <span class="text-xs font-medium">system:</span>
                                                        @endif
                                                        <div class="whitespace-pre-wrap break-words">{{ Str::limit($msg->content, 500) }}</div>
                                                        @if($msg->meta && !empty($msg->meta['products_shown']))
                                                            <div class="mt-1 pt-1 border-t {{ $msg->role === 'user' ? 'border-blue-400' : 'border-gray-200' }}">
                                                                <span class="text-xs opacity-70">📦 Показано {{ count($msg->meta['products_shown']) }} товарів</span>
                                                            </div>
                                                        @endif
                                                        <div class="text-xs mt-1 {{ $msg->role === 'user' ? 'text-blue-200' : 'text-gray-400' }}">
                                                            {{ $msg->created_at->format('H:i:s') }}
                                                        </div>
                                                    </div>
                                                </div>
                                            @empty
                                                <p class="text-sm text-gray-400 text-center py-2">Немає повідомлень</p>
                                            @endforelse
                                            <div class="pt-2 text-center">
                                                <a href="{{ route('admin.chats.show', $session->id) }}" 
                                                   class="text-xs text-blue-600 hover:text-blue-800 hover:underline">
                                                    Відкрити повний чат →
                                                </a>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

        @elseif($activeTab === 'sync')
            <!-- Sync Tab -->
            <div class="space-y-6">
                <!-- Sync Controls -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold mb-4">Керування синхронізацією</h3>
                    
                    @if($stats['sync_running'])
                        <div class="flex items-center gap-4 p-4 bg-blue-50 border border-blue-200 rounded-lg mb-4">
                            <svg class="animate-spin w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span class="text-blue-700">Синхронізація виконується...</span>
                            <button wire:click="cancelSync" class="ml-auto px-3 py-1 bg-red-600 text-white text-sm rounded hover:bg-red-700">
                                Скасувати
                            </button>
                        </div>
                    @endif

                    <div class="flex flex-wrap gap-3">
                        @if(!$stats['sync_running'])
                            <button wire:click="startSync" 
                                    @if(empty($tenant->platform_credentials)) disabled @endif
                                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition disabled:opacity-50 disabled:cursor-not-allowed">
                                🔄 Запустити синхронізацію (async)
                            </button>
                            <button wire:click="startSyncNow" 
                                    wire:loading.attr="disabled"
                                    @if(empty($tenant->platform_credentials)) disabled @endif
                                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition disabled:opacity-50 disabled:cursor-not-allowed">
                                <span wire:loading.remove wire:target="startSyncNow">⚡ Синхронізувати зараз (sync)</span>
                                <span wire:loading wire:target="startSyncNow">⏳ Виконується...</span>
                            </button>
                        @endif
                        <button wire:click="testConnection" 
                                @if(empty($tenant->platform_credentials)) disabled @endif
                                class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition disabled:opacity-50 disabled:cursor-not-allowed">
                            🔌 Тест підключення
                        </button>
                        @if($stats['products_count'] > 0)
                            <button wire:click="clearProducts" 
                                    wire:confirm="Видалити всі {{ $stats['products_count'] }} товарів?"
                                    class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                                🗑 Очистити товари
                            </button>
                        @endif
                    </div>

                    @if(empty($tenant->platform_credentials))
                        <p class="mt-4 text-sm text-yellow-600">
                            ⚠️ API credentials не налаштовані. Синхронізація недоступна.
                        </p>
                    @endif
                </div>

                <!-- Sync Logs -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold mb-4">Журнал синхронізацій</h3>
                    
                    @if($syncLogs->isEmpty())
                        <p class="text-gray-500">Немає записів синхронізації</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-medium text-gray-700">Час</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-700">Статус</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-700">Деталі</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-700">Тривалість</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach($syncLogs as $log)
                                        <tr>
                                            <td class="px-3 py-2 whitespace-nowrap">{{ $log->started_at?->format('d.m H:i') ?? $log->created_at->format('d.m H:i') }}</td>
                                            <td class="px-3 py-2">
                                                <span class="px-2 py-1 text-xs font-medium rounded-full 
                                                    {{ $log->status === 'completed' ? 'bg-green-100 text-green-700' : '' }}
                                                    {{ $log->status === 'failed' ? 'bg-red-100 text-red-700' : '' }}
                                                    {{ $log->status === 'running' ? 'bg-blue-100 text-blue-700' : '' }}">
                                                    {{ $log->status }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-2 text-gray-600 max-w-md truncate">
                                                {{ $log->notes ?? $log->sync_type }}
                                                @if($log->total_processed > 0)
                                                    <span class="text-xs text-gray-500">({{ $log->total_processed }} оброблено)</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-gray-500">
                                                @if($log->duration_seconds)
                                                    {{ $log->duration_seconds }}s
                                                @elseif($log->finished_at)
                                                    {{ $log->started_at->diffInSeconds($log->finished_at) }}s
                                                @else
                                                    —
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

        @elseif($activeTab === 'chats')
            <!-- Chats Tab -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold">Чати ({{ $stats['sessions_count'] }})</h3>
                    <div class="flex gap-2">
                        <!-- Search -->
                        <input type="text" 
                               wire:model.live.debounce.300ms="chatSearch"
                               placeholder="Пошук по сесії або тексту..."
                               class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        
                        <!-- Status Filter -->
                        <select wire:model.live="chatStatus" 
                                class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Всі статуси</option>
                            <option value="open">Відкриті</option>
                            <option value="closed">Закриті</option>
                        </select>

                        @if($chatSearch || $chatStatus)
                            <button wire:click="resetChatFilters" 
                                    class="px-3 py-2 text-sm text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-lg">
                                ✕ Скинути
                            </button>
                        @endif
                    </div>
                </div>
                
                @if($chatSessions->isEmpty())
                    <p class="text-gray-500 py-8 text-center">
                        @if($chatSearch || $chatStatus)
                            Немає чатів за вказаними фільтрами
                        @else
                            Немає чатів
                        @endif
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left font-medium text-gray-700">Session ID</th>
                                    <th class="px-4 py-3 text-left font-medium text-gray-700">Статус</th>
                                    <th class="px-4 py-3 text-left font-medium text-gray-700">Повідомлень</th>
                                    <th class="px-4 py-3 text-left font-medium text-gray-700">Створено</th>
                                    <th class="px-4 py-3 text-left font-medium text-gray-700">Остання активність</th>
                                    <th class="px-4 py-3 text-left font-medium text-gray-700">Дії</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($chatSessions as $session)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3">
                                            <a href="{{ route('admin.chats.show', $session->id) }}" 
                                               class="font-mono text-sm text-blue-600 hover:text-blue-800 hover:underline"
                                               title="{{ $session->session_id }}">
                                                {{ Str::limit($session->session_id, 24) }}
                                            </a>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="px-2 py-1 text-xs font-medium rounded-full 
                                                {{ $session->status === 'open' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                                                {{ $session->status === 'open' ? 'Відкритий' : 'Закритий' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="font-medium">{{ $session->messages_count }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-gray-600">
                                            {{ $session->created_at->format('d.m.Y H:i') }}
                                        </td>
                                        <td class="px-4 py-3 text-gray-600">
                                            {{ $session->updated_at->diffForHumans() }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <a href="{{ route('admin.chats.show', $session->id) }}" 
                                               class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition">
                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                </svg>
                                                Переглянути
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="mt-4">
                        {{ $chatSessions->links() }}
                    </div>
                @endif
            </div>

        @elseif($activeTab === 'settings')
            <!-- Settings Tab -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Widget Settings -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold mb-4">Налаштування віджета</h3>
                    @if($tenant->widgetSettings)
                        <dl class="space-y-3 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Заголовок</dt>
                                <dd>{{ $tenant->widgetSettings->header_text }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Привітання</dt>
                                <dd class="truncate max-w-xs">{{ $tenant->widgetSettings->welcome_message }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Основний колір</dt>
                                <dd>
                                    <span class="inline-block w-6 h-6 rounded" style="background-color: {{ $tenant->widgetSettings->primary_color }}"></span>
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Позиція</dt>
                                <dd>{{ $tenant->widgetSettings->position }}</dd>
                            </div>
                        </dl>
                    @else
                        <p class="text-gray-500">Віджет не налаштований</p>
                    @endif
                </div>

                <!-- Onboarding Status -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold mb-4">Статус онбордингу</h3>
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between items-center">
                            <dt class="text-gray-500">Платформа вибрана</dt>
                            <dd>{!! $tenant->platform ? '✅' : '❌' !!}</dd>
                        </div>
                        <div class="flex justify-between items-center">
                            <dt class="text-gray-500">Credentials налаштовані</dt>
                            <dd>{!! !empty($tenant->platform_credentials) ? '✅' : '❌' !!}</dd>
                        </div>
                        <div class="flex justify-between items-center">
                            <dt class="text-gray-500">Товари синхронізовані</dt>
                            <dd>{!! $stats['products_count'] > 0 ? '✅' : '❌' !!}</dd>
                        </div>
                        <div class="flex justify-between items-center">
                            <dt class="text-gray-500">Віджет налаштований</dt>
                            <dd>{!! $tenant->widgetSettings && $tenant->widgetSettings->header_text !== 'AI Асистент' ? '✅' : '❌' !!}</dd>
                        </div>
                        <div class="flex justify-between items-center">
                            <dt class="text-gray-500">Онбординг завершено</dt>
                            <dd>{!! ($tenant->settings['onboarding_completed'] ?? false) ? '✅' : '❌' !!}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        @endif
    </div>
</div>

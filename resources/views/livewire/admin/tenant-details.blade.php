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
                                            <td class="px-3 py-2 whitespace-nowrap">{{ $log->created_at->format('d.m H:i') }}</td>
                                            <td class="px-3 py-2">
                                                <span class="px-2 py-1 text-xs font-medium rounded-full 
                                                    {{ $log->status === 'completed' ? 'bg-green-100 text-green-700' : '' }}
                                                    {{ $log->status === 'failed' ? 'bg-red-100 text-red-700' : '' }}
                                                    {{ $log->status === 'running' ? 'bg-blue-100 text-blue-700' : '' }}">
                                                    {{ $log->status }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-2 text-gray-600 max-w-md truncate">
                                                {{ $log->details }}
                                            </td>
                                            <td class="px-3 py-2 text-gray-500">
                                                @if($log->completed_at)
                                                    {{ $log->created_at->diffInSeconds($log->completed_at) }}s
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
                <h3 class="text-lg font-semibold mb-4">Останні чати</h3>
                
                @if($recentSessions->isEmpty())
                    <p class="text-gray-500">Немає чатів</p>
                @else
                    <div class="space-y-3">
                        @foreach($recentSessions as $session)
                            <div class="p-4 bg-gray-50 rounded-lg">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="font-mono text-sm text-gray-600">{{ substr($session->session_id, 0, 16) }}...</span>
                                    <span class="text-sm text-gray-500">{{ $session->created_at->diffForHumans() }}</span>
                                </div>
                                @if($session->messages->first())
                                    <p class="text-gray-700 truncate">
                                        {{ $session->messages->first()->content }}
                                    </p>
                                @endif
                                <div class="mt-2 flex gap-2 text-xs text-gray-500">
                                    <span>{{ $session->messages_count ?? $session->messages()->count() }} повідомлень</span>
                                </div>
                            </div>
                        @endforeach
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

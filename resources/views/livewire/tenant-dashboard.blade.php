<div>

    <!-- Header -->
    <div class="mb-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $tenant->name }}</h1>
                <p class="text-gray-500">{{ $tenant->domain ?? $tenant->slug }}</p>
            </div>
            <div class="flex items-center gap-2">
                @if($stats['is_trial'])
                    <span class="px-3 py-1 bg-amber-100 text-amber-800 rounded-full text-sm font-medium">
                        🎁 Trial Pro: {{ $stats['days_left'] }} днів
                    </span>
                @else
                    <span class="px-3 py-1 bg-emerald-100 text-emerald-800 rounded-full text-sm font-medium">
                        {{ $stats['plan_label'] }}
                    </span>
                @endif
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    @if(session()->has('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 text-green-700 rounded-lg">
            {{ session('success') }}
        </div>
    @endif

    <!-- Trial Banner -->
    @if($stats['is_trial'])
        <div class="mb-6 p-4 bg-gradient-to-r from-amber-50 to-orange-50 border border-amber-200 rounded-xl">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="flex items-start gap-3">
                    <span class="text-3xl">🎁</span>
                    <div>
                        <h3 class="font-bold text-amber-900">Ваш Pro-тріал активний!</h3>
                        <p class="text-amber-700 text-sm mt-1">
                            Залишилось <strong>{{ $stats['days_left'] }} днів</strong> безкоштовного доступу до всіх Pro-функцій.
                        </p>
                    </div>
                </div>
                <a href="{{ route('billing.index') }}" 
                   class="inline-flex items-center px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white font-medium rounded-lg transition shadow-sm">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                    </svg>
                    Обрати план
                </a>
            </div>
        </div>
    @endif

    <!-- Stats Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm p-4 border border-gray-100">
            <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['products_count']) }}</div>
            <div class="text-sm text-gray-500">Товарів</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 border border-gray-100">
            <div class="text-2xl font-bold text-green-600">{{ number_format($stats['products_in_stock']) }}</div>
            <div class="text-sm text-gray-500">В наявності</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 border border-gray-100">
            <div class="text-2xl font-bold text-purple-600">{{ number_format($stats['categories_count']) }}</div>
            <div class="text-sm text-gray-500">Категорій</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 border border-gray-100">
            <div class="text-2xl font-bold text-amber-600">{{ number_format($stats['total_sessions']) }}</div>
            <div class="text-sm text-gray-500">Чатів</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 border border-gray-100">
            <div class="text-2xl font-bold text-cyan-600">{{ number_format($stats['messages_30d']) }}</div>
            <div class="text-sm text-gray-500">Повідомлень</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 border border-gray-100">
            <div class="text-sm text-gray-500">Остання синхр.</div>
            <div class="text-lg font-bold text-gray-600">
                {{ $stats['last_sync_at'] ? $stats['last_sync_at']->diffForHumans() : '—' }}
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="border-b border-gray-200">
            <nav class="flex -mb-px">
                <button wire:click="setTab('overview')"
                        class="px-6 py-3 text-sm font-medium border-b-2 transition
                            {{ $activeTab === 'overview' 
                                ? 'border-blue-500 text-blue-600' 
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                    Огляд
                </button>
                <button wire:click="setTab('chats')"
                        class="px-6 py-3 text-sm font-medium border-b-2 transition
                            {{ $activeTab === 'chats' 
                                ? 'border-blue-500 text-blue-600' 
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                    Чати
                </button>
                <button wire:click="setTab('widget')"
                        class="px-6 py-3 text-sm font-medium border-b-2 transition
                            {{ $activeTab === 'widget' 
                                ? 'border-blue-500 text-blue-600' 
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                    Віджет
                </button>
                <button wire:click="setTab('greetings')"
                        class="px-6 py-3 text-sm font-medium border-b-2 transition
                            {{ $activeTab === 'greetings' 
                                ? 'border-blue-500 text-blue-600' 
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                    Привітання
                </button>
                <button wire:click="setTab('prompts')"
                        class="px-6 py-3 text-sm font-medium border-b-2 transition
                            {{ $activeTab === 'prompts' 
                                ? 'border-blue-500 text-blue-600' 
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                    Промпти
                </button>
                <button wire:click="setTab('triggers')"
                        class="px-6 py-3 text-sm font-medium border-b-2 transition
                            {{ $activeTab === 'triggers' 
                                ? 'border-blue-500 text-blue-600' 
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                    Тригери
                </button>
                <button wire:click="setTab('analytics')"
                        class="px-6 py-3 text-sm font-medium border-b-2 transition
                            {{ $activeTab === 'analytics' 
                                ? 'border-blue-500 text-blue-600' 
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                    Аналітика
                </button>
                <button wire:click="setTab('settings')"
                        class="px-6 py-3 text-sm font-medium border-b-2 transition
                            {{ $activeTab === 'settings' 
                                ? 'border-blue-500 text-blue-600' 
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                    Налаштування
                </button>
            </nav>
        </div>

        <div class="p-6">
            <!-- Overview Tab -->
            @if($activeTab === 'overview')
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Activity Chart -->
                    <div class="lg:col-span-2">
                        <h3 class="font-semibold text-lg mb-4">Активність за 14 днів</h3>
                        <div class="h-64">
                            <canvas id="messages-chart" wire:ignore></canvas>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div>
                        <h3 class="font-semibold text-lg mb-4">Швидкі дії</h3>
                        <div class="space-y-3">
                            <a href="{{ route('admin.widget.settings') }}" 
                               class="flex items-center p-3 bg-gray-50 hover:bg-gray-100 rounded-lg transition">
                                <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mr-3">
                                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    </svg>
                                </div>
                                <span>Налаштування віджета</span>
                            </a>

                            <button wire:click="copyEmbedCode" 
                                    class="w-full flex items-center p-3 bg-gray-50 hover:bg-gray-100 rounded-lg transition text-left">
                                <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mr-3">
                                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                    </svg>
                                </div>
                                <span>Копіювати embed код</span>
                            </button>

                            <a href="{{ route('admin.prompts') }}" 
                               class="flex items-center p-3 bg-gray-50 hover:bg-gray-100 rounded-lg transition">
                                <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center mr-3">
                                    <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </div>
                                <span>Кастомні промпти</span>
                            </a>

                            <a href="{{ route('admin.greetings') }}" 
                               class="flex items-center p-3 bg-gray-50 hover:bg-gray-100 rounded-lg transition">
                                <div class="w-10 h-10 bg-amber-100 rounded-lg flex items-center justify-center mr-3">
                                    <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
                                    </svg>
                                </div>
                                <span>Привітання</span>
                            </a>

                            <a href="{{ route('billing.index') }}" 
                               class="flex items-center p-3 bg-gray-50 hover:bg-gray-100 rounded-lg transition">
                                <div class="w-10 h-10 bg-cyan-100 rounded-lg flex items-center justify-center mr-3">
                                    <svg class="w-5 h-5 text-cyan-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                                    </svg>
                                </div>
                                <span>Тарифи та оплата</span>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Conversion Funnel - FIRST -->
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-semibold text-lg">🔄 Воронка конверсії</h3>
                        @if(($funnelData['overall_rate'] ?? 0) > 0)
                            <span class="text-sm bg-green-100 text-green-700 px-3 py-1 rounded-full">
                                {{ $funnelData['overall_rate'] }}% загальна конверсія
                            </span>
                        @endif
                    </div>
                    
                    @if(!empty($funnelData['stages']) && collect($funnelData['stages'])->sum('count') > 0)
                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                            @foreach($funnelData['stages'] as $index => $stage)
                                <div class="bg-gradient-to-b from-gray-50 to-white border border-gray-200 rounded-xl p-4 text-center relative">
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

                <!-- Features Section - LAST -->
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-semibold text-lg">Ваші функції</h3>
                        @if($tenant->plan === 'starter')
                            <a href="{{ route('billing.index') }}" class="text-sm text-purple-600 hover:text-purple-700 font-medium">
                                Розблокувати всі →
                            </a>
                        @endif
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                        @foreach($features as $featureKey => $feature)
                            <x-feature-item 
                                :feature="$featureKey"
                                :meta="$feature"
                                :available="$feature['available']"
                                :upgrade-to="$feature['upgrade_to'] ?? 'pro'"
                                size="default"
                            />
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Chats Tab -->
            @if($activeTab === 'chats')
                <div>
                    <div class="flex flex-col md:flex-row gap-4 mb-6">
                        <div class="flex-1">
                            <input type="text" 
                                   wire:model.live.debounce.300ms="chatSearch"
                                   placeholder="Пошук по сесії або вмісту..."
                                   class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <select wire:model.live="chatStatus"
                                class="rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
                            <option value="">Всі статуси</option>
                            <option value="open">Відкриті</option>
                            <option value="closed">Закриті</option>
                            <option value="flagged">Позначені</option>
                        </select>
                    </div>

                    @if($chats->count() > 0)
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Сесія</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Останнє повідомлення</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Повідомлень</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Статус</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Дата</th>
                                        <th class="px-4 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($chats as $chat)
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <code class="text-xs bg-gray-100 px-2 py-1 rounded">
                                                    {{ Str::limit($chat->session_id, 20) }}
                                                </code>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="text-sm text-gray-600">
                                                    {{ Str::limit($chat->messages->first()?->content ?? '—', 50) }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <span class="text-sm font-medium">{{ $chat->messages_count }}</span>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <span class="px-2 py-1 text-xs rounded-full 
                                                    {{ $chat->status === 'open' ? 'bg-green-100 text-green-700' : '' }}
                                                    {{ $chat->status === 'closed' ? 'bg-gray-100 text-gray-700' : '' }}
                                                    {{ $chat->status === 'flagged' ? 'bg-red-100 text-red-700' : '' }}">
                                                    {{ $chat->status }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                                {{ $chat->created_at->format('d.m.Y H:i') }}
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-right">
                                                <a href="{{ route('admin.chats.show', ['sessionId' => $chat->session_id]) }}" 
                                                   class="text-blue-600 hover:text-blue-800 text-sm">
                                                    Переглянути →
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4">
                            {{ $chats->links() }}
                        </div>
                    @else
                        <div class="text-center py-12 text-gray-500">
                            <svg class="w-12 h-12 mx-auto mb-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                            </svg>
                            <p>Ще немає чатів</p>
                            <p class="text-sm mt-2">Чати з'являться після того, як відвідувачі почнуть користуватись віджетом</p>
                        </div>
                    @endif
                </div>
            @endif

            <!-- Widget Tab -->
            @if($activeTab === 'widget')
                <div>
                    <!-- Embed Code Section -->
                    <div class="mb-8">
                        <h3 class="font-semibold text-lg mb-4">📋 Код віджета</h3>
                        <p class="text-gray-600 mb-4">
                            Додайте цей код перед закриваючим тегом <code class="bg-gray-100 px-1 rounded">&lt;/body&gt;</code> на вашому сайті.
                        </p>
                        
                        <div class="relative max-w-2xl">
                            <pre class="bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto text-sm">{{ $embedCode }}</pre>
                            <button wire:click="copyEmbedCode"
                                    class="absolute top-2 right-2 px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded">
                                Копіювати
                            </button>
                        </div>
                    </div>

                    <!-- Widget Settings - Embedded -->
                    <div class="border-t border-gray-200 pt-6">
                        <h3 class="font-semibold text-lg mb-4">⚙️ Налаштування віджета</h3>
                        @livewire('admin.widget-settings', ['embedded' => true])
                    </div>
                </div>
            @endif

            <!-- Analytics Tab -->
            @if($activeTab === 'analytics')
                <div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                        <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-xl p-6">
                            <div class="text-4xl font-bold text-blue-700">{{ number_format($stats['messages_30d']) }}</div>
                            <div class="text-blue-600 mt-1">Повідомлень за 30 днів</div>
                        </div>
                        <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-xl p-6">
                            <div class="text-4xl font-bold text-green-700">{{ number_format($stats['sessions_30d']) }}</div>
                            <div class="text-green-600 mt-1">Сесій за 30 днів</div>
                        </div>
                        <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-xl p-6">
                            <div class="text-4xl font-bold text-purple-700">
                                {{ $stats['sessions_30d'] > 0 ? round($stats['messages_30d'] / $stats['sessions_30d'], 1) : 0 }}
                            </div>
                            <div class="text-purple-600 mt-1">Повідомлень на сесію</div>
                        </div>
                    </div>

                    <div class="p-4 bg-amber-50 border border-amber-200 rounded-lg">
                        <h4 class="font-medium text-amber-900 mb-2">📊 Розширена аналітика</h4>
                        <p class="text-amber-700 text-sm">
                            Детальні звіти про конверсії, популярні товари та поведінку користувачів доступні в 
                            <a href="{{ route('admin.analytics') }}" class="underline font-medium">розділі аналітики</a>.
                        </p>
                    </div>
                </div>
            @endif

            <!-- Settings Tab -->
            @if($activeTab === 'settings')
                <div class="max-w-2xl space-y-6">
                    <div class="bg-gray-50 rounded-lg p-6">
                        <h4 class="font-medium text-gray-900 mb-4">Основні налаштування</h4>
                        <dl class="space-y-3">
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Назва магазину</dt>
                                <dd class="font-medium">{{ $tenant->name }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Домен</dt>
                                <dd class="font-medium">{{ $tenant->domain ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Платформа</dt>
                                <dd class="font-medium">{{ ucfirst($tenant->platform ?? 'Не вказано') }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">План</dt>
                                <dd class="font-medium">{{ $stats['plan_label'] }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Ліміт повідомлень</dt>
                                <dd class="font-medium">{{ number_format($stats['messages_limit']) }} / місяць</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="flex gap-3">
                        <a href="{{ route('profile.edit') }}" 
                           class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg transition">
                            Редагувати профіль
                        </a>
                        <a href="{{ route('billing.index') }}" 
                           class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
                            Змінити план
                        </a>
                    </div>
                </div>
            @endif

            <!-- Greetings Tab -->
            @if($activeTab === 'greetings')
                <div>
                    @livewire('admin.greetings-manager', ['embedded' => true])
                </div>
            @endif

            <!-- Prompts Tab -->
            @if($activeTab === 'prompts')
                <div>
                    @livewire('admin.prompt-presets-manager', ['embedded' => true])
                </div>
            @endif

            <!-- Triggers Tab -->
            @if($activeTab === 'triggers')
                <div>
                    @livewire('admin.proactive-triggers-manager', ['embedded' => true])
                </div>
            @endif
        </div>
    </div>

    <!-- Embed code (hidden) -->
    <textarea id="embed-code" class="hidden">{{ $embedCode }}</textarea>

    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('livewire:initialized', () => {
            initChart();
            
            Livewire.on('copy-to-clipboard', ({ code }) => {
                navigator.clipboard.writeText(code).then(() => {
                    alert('Код скопійовано!');
                });
            });
        });

        function initChart() {
            const ctx = document.getElementById('messages-chart');
            if (!ctx) return;
            
            const chartData = @json($chartData);
            
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: Object.keys(chartData).map(d => {
                        const date = new Date(d);
                        return date.toLocaleDateString('uk-UA', { day: 'numeric', month: 'short' });
                    }),
                    datasets: [{
                        label: 'Повідомлень',
                        data: Object.values(chartData),
                        backgroundColor: 'rgba(59, 130, 246, 0.5)',
                        borderColor: 'rgb(59, 130, 246)',
                        borderWidth: 1,
                        borderRadius: 4,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1 } }
                    }
                }
            });
        }
    </script>
    @endpush
</div>

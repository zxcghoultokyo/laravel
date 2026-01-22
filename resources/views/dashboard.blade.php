<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Dashboard — {{ $tenant->name }}
            </h2>
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
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <!-- Success message -->
            @if(session('success'))
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <p class="text-green-800">{{ session('success') }}</p>
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
                                    Залишилось <strong>{{ $stats['days_left'] }} днів</strong> безкоштовного доступу до всіх Pro-функцій: 
                                    5000 повідомлень, розширена аналітика, кастомні промпти.
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <a href="{{ route('billing.index') }}" 
                               class="inline-flex items-center px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white font-medium rounded-lg transition shadow-sm">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                                </svg>
                                Обрати план
                            </a>
                        </div>
                    </div>
                    @if($stats['days_left'] <= 3)
                        <div class="mt-3 p-2 bg-red-100 border border-red-200 rounded-lg">
                            <p class="text-red-700 text-sm font-medium">
                                ⚠️ Тріал закінчується через {{ $stats['days_left'] }} {{ $stats['days_left'] == 1 ? 'день' : 'дні' }}! 
                                Оберіть план, щоб не втратити доступ.
                            </p>
                        </div>
                    @endif
                </div>
            @endif

            <!-- Trial Expired Banner -->
            @if($stats['is_trial_expired'] ?? false)
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">⏰</span>
                            <div>
                                <h3 class="font-bold text-red-900">Тріал завершено</h3>
                                <p class="text-red-700 text-sm mt-1">
                                    Ваш безкоштовний період закінчився. Оберіть план, щоб продовжити користуватись AI-асистентом.
                                </p>
                                <p class="text-red-600 text-xs mt-2 font-medium">
                                    ⚠️ Віджет на вашому сайті зараз не відображається для відвідувачів.
                                </p>
                            </div>
                        </div>
                        <a href="{{ route('billing.index') }}" 
                           class="inline-flex items-center px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition shadow-sm">
                            Обрати план →
                        </a>
                    </div>
                </div>
            @endif

            <!-- Suspended Account Banner -->
            @if($tenant->status === 'suspended')
                <div class="mb-6 p-4 bg-gray-100 border-2 border-gray-400 rounded-xl">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">🚫</span>
                            <div>
                                <h3 class="font-bold text-gray-900">Акаунт призупинено</h3>
                                <p class="text-gray-700 text-sm mt-1">
                                    Ваш акаунт тимчасово призупинено. Віджет не відображається на вашому сайті.
                                </p>
                                <p class="text-gray-600 text-xs mt-2">
                                    Зверніться до підтримки: <a href="mailto:support@aintento.com" class="underline">support@aintento.com</a>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <!-- No Subscription Banner (plan=trial but no trial_ends_at) -->
            @if($tenant->plan === 'trial' && !$tenant->trial_ends_at && $tenant->status === 'active')
                <div class="mb-6 p-4 bg-amber-50 border border-amber-300 rounded-xl">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">💳</span>
                            <div>
                                <h3 class="font-bold text-amber-900">Потрібна підписка</h3>
                                <p class="text-amber-700 text-sm mt-1">
                                    Для активації віджету необхідно обрати тарифний план.
                                </p>
                                <p class="text-amber-600 text-xs mt-2 font-medium">
                                    ⚠️ Віджет на вашому сайті зараз не відображається для відвідувачів.
                                </p>
                            </div>
                        </div>
                        <a href="{{ route('billing.index') }}" 
                           class="inline-flex items-center px-5 py-2.5 bg-amber-600 hover:bg-amber-700 text-white font-medium rounded-lg transition shadow-sm">
                            Обрати план →
                        </a>
                    </div>
                </div>
            @endif

            <!-- Paid plan not activated (starter/pro/enterprise but no plan_expires_at) -->
            @if(in_array($tenant->plan, ['starter', 'pro', 'enterprise']) && !$tenant->plan_expires_at)
                <div class="mb-6 p-4 bg-red-50 border border-red-300 rounded-xl">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">💳</span>
                            <div>
                                <h3 class="font-bold text-red-900">Підписка не оплачена</h3>
                                <p class="text-red-700 text-sm mt-1">
                                    Ваш план {{ ucfirst($tenant->plan) }} не активований. Оплатіть підписку для активації віджету.
                                </p>
                                <p class="text-red-600 text-xs mt-2 font-medium">
                                    ⚠️ Віджет на вашому сайті зараз не відображається для відвідувачів.
                                </p>
                            </div>
                        </div>
                        <a href="{{ route('billing.index') }}" 
                           class="inline-flex items-center px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition shadow-sm">
                            Оплатити →
                        </a>
                    </div>
                </div>
            @endif

            <!-- Paid subscription expired -->
            @if(in_array($tenant->plan, ['starter', 'pro', 'enterprise']) && $tenant->plan_expires_at && $tenant->plan_expires_at->isPast())
                <div class="mb-6 p-4 bg-red-50 border border-red-300 rounded-xl">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">⏰</span>
                            <div>
                                <h3 class="font-bold text-red-900">Підписка закінчилась</h3>
                                <p class="text-red-700 text-sm mt-1">
                                    Ваша підписка {{ ucfirst($tenant->plan) }} закінчилась {{ $tenant->plan_expires_at->format('d.m.Y') }}.
                                </p>
                                <p class="text-red-600 text-xs mt-2 font-medium">
                                    ⚠️ Віджет на вашому сайті зараз не відображається для відвідувачів.
                                </p>
                            </div>
                        </div>
                        <a href="{{ route('billing.index') }}" 
                           class="inline-flex items-center px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition shadow-sm">
                            Продовжити →
                        </a>
                    </div>
                </div>
            @endif

            <!-- Subscription expiring soon (within 7 days) -->
            @if(in_array($tenant->plan, ['starter', 'pro', 'enterprise']) && $tenant->plan_expires_at && $tenant->plan_expires_at->isFuture() && $tenant->plan_expires_at->diffInDays(now()) <= 7)
                <div class="mb-6 p-4 bg-amber-50 border border-amber-300 rounded-xl">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">⚠️</span>
                            <div>
                                <h3 class="font-bold text-amber-900">Підписка закінчується скоро</h3>
                                <p class="text-amber-700 text-sm mt-1">
                                    Ваша підписка {{ ucfirst($tenant->plan) }} діє до <strong>{{ $tenant->plan_expires_at->format('d.m.Y H:i') }}</strong>
                                    ({{ $tenant->plan_expires_at->diffForHumans() }}).
                                </p>
                            </div>
                        </div>
                        <a href="{{ route('billing.index') }}" 
                           class="inline-flex items-center px-5 py-2.5 bg-amber-600 hover:bg-amber-700 text-white font-medium rounded-lg transition shadow-sm">
                            Продовжити →
                        </a>
                    </div>
                </div>
            @endif

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                <!-- Usage -->
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-gray-500 text-sm">Повідомлень</span>
                        <span class="text-xs px-2 py-1 bg-gray-100 rounded">цей місяць</span>
                    </div>
                    <div class="flex items-end justify-between">
                        <span class="text-3xl font-bold">{{ number_format($stats['messages_used']) }}</span>
                        <span class="text-gray-400 text-sm">/ {{ number_format($stats['messages_limit']) }}</span>
                    </div>
                    <div class="mt-3 h-2 bg-gray-200 rounded-full overflow-hidden">
                        <div class="h-full {{ $stats['usage_percentage'] > 80 ? 'bg-red-500' : 'bg-blue-500' }}" 
                             style="width: {{ min($stats['usage_percentage'], 100) }}%"></div>
                    </div>
                </div>

                <!-- Sessions -->
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-gray-500 text-sm">Сесій</span>
                        <span class="text-xs px-2 py-1 bg-gray-100 rounded">30 днів</span>
                    </div>
                    <span class="text-3xl font-bold">{{ number_format($stats['sessions_30d']) }}</span>
                    <p class="text-sm text-gray-500 mt-1">всього: {{ number_format($stats['total_sessions']) }}</p>
                </div>

                <!-- Messages -->
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-gray-500 text-sm">Повідомлень</span>
                        <span class="text-xs px-2 py-1 bg-gray-100 rounded">30 днів</span>
                    </div>
                    <span class="text-3xl font-bold">{{ number_format($stats['messages_30d']) }}</span>
                    <p class="text-sm text-gray-500 mt-1">всього: {{ number_format($stats['total_messages']) }}</p>
                </div>

                <!-- Products -->
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-gray-500 text-sm">Товарів</span>
                    </div>
                    <span class="text-3xl font-bold">{{ number_format($stats['products_count']) }}</span>
                    <p class="text-sm text-gray-500 mt-1">в каталозі</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Chart -->
                <div class="lg:col-span-2 bg-white rounded-xl shadow-sm p-6">
                    <h3 class="font-semibold text-lg mb-4">Активність за 14 днів</h3>
                    <div class="h-64">
                        <canvas id="messages-chart"></canvas>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <h3 class="font-semibold text-lg mb-4">Швидкі дії</h3>
                    <div class="space-y-3">
                        <a href="{{ route('profile.edit') }}" 
                           class="flex items-center p-3 bg-gray-50 hover:bg-gray-100 rounded-lg transition">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                            </div>
                            <span>Налаштування віджета</span>
                        </a>

                        <button onclick="copyEmbedCode()" 
                                class="w-full flex items-center p-3 bg-gray-50 hover:bg-gray-100 rounded-lg transition text-left">
                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                            <span>Копіювати embed код</span>
                        </button>

                        <a href="{{ route('billing.index') }}" 
                           class="flex items-center p-3 bg-gray-50 hover:bg-gray-100 rounded-lg transition">
                            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                                </svg>
                            </div>
                            <span>Тарифи та оплата</span>
                        </a>
                    </div>

                    <!-- Embed code hidden -->
                    <textarea id="embed-code" class="hidden">{{ $embedCode }}</textarea>
                </div>
            </div>

            <!-- Features Section -->
            <div class="mt-6 bg-white rounded-xl shadow-sm p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-semibold text-lg">Ваші функції</h3>
                    @if($tenant->plan === 'starter' || $tenant->plan === 'trial')
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
                
                @if($tenant->plan === 'starter')
                    <div class="mt-4 p-3 bg-purple-50 border border-purple-100 rounded-lg">
                        <p class="text-purple-700 text-sm">
                            <span class="font-medium">💡 Порада:</span> 
                            Перейдіть на Pro щоб отримати розширену аналітику та кастомні промпти. 
                            <a href="{{ route('billing.index') }}" class="underline font-medium">Дізнатися більше</a>
                        </p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Chart
        const ctx = document.getElementById('messages-chart').getContext('2d');
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
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });

        // Copy embed code
        function copyEmbedCode() {
            const code = document.getElementById('embed-code').value;
            navigator.clipboard.writeText(code).then(() => {
                alert('Код скопійовано!');
            });
        }
    </script>
    @endpush
</x-app-layout>

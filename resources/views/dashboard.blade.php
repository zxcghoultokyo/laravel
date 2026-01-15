<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Dashboard — {{ $tenant->name }}
            </h2>
            @if($stats['is_trial'])
                <span class="px-3 py-1 bg-yellow-100 text-yellow-800 rounded-full text-sm">
                    Trial: {{ $stats['days_left'] }} днів
                </span>
            @else
                <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-sm">
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

                        <a href="#" 
                           class="flex items-center p-3 bg-gray-50 hover:bg-gray-100 rounded-lg transition">
                            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                                </svg>
                            </div>
                            <span>Переглянути чати</span>
                        </a>
                    </div>

                    <!-- Embed code hidden -->
                    <textarea id="embed-code" class="hidden">{{ $embedCode }}</textarea>
                </div>
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

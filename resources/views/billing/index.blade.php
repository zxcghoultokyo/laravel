<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Тарифи та оплата') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            {{-- Trial expired warning --}}
            @if(session('warning'))
                <div class="mb-4 bg-amber-100 border border-amber-400 text-amber-800 px-4 py-3 rounded-lg flex items-center">
                    <span class="text-xl mr-3">⏰</span>
                    <span>{{ session('warning') }}</span>
                </div>
            @endif

            {{-- Flash messages --}}
            @if(session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                    {{ session('success') }}
                </div>
            @endif

            @if($errors->any())
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                    <ul class="list-disc pl-5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Current subscription status --}}
            @if($currentSubscription)
                <div class="mb-8 bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold mb-4">Поточна підписка</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <p class="text-sm text-gray-500">План</p>
                                <p class="text-lg font-medium">{{ $plans[$currentSubscription->plan_id]['name'] ?? $currentSubscription->plan_id }}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Статус</p>
                                <p class="text-lg font-medium">
                                    @if($currentSubscription->onTrial())
                                        <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-sm">
                                            Пробний період ({{ $trialDaysLeft }} днів)
                                        </span>
                                    @elseif($currentSubscription->isActive())
                                        <span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-sm">Активна</span>
                                    @elseif($currentSubscription->isCancelled())
                                        <span class="px-2 py-1 bg-yellow-100 text-yellow-800 rounded-full text-sm">
                                            Скасована (до {{ $currentSubscription->ends_at?->format('d.m.Y') }})
                                        </span>
                                    @else
                                        <span class="px-2 py-1 bg-gray-100 text-gray-800 rounded-full text-sm">{{ $currentSubscription->status }}</span>
                                    @endif
                                </p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Наступне списання</p>
                                <p class="text-lg font-medium">{{ $currentSubscription->current_period_end?->format('d.m.Y') ?? '-' }}</p>
                            </div>
                        </div>

                        @if($currentSubscription->isActive() && !$currentSubscription->isCancelled())
                            <div class="mt-4 pt-4 border-t">
                                <form action="{{ route('billing.cancel') }}" method="POST" 
                                      onsubmit="return confirm('Ви впевнені, що хочете скасувати підписку?')">
                                    @csrf
                                    <button type="submit" class="text-red-600 hover:text-red-800 text-sm">
                                        Скасувати підписку
                                    </button>
                                </form>
                            </div>
                        @elseif($currentSubscription->onGracePeriod())
                            <div class="mt-4 pt-4 border-t">
                                <form action="{{ route('billing.resume') }}" method="POST">
                                    @csrf
                                    <button type="submit" class="text-blue-600 hover:text-blue-800 text-sm">
                                        Відновити підписку
                                    </button>
                                </form>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Plans --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                @foreach($plans as $planId => $plan)
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg {{ ($currentSubscription?->plan_id ?? '') === $planId ? 'ring-2 ring-blue-500' : '' }}">
                        <div class="p-6">
                            <h3 class="text-xl font-bold mb-2">{{ $plan['name'] }}</h3>
                            <p class="text-gray-600 mb-4">{{ $plan['description'] ?? '' }}</p>
                            
                            <div class="mb-4">
                                <span class="text-3xl font-bold">{{ number_format($plan['price']) }}</span>
                                <span class="text-gray-500">₴/міс</span>
                            </div>

                            <ul class="space-y-2 mb-6">
                                @php $limits = $plan['limits'] ?? []; @endphp
                                <li class="flex items-center">
                                    <svg class="w-4 h-4 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                    {{ number_format($limits['messages_per_month'] ?? 0) }} повідомлень/міс
                                </li>
                                <li class="flex items-center">
                                    <svg class="w-4 h-4 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                    @if($limits['products_limit'] ?? false)
                                        {{ number_format($limits['products_limit']) }} товарів
                                    @else
                                        Необмежено товарів
                                    @endif
                                </li>
                                @foreach(($limits['features'] ?? []) as $feature)
                                    <li class="flex items-center">
                                        <svg class="w-4 h-4 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                        </svg>
                                        {{ __("billing.features.{$feature}") }}
                                    </li>
                                @endforeach
                            </ul>

                            @if(($currentSubscription?->plan_id ?? '') === $planId)
                                <button disabled class="w-full bg-gray-300 text-gray-600 py-2 px-4 rounded cursor-not-allowed">
                                    Поточний план
                                </button>
                            @elseif($currentSubscription?->isActive())
                                <a href="{{ route('billing.checkout', $planId) }}" 
                                   class="block w-full bg-blue-600 hover:bg-blue-700 text-white text-center py-2 px-4 rounded">
                                    Змінити план
                                </a>
                            @else
                                <a href="{{ route('billing.checkout', $planId) }}" 
                                   class="block w-full bg-blue-600 hover:bg-blue-700 text-white text-center py-2 px-4 rounded">
                                    Обрати план
                                </a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Recent payments --}}
            @if($recentPayments->isNotEmpty())
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-semibold">Останні платежі</h3>
                            <a href="{{ route('billing.history') }}" class="text-blue-600 hover:text-blue-800 text-sm">
                                Вся історія →
                            </a>
                        </div>
                        
                        <table class="min-w-full">
                            <thead>
                                <tr class="border-b">
                                    <th class="text-left py-2">Дата</th>
                                    <th class="text-left py-2">Опис</th>
                                    <th class="text-left py-2">Сума</th>
                                    <th class="text-left py-2">Статус</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($recentPayments as $payment)
                                    <tr class="border-b">
                                        <td class="py-2">{{ $payment->created_at->format('d.m.Y') }}</td>
                                        <td class="py-2">{{ $payment->description ?? 'Оплата підписки' }}</td>
                                        <td class="py-2">{{ $payment->formatted_amount }}</td>
                                        <td class="py-2">
                                            @if($payment->isSuccessful())
                                                <span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs">Оплачено</span>
                                            @elseif($payment->isPending())
                                                <span class="px-2 py-1 bg-yellow-100 text-yellow-800 rounded-full text-xs">Очікує</span>
                                            @elseif($payment->isFailed())
                                                <span class="px-2 py-1 bg-red-100 text-red-800 rounded-full text-xs">Помилка</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>

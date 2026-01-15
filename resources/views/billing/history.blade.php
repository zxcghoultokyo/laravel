<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Історія платежів') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    @if($payments->isEmpty())
                        <p class="text-gray-500 text-center py-8">Платежів поки немає</p>
                    @else
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Дата</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Опис</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Сума</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Статус</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Провайдер</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Картка</th>
                                    <th class="px-6 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($payments as $payment)
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ $payment->created_at->format('d.m.Y H:i') }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900">
                                            {{ $payment->description ?? 'Оплата підписки' }}
                                            @if($payment->subscription)
                                                <span class="text-xs text-gray-500">({{ $payment->subscription->plan_id }})</span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ $payment->formatted_amount }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            @if($payment->isSuccessful())
                                                <span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs">Оплачено</span>
                                            @elseif($payment->isPending())
                                                <span class="px-2 py-1 bg-yellow-100 text-yellow-800 rounded-full text-xs">Очікує</span>
                                            @elseif($payment->isFailed())
                                                <span class="px-2 py-1 bg-red-100 text-red-800 rounded-full text-xs">Помилка</span>
                                            @elseif($payment->isRefunded())
                                                <span class="px-2 py-1 bg-gray-100 text-gray-800 rounded-full text-xs">Повернено</span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ ucfirst($payment->provider) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            @if($payment->card_mask)
                                                {{ $payment->card_mask }}
                                                @if($payment->card_type)
                                                    <span class="text-xs">({{ $payment->card_type }})</span>
                                                @endif
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                            @if($payment->isSuccessful())
                                                <a href="{{ route('billing.invoice', $payment) }}" 
                                                   class="text-blue-600 hover:text-blue-800" target="_blank">
                                                    Квитанція
                                                </a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        <div class="mt-4">
                            {{ $payments->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

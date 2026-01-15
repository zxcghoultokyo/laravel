<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Оформлення підписки') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            @if($errors->any())
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                    <ul class="list-disc pl-5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    {{-- Plan summary --}}
                    <div class="mb-6 pb-6 border-b">
                        <h3 class="text-xl font-bold mb-2">{{ $plan['name'] }}</h3>
                        <p class="text-gray-600 mb-4">{{ $plan['description'] ?? '' }}</p>
                        
                        <div class="bg-gray-50 p-4 rounded">
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">Вартість підписки</span>
                                <span class="text-2xl font-bold">{{ number_format($plan['price']) }} ₴/міс</span>
                            </div>
                        </div>
                    </div>

                    {{-- Features --}}
                    <div class="mb-6 pb-6 border-b">
                        <h4 class="font-semibold mb-3">Що входить:</h4>
                        <ul class="space-y-2">
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
                                    До {{ number_format($limits['products_limit']) }} товарів
                                @else
                                    Необмежена кількість товарів
                                @endif
                            </li>
                        </ul>
                    </div>

                    {{-- Payment form --}}
                    <form action="{{ route('billing.subscribe', $planId) }}" method="POST">
                        @csrf
                        
                        {{-- Provider selection (if multiple configured) --}}
                        <div class="mb-6">
                            <h4 class="font-semibold mb-3">Спосіб оплати</h4>
                            
                            <div class="space-y-2">
                                <label class="flex items-center p-3 border rounded cursor-pointer hover:bg-gray-50">
                                    <input type="radio" name="provider" value="wayforpay" checked class="mr-3">
                                    <div class="flex items-center">
                                        <span class="font-medium">WayForPay</span>
                                        <span class="text-sm text-gray-500 ml-2">Visa, Mastercard, Apple Pay, Google Pay</span>
                                    </div>
                                </label>
                                
                                <label class="flex items-center p-3 border rounded cursor-pointer hover:bg-gray-50">
                                    <input type="radio" name="provider" value="liqpay" class="mr-3">
                                    <div class="flex items-center">
                                        <span class="font-medium">LiqPay</span>
                                        <span class="text-sm text-gray-500 ml-2">Visa, Mastercard, ПриватБанк</span>
                                    </div>
                                </label>
                            </div>
                        </div>

                        {{-- Legal notice --}}
                        <div class="mb-6 text-sm text-gray-500">
                            <p>Натискаючи "Оплатити", ви погоджуєтесь з:</p>
                            <ul class="list-disc pl-5 mt-1">
                                <li><a href="#" class="text-blue-600 hover:underline">Умовами надання послуг</a></li>
                                <li><a href="#" class="text-blue-600 hover:underline">Політикою конфіденційності</a></li>
                            </ul>
                            <p class="mt-2">Підписка автоматично продовжується щомісяця. Ви можете скасувати її в будь-який момент.</p>
                        </div>

                        {{-- Submit --}}
                        <div class="flex items-center justify-between">
                            <a href="{{ route('billing.index') }}" class="text-gray-600 hover:text-gray-800">
                                ← Назад до тарифів
                            </a>
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-8 rounded">
                                Оплатити {{ number_format($plan['price']) }} ₴
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

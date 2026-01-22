<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Налаштування магазину
            </h2>
            <div class="flex items-center space-x-2 text-sm text-gray-500">
                @php
                    // Map internal steps (1,2,3,5) to display steps (1,2,3,4)
                    $displayStep = $currentStep == 5 ? 4 : $currentStep;
                    $displayTotalSteps = 4;
                @endphp
                <span>Крок {{ $displayStep }} з {{ $displayTotalSteps }}</span>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <!-- Progress bar (4 steps: Platform, Connection, Sync, Done) -->
            @php
                // Map internal steps to display: 1->1, 2->2, 3->3, 5->4
                $displayStep = $currentStep == 5 ? 4 : $currentStep;
                $displayTotalSteps = 4;
                $stepLabels = ['Платформа', 'Підключення', 'Синхронізація', 'Готово'];
            @endphp
            <div class="mb-8">
                <div class="flex items-center justify-between mb-2">
                    @for ($i = 1; $i <= $displayTotalSteps; $i++)
                        <div class="flex items-center">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-medium
                                {{ $i < $displayStep ? 'bg-green-500 text-white' : '' }}
                                {{ $i === $displayStep ? 'bg-blue-600 text-white' : '' }}
                                {{ $i > $displayStep ? 'bg-gray-200 text-gray-600' : '' }}">
                                @if ($i < $displayStep)
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                @else
                                    {{ $i }}
                                @endif
                            </div>
                            @if ($i < $displayTotalSteps)
                                <div class="w-12 sm:w-24 h-1 mx-2 {{ $i < $displayStep ? 'bg-green-500' : 'bg-gray-200' }}"></div>
                            @endif
                        </div>
                    @endfor
                </div>
                <div class="flex justify-between text-xs text-gray-500">
                    @foreach ($stepLabels as $label)
                        <span>{{ $label }}</span>
                    @endforeach
                </div>
            </div>

            <!-- Current step content -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    @switch($currentStep)
                        @case(1)
                            @include('onboarding.partials.step1')
                            @break
                        @case(2)
                            @include('onboarding.partials.step2')
                            @break
                        @case(3)
                            @include('onboarding.partials.step3')
                            @break
                        @case(4)
                            @include('onboarding.partials.step4')
                            @break
                        @case(5)
                            @include('onboarding.partials.step5')
                            @break
                    @endswitch
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

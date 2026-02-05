<div 
    @if($pollingInterval)
        wire:poll.{{ $pollingInterval }}ms
    @endif
    class="{{ $isCompact ? 'p-4' : 'p-6' }} bg-white rounded-xl shadow-sm"
>
    @if($progress)
        {{-- Header with overall progress --}}
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center space-x-3">
                @if($progress['status'] === 'completed')
                    <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center">
                        <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                @elseif($progress['status'] === 'failed')
                    <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center">
                        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </div>
                @elseif($progress['status'] === 'in_progress')
                    <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center">
                        <svg class="w-6 h-6 text-blue-600 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                @elseif($progress['status'] === 'pending')
                    <div class="w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center">
                        <svg class="w-6 h-6 text-amber-600 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                @else
                    <div class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center">
                        <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                @endif
                <div>
                    <h3 class="font-semibold text-gray-900">
                        @if($progress['status'] === 'completed')
                            @if($aiInProgress ?? false)
                                Онбординг успішно завершено!
                            @else
                                Налаштування завершено!
                            @endif
                        @elseif($progress['status'] === 'failed')
                            Помилка налаштування
                        @elseif($progress['status'] === 'in_progress')
                            Налаштування магазину...
                        @elseif($progress['status'] === 'pending')
                            Запускаємо синхронізацію...
                        @else
                            Очікує запуску
                        @endif
                    </h3>
                    @if($aiInProgress ?? false)
                        <p class="text-sm text-amber-600 flex items-center">
                            <svg class="w-4 h-4 mr-1 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            AI збагачення ще виконується у фоні...
                        </p>
                    @elseif($progress['current_step_detail'])
                        <p class="text-sm text-gray-500">{{ $progress['current_step_detail'] }}</p>
                    @endif
                </div>
            </div>
            <div class="text-right">
                <span class="text-2xl font-bold {{ $progress['status'] === 'completed' ? 'text-green-600' : ($progress['status'] === 'failed' ? 'text-red-600' : 'text-blue-600') }}">
                    {{ $progress['overall_percent'] }}%
                </span>
            </div>
        </div>

        {{-- Overall progress bar --}}
        <div class="w-full bg-gray-200 rounded-full h-3 mb-4">
            <div class="h-3 rounded-full transition-all duration-500 ease-out
                {{ $progress['status'] === 'completed' ? 'bg-green-500' : ($progress['status'] === 'failed' ? 'bg-red-500' : 'bg-blue-500') }}"
                style="width: {{ $progress['overall_percent'] }}%">
            </div>
        </div>

        @if(!$isCompact)
            {{-- Detailed steps --}}
            <div class="space-y-3 mt-6">
                @foreach($progress['steps'] as $step)
                    <div class="flex items-center space-x-3">
                        {{-- Step status icon --}}
                        <div class="flex-shrink-0">
                            @if($step['status'] === 'completed')
                                <div class="w-6 h-6 rounded-full bg-green-100 flex items-center justify-center">
                                    <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                </div>
                            @elseif($step['status'] === 'in_progress')
                                <div class="w-6 h-6 rounded-full bg-blue-100 flex items-center justify-center">
                                    <svg class="w-4 h-4 text-blue-600 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                </div>
                            @elseif($step['status'] === 'failed')
                                <div class="w-6 h-6 rounded-full bg-red-100 flex items-center justify-center">
                                    <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </div>
                            @else
                                <div class="w-6 h-6 rounded-full bg-gray-100 flex items-center justify-center">
                                    <div class="w-2 h-2 rounded-full bg-gray-400"></div>
                                </div>
                            @endif
                        </div>

                        {{-- Step info --}}
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-medium {{ $step['status'] === 'completed' ? 'text-green-700' : ($step['status'] === 'in_progress' ? 'text-blue-700' : 'text-gray-600') }}">
                                    {{ $step['name'] }}
                                </p>
                                @if($step['percent'] > 0 && $step['status'] !== 'completed')
                                    <span class="text-xs text-gray-500">{{ $step['percent'] }}%</span>
                                @endif
                            </div>
                            @if($step['detail'] && $step['status'] !== 'pending')
                                <p class="text-xs text-gray-500 truncate">{{ $step['detail'] }}</p>
                            @endif
                            @if(!empty($step['stats']))
                                <div class="flex items-center space-x-3 mt-1">
                                    @foreach($step['stats'] as $key => $value)
                                        @if(is_numeric($value))
                                            <span class="text-xs text-gray-400">
                                                {{ str_replace('_', ' ', ucfirst($key)) }}: {{ number_format($value) }}
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Error message --}}
            @if($progress['error_message'])
                <div class="mt-4 p-3 bg-red-50 border border-red-200 rounded-lg">
                    <p class="text-sm text-red-700">{{ $progress['error_message'] }}</p>
                    <button 
                        wire:click="startOnboarding"
                        class="mt-2 text-sm text-red-600 hover:text-red-800 underline"
                    >
                        Спробувати знову
                    </button>
                </div>
            @endif
        @endif

    @elseif($showStartButton)
        {{-- No progress yet - show start button --}}
        <div class="text-center py-6">
            <div class="w-16 h-16 mx-auto mb-4 bg-blue-100 rounded-full flex items-center justify-center">
                <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                </svg>
            </div>
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Запустити налаштування</h3>
            <p class="text-sm text-gray-500 mb-4">
                Синхронізація товарів, AI-аналіз та налаштування пошуку
            </p>
            <button 
                wire:click="startOnboarding"
                wire:loading.attr="disabled"
                class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center"
            >
                <span wire:loading.remove wire:target="startOnboarding">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    Почати налаштування
                </span>
                <span wire:loading wire:target="startOnboarding" class="flex items-center">
                    <svg class="animate-spin w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    Запускаємо...
                </span>
            </button>
        </div>
    @else
        {{-- Onboarding already complete or products imported --}}
        <div class="text-center py-4">
            <div class="w-12 h-12 mx-auto mb-3 bg-green-100 rounded-full flex items-center justify-center">
                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <p class="text-sm text-gray-600">Магазин налаштовано</p>
        </div>
    @endif

    {{-- Flash messages --}}
    @if (session()->has('message'))
        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
            <p class="text-sm text-blue-700">{{ session('message') }}</p>
        </div>
    @endif
</div>

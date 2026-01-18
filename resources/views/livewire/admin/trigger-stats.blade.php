<div class="p-4 sm:p-6 max-w-7xl mx-auto">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 mb-6">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-gray-900">📊 Статистика тригерів</h1>
            <p class="text-gray-600 mt-1 text-sm">Воронка конверсій та ефективність правил</p>
        </div>
        @if(!$embedded)
        <a href="{{ route('admin.triggers') }}" 
           class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-800 text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            Налаштування
        </a>
        @endif
    </div>

    {{-- Filters --}}
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <div class="grid grid-cols-2 sm:flex sm:flex-wrap gap-3 items-end">
            {{-- Period --}}
            <div class="col-span-1">
                <label class="block text-xs font-medium text-gray-700 mb-1">Період</label>
                <select wire:model.live="period" class="w-full rounded-lg border-gray-300 text-sm py-2">
                    <option value="today">Сьогодні</option>
                    <option value="7d">7 днів</option>
                    <option value="30d">30 днів</option>
                    <option value="custom">Власний</option>
                </select>
            </div>

            {{-- Custom date range --}}
            @if($period === 'custom')
            <div class="col-span-1">
                <label class="block text-xs font-medium text-gray-700 mb-1">Від</label>
                <input type="date" wire:model.live="dateFrom" class="w-full rounded-lg border-gray-300 text-sm py-2">
            </div>
            <div class="col-span-1">
                <label class="block text-xs font-medium text-gray-700 mb-1">До</label>
                <input type="date" wire:model.live="dateTo" class="w-full rounded-lg border-gray-300 text-sm py-2">
            </div>
            @endif

            {{-- Trigger type --}}
            <div class="col-span-1">
                <label class="block text-xs font-medium text-gray-700 mb-1">Тип тригера</label>
                <select wire:model.live="triggerType" class="w-full rounded-lg border-gray-300 text-sm py-2">
                    <option value="">Всі типи</option>
                    @foreach($triggerTypes as $type => $label)
                        <option value="{{ $type }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Specific rule --}}
            <div class="col-span-2 sm:col-span-1">
                <label class="block text-xs font-medium text-gray-700 mb-1">Правило</label>
                <select wire:model.live="ruleId" class="w-full rounded-lg border-gray-300 text-sm py-2">
                    <option value="">Всі правила</option>
                    @foreach($rules as $rule)
                        <option value="{{ $rule->id }}">{{ $rule->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="mt-2 text-xs text-gray-500">
            📅 {{ $dateRange['label'] }}
        </div>
    </div>

    {{-- Funnel Visualization --}}
    <div class="bg-white rounded-lg shadow p-4 sm:p-6 mb-6">
        <h2 class="text-base sm:text-lg font-semibold text-gray-900 mb-4 sm:mb-6">🎯 Воронка конверсій</h2>
        
        {{-- Mobile Funnel (vertical) --}}
        <div class="sm:hidden space-y-3">
            @foreach($funnelData as $key => $step)
                <div class="relative">
                    {{-- Step card --}}
                    <div class="flex items-center gap-3 p-3 rounded-xl border-2
                        @if($step['color'] === 'blue') bg-blue-50 border-blue-200 @endif
                        @if($step['color'] === 'indigo') bg-indigo-50 border-indigo-200 @endif
                        @if($step['color'] === 'purple') bg-purple-50 border-purple-200 @endif
                        @if($step['color'] === 'orange') bg-orange-50 border-orange-200 @endif
                        @if($step['color'] === 'green') bg-green-50 border-green-200 @endif
                    ">
                        {{-- Icon --}}
                        <div class="text-2xl">{{ $step['icon'] }}</div>
                        
                        {{-- Info --}}
                        <div class="flex-1">
                            <div class="text-xl font-bold
                                @if($step['color'] === 'blue') text-blue-700 @endif
                                @if($step['color'] === 'indigo') text-indigo-700 @endif
                                @if($step['color'] === 'purple') text-purple-700 @endif
                                @if($step['color'] === 'orange') text-orange-700 @endif
                                @if($step['color'] === 'green') text-green-700 @endif
                            ">
                                {{ number_format($step['count']) }}
                            </div>
                            <div class="text-xs font-medium
                                @if($step['color'] === 'blue') text-blue-600 @endif
                                @if($step['color'] === 'indigo') text-indigo-600 @endif
                                @if($step['color'] === 'purple') text-purple-600 @endif
                                @if($step['color'] === 'orange') text-orange-600 @endif
                                @if($step['color'] === 'green') text-green-600 @endif
                            ">
                                {{ $step['label'] }}
                            </div>
                        </div>
                        
                        {{-- Rate badge --}}
                        @if($key !== 'shown')
                        <div class="px-2 py-1 rounded-full text-xs font-bold text-white
                            @if($step['color'] === 'indigo') bg-indigo-500 @endif
                            @if($step['color'] === 'purple') bg-purple-500 @endif
                            @if($step['color'] === 'orange') bg-orange-500 @endif
                            @if($step['color'] === 'green') bg-green-500 @endif
                        ">
                            {{ $step['rate'] }}%
                        </div>
                        @endif
                    </div>
                    
                    {{-- Arrow down --}}
                    @if($key !== 'purchased')
                    <div class="flex justify-center py-1 text-gray-300">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                        </svg>
                    </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Desktop Funnel (horizontal) --}}
        <div class="hidden sm:block">
            <div class="flex items-stretch justify-between gap-2 lg:gap-4">
                @foreach($funnelData as $key => $step)
                    <div class="flex-1 flex items-center">
                        {{-- Step card --}}
                        <div class="relative w-full p-3 lg:p-4 rounded-xl border-2 text-center
                            @if($step['color'] === 'blue') bg-blue-50 border-blue-200 @endif
                            @if($step['color'] === 'indigo') bg-indigo-50 border-indigo-200 @endif
                            @if($step['color'] === 'purple') bg-purple-50 border-purple-200 @endif
                            @if($step['color'] === 'orange') bg-orange-50 border-orange-200 @endif
                            @if($step['color'] === 'green') bg-green-50 border-green-200 @endif
                        ">
                            {{-- Icon --}}
                            <div class="text-2xl lg:text-3xl mb-1 lg:mb-2">{{ $step['icon'] }}</div>
                            
                            {{-- Count --}}
                            <div class="text-lg lg:text-2xl font-bold
                                @if($step['color'] === 'blue') text-blue-700 @endif
                                @if($step['color'] === 'indigo') text-indigo-700 @endif
                                @if($step['color'] === 'purple') text-purple-700 @endif
                                @if($step['color'] === 'orange') text-orange-700 @endif
                                @if($step['color'] === 'green') text-green-700 @endif
                            ">
                                {{ number_format($step['count']) }}
                            </div>
                            
                            {{-- Label --}}
                            <div class="text-xs lg:text-sm font-medium
                                @if($step['color'] === 'blue') text-blue-600 @endif
                                @if($step['color'] === 'indigo') text-indigo-600 @endif
                                @if($step['color'] === 'purple') text-purple-600 @endif
                                @if($step['color'] === 'orange') text-orange-600 @endif
                                @if($step['color'] === 'green') text-green-600 @endif
                            ">
                                {{ $step['label'] }}
                            </div>
                            
                            {{-- Rate badge --}}
                            @if($key !== 'shown')
                            <div class="absolute -top-2 -right-2 px-2 py-0.5 rounded-full text-xs font-bold text-white
                                @if($step['color'] === 'indigo') bg-indigo-500 @endif
                                @if($step['color'] === 'purple') bg-purple-500 @endif
                                @if($step['color'] === 'orange') bg-orange-500 @endif
                                @if($step['color'] === 'green') bg-green-500 @endif
                            ">
                                {{ $step['rate'] }}%
                            </div>
                            @endif
                        </div>
                        
                        {{-- Arrow right --}}
                        @if($key !== 'purchased')
                        <div class="px-1 lg:px-2 text-gray-300 flex-shrink-0">
                            <svg class="w-4 h-4 lg:w-6 lg:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Overall stats --}}
        <div class="mt-4 pt-4 border-t border-gray-200">
            <div class="flex flex-col sm:flex-row sm:justify-between gap-2">
                <div class="text-sm">
                    <span class="text-gray-500">Загальна конверсія:</span>
                    <span class="ml-1 font-bold {{ $funnelData['shown']['count'] > 0 && $funnelData['purchased']['count'] > 0 ? 'text-green-600' : 'text-gray-400' }}">
                        {{ $funnelData['shown']['count'] > 0 ? round(($funnelData['purchased']['count'] / $funnelData['shown']['count']) * 100, 2) : 0 }}%
                    </span>
                </div>
                <div class="text-sm">
                    <span class="text-gray-500">CTR:</span>
                    <span class="ml-1 font-bold {{ $funnelData['clicked']['rate'] >= 5 ? 'text-green-600' : ($funnelData['clicked']['rate'] >= 2 ? 'text-yellow-600' : 'text-gray-400') }}">
                        {{ $funnelData['clicked']['rate'] }}%
                    </span>
                </div>
            </div>
        </div>
    </div>

    {{-- Chart and Top Rules --}}
    <div class="grid gap-4 lg:gap-6 lg:grid-cols-2 mb-6">
        {{-- Trend Chart --}}
        <div class="bg-white rounded-lg shadow p-4 sm:p-6">
            <h2 class="text-base sm:text-lg font-semibold text-gray-900 mb-4">📈 Динаміка</h2>
            
            @if(count($trendData) > 0 && max(array_column($trendData, 'shown')) > 0)
            <div class="h-48 sm:h-64">
                <div class="flex items-end justify-between h-36 sm:h-48 gap-0.5 sm:gap-1 border-b border-gray-200 pb-2">
                    @php $maxValue = max(array_column($trendData, 'shown')) ?: 1; @endphp
                    @foreach($trendData as $index => $day)
                        <div class="flex-1 flex flex-col items-center">
                            <div class="w-full flex gap-px items-end h-32 sm:h-40">
                                <div class="flex-1 bg-blue-400 rounded-t transition-all" 
                                     style="height: {{ $day['shown'] > 0 ? max(($day['shown'] / $maxValue) * 100, 3) : 0 }}%"
                                     title="Показано: {{ $day['shown'] }}"></div>
                                <div class="flex-1 bg-indigo-500 rounded-t transition-all" 
                                     style="height: {{ $day['clicked'] > 0 ? max(($day['clicked'] / $maxValue) * 100, 3) : 0 }}%"
                                     title="Клікнуто: {{ $day['clicked'] }}"></div>
                                <div class="flex-1 bg-green-500 rounded-t transition-all" 
                                     style="height: {{ $day['converted'] > 0 ? max(($day['converted'] / $maxValue) * 100, 3) : 0 }}%"
                                     title="В кошик: {{ $day['converted'] }}"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
                {{-- X axis --}}
                <div class="flex justify-between mt-1 text-[10px] sm:text-xs text-gray-400 overflow-hidden">
                    @foreach($trendData as $index => $day)
                        @if($index === 0 || $index === count($trendData) - 1 || $index % max(1, intval(count($trendData) / 5)) === 0)
                            <span class="truncate">{{ $day['date'] }}</span>
                        @endif
                    @endforeach
                </div>
                {{-- Legend --}}
                <div class="flex justify-center gap-3 sm:gap-4 mt-3 text-xs">
                    <div class="flex items-center gap-1">
                        <div class="w-2 h-2 sm:w-3 sm:h-3 bg-blue-400 rounded"></div>
                        <span class="text-gray-600">Показано</span>
                    </div>
                    <div class="flex items-center gap-1">
                        <div class="w-2 h-2 sm:w-3 sm:h-3 bg-indigo-500 rounded"></div>
                        <span class="text-gray-600">Клік</span>
                    </div>
                    <div class="flex items-center gap-1">
                        <div class="w-2 h-2 sm:w-3 sm:h-3 bg-green-500 rounded"></div>
                        <span class="text-gray-600">Кошик</span>
                    </div>
                </div>
            </div>
            @else
            <div class="h-48 sm:h-64 flex items-center justify-center text-gray-400 text-sm">
                Немає даних
            </div>
            @endif
        </div>

        {{-- Top Rules by CTR --}}
        <div class="bg-white rounded-lg shadow p-4 sm:p-6">
            <h2 class="text-base sm:text-lg font-semibold text-gray-900 mb-4">🏆 Топ правил</h2>
            
            @if($topRules->count() > 0)
            <div class="space-y-2 sm:space-y-3">
                @foreach($topRules as $index => $rule)
                    <div class="flex items-center gap-2 sm:gap-3 p-2 sm:p-3 rounded-lg {{ $index === 0 ? 'bg-yellow-50 border border-yellow-200' : 'bg-gray-50' }}">
                        {{-- Rank --}}
                        <div class="w-6 h-6 sm:w-8 sm:h-8 rounded-full flex items-center justify-center font-bold text-xs sm:text-sm flex-shrink-0
                            {{ $index === 0 ? 'bg-yellow-400 text-yellow-900' : '' }}
                            {{ $index === 1 ? 'bg-gray-300 text-gray-700' : '' }}
                            {{ $index === 2 ? 'bg-orange-300 text-orange-900' : '' }}
                            {{ $index > 2 ? 'bg-gray-200 text-gray-600' : '' }}
                        ">
                            {{ $index + 1 }}
                        </div>
                        
                        {{-- Info --}}
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-gray-900 truncate text-sm">{{ $rule['name'] }}</div>
                            <div class="text-[10px] sm:text-xs text-gray-500 truncate">
                                {{ $triggerTypes[$rule['type']] ?? $rule['type'] }} • {{ $rule['shown'] }} показів
                            </div>
                        </div>
                        
                        {{-- CTR --}}
                        <div class="text-right flex-shrink-0">
                            <div class="text-base sm:text-lg font-bold {{ $rule['ctr'] >= 5 ? 'text-green-600' : ($rule['ctr'] >= 2 ? 'text-yellow-600' : 'text-gray-500') }}">
                                {{ $rule['ctr'] }}%
                            </div>
                            <div class="text-[10px] text-gray-400">CTR</div>
                        </div>
                    </div>
                @endforeach
            </div>
            @else
            <div class="h-40 flex items-center justify-center text-gray-400 text-sm">
                Немає даних
            </div>
            @endif
        </div>
    </div>

    {{-- Detailed Rules Table --}}
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-4 py-3 sm:px-6 sm:py-4 border-b border-gray-200">
            <h2 class="text-base sm:text-lg font-semibold text-gray-900">📋 Детальна статистика</h2>
        </div>
        
        {{-- Mobile Cards --}}
        <div class="sm:hidden divide-y divide-gray-100">
            @forelse($rulesStats as $rule)
                <div class="p-4 {{ !$rule['is_enabled'] ? 'opacity-50' : '' }}">
                    <div class="flex items-start justify-between mb-2">
                        <div class="flex-1 min-w-0">
                            <a href="{{ route('admin.triggers') }}?edit={{ $rule['id'] }}" class="text-blue-600 hover:text-blue-800 font-medium text-sm truncate block">
                                {{ $rule['name'] }}
                            </a>
                            <div class="text-xs text-gray-500">
                                {{ $triggerTypes[$rule['type']] ?? $rule['type'] }}
                            </div>
                        </div>
                        @if($rule['is_enabled'])
                            <span class="px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">ON</span>
                        @else
                            <span class="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">OFF</span>
                        @endif
                    </div>
                    <div class="grid grid-cols-4 gap-2 text-center">
                        <div>
                            <div class="text-xs text-gray-400">👁</div>
                            <div class="font-medium text-sm">{{ number_format($rule['shown']) }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-400">👆</div>
                            <div class="font-medium text-sm">{{ number_format($rule['clicked']) }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-400">🛒</div>
                            <div class="font-medium text-sm">{{ number_format($rule['converted']) }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-400">CTR</div>
                            <div class="font-bold text-sm {{ $rule['ctr'] >= 5 ? 'text-green-600' : ($rule['ctr'] >= 2 ? 'text-yellow-600' : 'text-gray-500') }}">
                                {{ $rule['ctr'] }}%
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="p-8 text-center text-gray-400 text-sm">
                    Немає даних
                </div>
            @endforelse
        </div>

        {{-- Desktop Table --}}
        <div class="hidden sm:block overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Правило</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Тип</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Статус</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">👁️</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">👆</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">🛒</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">✅</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">CTR</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($rulesStats as $rule)
                        <tr class="hover:bg-gray-50 {{ !$rule['is_enabled'] ? 'opacity-50' : '' }}">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <a href="{{ route('admin.triggers') }}?edit={{ $rule['id'] }}" class="text-blue-600 hover:text-blue-800 font-medium">
                                    {{ $rule['name'] }}
                                </a>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                {{ $triggerTypes[$rule['type']] ?? $rule['type'] }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-center">
                                @if($rule['is_enabled'])
                                    <span class="px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">ON</span>
                                @else
                                    <span class="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">OFF</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-right text-sm font-medium">{{ number_format($rule['shown']) }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-right text-sm">{{ number_format($rule['clicked']) }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-right text-sm">{{ number_format($rule['converted']) }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-right text-sm">{{ number_format($rule['purchased']) }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-right">
                                <span class="font-bold {{ $rule['ctr'] >= 5 ? 'text-green-600' : ($rule['ctr'] >= 2 ? 'text-yellow-600' : 'text-gray-500') }}">
                                    {{ $rule['ctr'] }}%
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-gray-400">Немає даних</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Info --}}
    <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-3 sm:p-4">
        <div class="flex items-start gap-2 sm:gap-3">
            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-blue-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div class="text-xs sm:text-sm text-blue-800">
                <p class="font-medium mb-1">Воронка:</p>
                <p class="text-blue-700">👁 Показано → 👆 Клік → 🔍 Товар → 🛒 Кошик → ✅ Замовлення</p>
            </div>
        </div>
    </div>
</div>

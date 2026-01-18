<div class="p-4 sm:p-6 max-w-7xl mx-auto">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 mb-6">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-gray-900">📊 Статистика тригерів</h1>
            <p class="text-gray-600 mt-1 text-sm sm:text-base">Воронка конверсій та ефективність правил</p>
        </div>
        <a href="{{ route('admin.triggers') }}" 
           class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-800">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            Налаштування тригерів
        </a>
    </div>

    {{-- Filters --}}
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <div class="flex flex-wrap gap-4 items-end">
            {{-- Period --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Період</label>
                <select wire:model.live="period" class="rounded-lg border-gray-300 text-sm">
                    <option value="today">Сьогодні</option>
                    <option value="7d">7 днів</option>
                    <option value="30d">30 днів</option>
                    <option value="custom">Власний</option>
                </select>
            </div>

            {{-- Custom date range --}}
            @if($period === 'custom')
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Від</label>
                <input type="date" wire:model.live="dateFrom" class="rounded-lg border-gray-300 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">До</label>
                <input type="date" wire:model.live="dateTo" class="rounded-lg border-gray-300 text-sm">
            </div>
            @endif

            {{-- Trigger type --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Тип тригера</label>
                <select wire:model.live="triggerType" class="rounded-lg border-gray-300 text-sm">
                    <option value="">Всі типи</option>
                    @foreach($triggerTypes as $type => $label)
                        <option value="{{ $type }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Specific rule --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Правило</label>
                <select wire:model.live="ruleId" class="rounded-lg border-gray-300 text-sm">
                    <option value="">Всі правила</option>
                    @foreach($rules as $rule)
                        <option value="{{ $rule->id }}">{{ $rule->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="mt-2 text-sm text-gray-500">
            📅 {{ $dateRange['label'] }}
        </div>
    </div>

    {{-- Funnel Visualization --}}
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-6">🎯 Воронка конверсій</h2>
        
        <div class="relative">
            {{-- Funnel steps --}}
            <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                @php $maxCount = max($funnelData['shown']['count'], 1); @endphp
                
                @foreach($funnelData as $key => $step)
                    <div class="flex-1 w-full md:w-auto">
                        {{-- Step card --}}
                        <div class="relative bg-{{ $step['color'] }}-50 border-2 border-{{ $step['color'] }}-200 rounded-xl p-4 text-center
                            {{ $key === 'shown' ? 'md:scale-100' : '' }}
                            {{ $key === 'clicked' ? 'md:scale-95' : '' }}
                            {{ $key === 'product_viewed' ? 'md:scale-90' : '' }}
                            {{ $key === 'added_to_cart' ? 'md:scale-85' : '' }}
                            {{ $key === 'purchased' ? 'md:scale-80' : '' }}
                        ">
                            {{-- Icon --}}
                            <div class="text-3xl mb-2">{{ $step['icon'] }}</div>
                            
                            {{-- Count --}}
                            <div class="text-2xl font-bold text-{{ $step['color'] }}-700">
                                {{ number_format($step['count']) }}
                            </div>
                            
                            {{-- Label --}}
                            <div class="text-sm text-{{ $step['color'] }}-600 font-medium">
                                {{ $step['label'] }}
                            </div>
                            
                            {{-- Rate badge --}}
                            @if($key !== 'shown')
                            <div class="absolute -top-2 -right-2 bg-{{ $step['color'] }}-500 text-white text-xs font-bold px-2 py-1 rounded-full">
                                {{ $step['rate'] }}%
                            </div>
                            @endif
                        </div>
                        
                        {{-- Arrow (hidden on last item) --}}
                        @if($key !== 'purchased')
                        <div class="hidden md:flex justify-center my-2 text-gray-400">
                            <svg class="w-6 h-6 transform rotate-90 md:rotate-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                            </svg>
                        </div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Overall conversion rate --}}
            <div class="mt-6 pt-6 border-t border-gray-200">
                <div class="flex justify-between items-center">
                    <div>
                        <span class="text-gray-500">Загальна конверсія (показ → замовлення):</span>
                        <span class="ml-2 text-2xl font-bold {{ $funnelData['shown']['count'] > 0 && $funnelData['purchased']['count'] > 0 ? 'text-green-600' : 'text-gray-400' }}">
                            {{ $funnelData['shown']['count'] > 0 ? round(($funnelData['purchased']['count'] / $funnelData['shown']['count']) * 100, 2) : 0 }}%
                        </span>
                    </div>
                    <div class="text-right">
                        <span class="text-gray-500">CTR (показ → клік):</span>
                        <span class="ml-2 text-xl font-bold {{ $funnelData['clicked']['rate'] >= 5 ? 'text-green-600' : ($funnelData['clicked']['rate'] >= 2 ? 'text-yellow-600' : 'text-gray-400') }}">
                            {{ $funnelData['clicked']['rate'] }}%
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Chart and Top Rules --}}
    <div class="grid gap-6 lg:grid-cols-2 mb-6">
        {{-- Trend Chart --}}
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">📈 Динаміка за період</h2>
            
            @if(count($trendData) > 0)
            <div class="h-64 relative" x-data="{ 
                data: {{ json_encode($trendData) }},
                maxShown: Math.max(...{{ json_encode(array_column($trendData, 'shown')) }}, 1)
            }">
                <div class="flex items-end justify-between h-48 gap-1 border-b border-gray-200 pb-2">
                    @foreach($trendData as $index => $day)
                        <div class="flex-1 flex flex-col items-center gap-1">
                            {{-- Bars --}}
                            <div class="w-full flex gap-0.5 items-end h-40">
                                {{-- Shown bar --}}
                                <div class="flex-1 bg-blue-400 rounded-t transition-all duration-300" 
                                     style="height: {{ $day['shown'] > 0 ? max(($day['shown'] / max(array_column($trendData, 'shown'))) * 100, 5) : 0 }}%"
                                     title="Показано: {{ $day['shown'] }}">
                                </div>
                                {{-- Clicked bar --}}
                                <div class="flex-1 bg-indigo-500 rounded-t transition-all duration-300" 
                                     style="height: {{ $day['clicked'] > 0 ? max(($day['clicked'] / max(array_column($trendData, 'shown'))) * 100, 5) : 0 }}%"
                                     title="Клікнуто: {{ $day['clicked'] }}">
                                </div>
                                {{-- Converted bar --}}
                                <div class="flex-1 bg-green-500 rounded-t transition-all duration-300" 
                                     style="height: {{ $day['converted'] > 0 ? max(($day['converted'] / max(array_column($trendData, 'shown'))) * 100, 5) : 0 }}%"
                                     title="Конверсій: {{ $day['converted'] }}">
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                {{-- X axis labels --}}
                <div class="flex justify-between mt-2 text-xs text-gray-500">
                    @foreach($trendData as $index => $day)
                        @if($index % max(1, intval(count($trendData) / 7)) === 0)
                            <span>{{ $day['date'] }}</span>
                        @endif
                    @endforeach
                </div>
                {{-- Legend --}}
                <div class="flex justify-center gap-4 mt-4 text-sm">
                    <div class="flex items-center gap-1">
                        <div class="w-3 h-3 bg-blue-400 rounded"></div>
                        <span class="text-gray-600">Показано</span>
                    </div>
                    <div class="flex items-center gap-1">
                        <div class="w-3 h-3 bg-indigo-500 rounded"></div>
                        <span class="text-gray-600">Клікнуто</span>
                    </div>
                    <div class="flex items-center gap-1">
                        <div class="w-3 h-3 bg-green-500 rounded"></div>
                        <span class="text-gray-600">В кошик</span>
                    </div>
                </div>
            </div>
            @else
            <div class="h-64 flex items-center justify-center text-gray-500">
                Немає даних за обраний період
            </div>
            @endif
        </div>

        {{-- Top Rules by CTR --}}
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">🏆 Топ-5 правил за CTR</h2>
            
            @if($topRules->count() > 0)
            <div class="space-y-3">
                @foreach($topRules as $index => $rule)
                    <div class="flex items-center gap-3 p-3 rounded-lg {{ $index === 0 ? 'bg-yellow-50 border border-yellow-200' : 'bg-gray-50' }}">
                        {{-- Rank --}}
                        <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm
                            {{ $index === 0 ? 'bg-yellow-400 text-yellow-900' : '' }}
                            {{ $index === 1 ? 'bg-gray-300 text-gray-700' : '' }}
                            {{ $index === 2 ? 'bg-orange-300 text-orange-900' : '' }}
                            {{ $index > 2 ? 'bg-gray-200 text-gray-600' : '' }}
                        ">
                            {{ $index + 1 }}
                        </div>
                        
                        {{-- Info --}}
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-gray-900 truncate">{{ $rule['name'] }}</div>
                            <div class="text-xs text-gray-500">
                                {{ $triggerTypes[$rule['type']] ?? $rule['type'] }} • 
                                {{ $rule['shown'] }} показів
                            </div>
                        </div>
                        
                        {{-- CTR --}}
                        <div class="text-right">
                            <div class="text-lg font-bold {{ $rule['ctr'] >= 5 ? 'text-green-600' : ($rule['ctr'] >= 2 ? 'text-yellow-600' : 'text-gray-500') }}">
                                {{ $rule['ctr'] }}%
                            </div>
                            <div class="text-xs text-gray-500">CTR</div>
                        </div>
                    </div>
                @endforeach
            </div>
            @else
            <div class="h-48 flex items-center justify-center text-gray-500">
                Немає даних за обраний період
            </div>
            @endif
        </div>
    </div>

    {{-- Detailed Rules Table --}}
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">📋 Детальна статистика по правилах</h2>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Правило</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Тип</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Статус</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">👁️ Показано</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">👆 Клікнуто</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">🛒 В кошик</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">✅ Замовлено</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">CTR</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Conv.</th>
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
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                        Активне
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">
                                        Вимкнене
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-right text-sm font-medium text-gray-900">
                                {{ number_format($rule['shown']) }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-right text-sm text-gray-700">
                                {{ number_format($rule['clicked']) }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-right text-sm text-gray-700">
                                {{ number_format($rule['converted']) }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-right text-sm text-gray-700">
                                {{ number_format($rule['purchased']) }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-right">
                                <span class="font-bold {{ $rule['ctr'] >= 5 ? 'text-green-600' : ($rule['ctr'] >= 2 ? 'text-yellow-600' : 'text-gray-500') }}">
                                    {{ $rule['ctr'] }}%
                                </span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-right">
                                <span class="font-medium {{ $rule['conversion_rate'] >= 10 ? 'text-green-600' : ($rule['conversion_rate'] >= 5 ? 'text-yellow-600' : 'text-gray-500') }}">
                                    {{ $rule['conversion_rate'] }}%
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-gray-500">
                                Немає даних за обраний період
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Info about tracking --}}
    <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
        <div class="flex items-start gap-3">
            <svg class="w-5 h-5 text-blue-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div class="text-sm text-blue-800">
                <p class="font-medium mb-1">Як рахується воронка:</p>
                <ul class="list-disc list-inside space-y-1 text-blue-700">
                    <li><strong>Показано</strong> — тригер з'явився на екрані користувача</li>
                    <li><strong>Клікнуто</strong> — користувач натиснув на кнопку тригера</li>
                    <li><strong>Переглянуто товар</strong> — після кліку користувач відкрив картку товару</li>
                    <li><strong>Додано в кошик</strong> — товар додано в кошик протягом сесії</li>
                    <li><strong>Замовлено</strong> — оформлено замовлення в тій самій сесії</li>
                </ul>
            </div>
        </div>
    </div>
</div>

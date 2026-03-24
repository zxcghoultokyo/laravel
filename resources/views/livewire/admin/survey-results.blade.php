<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Результати опитування</h1>
        <select wire:model.live="selectedTenantId" class="rounded-lg border-gray-300 text-sm">
            <option value="0">Всі тенанти</option>
            @foreach ($tenants as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>

    @if (!empty($stats))
        {{-- KPI Cards --}}
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-white rounded-xl shadow-sm p-5">
                <div class="text-sm text-gray-500">Відповідей</div>
                <div class="text-3xl font-bold text-gray-900 mt-1">{{ $stats['count'] }}</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm p-5">
                <div class="text-sm text-gray-500">Середня оцінка</div>
                <div class="text-3xl font-bold mt-1 {{ $stats['avg_rating'] >= 4 ? 'text-emerald-600' : ($stats['avg_rating'] >= 3 ? 'text-yellow-600' : 'text-red-600') }}">
                    {{ $stats['avg_rating'] }} / 5
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm p-5">
                <div class="text-sm text-gray-500">NPS</div>
                <div class="text-3xl font-bold mt-1 {{ $stats['nps'] >= 50 ? 'text-emerald-600' : ($stats['nps'] >= 0 ? 'text-yellow-600' : 'text-red-600') }}">
                    {{ $stats['nps'] }}
                </div>
                <div class="text-xs text-gray-400 mt-1">
                    {{ $stats['promoters'] }} промоутерів &middot; {{ $stats['passives'] }} пасивних &middot; {{ $stats['detractors'] }} критиків
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm p-5">
                <div class="text-sm text-gray-500">Готові платити</div>
                <div class="text-3xl font-bold text-emerald-600 mt-1">{{ $stats['payment_ready_percent'] }}%</div>
            </div>
        </div>

        {{-- Top Problems --}}
        @if (!empty($stats['top_problems']))
            <div class="bg-white rounded-xl shadow-sm p-6 mb-8">
                <h3 class="font-semibold text-gray-900 mb-4">Топ проблем</h3>
                @php
                    $problemLabels = [
                        'out_of_stock' => 'Товари не в наявності',
                        'wrong_category' => 'Не ті товари',
                        'wrong_language' => 'Інша мова',
                        'hallucinations' => 'Вигадані дані',
                        'broken_links' => 'Непрацюючі посилання',
                        'slow' => 'Повільно',
                        'no_ukrainian' => 'Не розуміє українську',
                        'repetitive' => 'Повторення товарів',
                        'none' => 'Проблем немає',
                    ];
                @endphp
                <div class="space-y-3">
                    @foreach ($stats['top_problems'] as $problem => $count)
                        <div class="flex items-center gap-3">
                            <div class="flex-1">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-sm text-gray-700">{{ $problemLabels[$problem] ?? $problem }}</span>
                                    <span class="text-sm font-medium text-gray-900">{{ $count }}</span>
                                </div>
                                <div class="w-full bg-gray-100 rounded-full h-2">
                                    <div class="bg-red-400 h-2 rounded-full" style="width: {{ min(100, $count / $stats['count'] * 100) }}%"></div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif

    {{-- Individual Responses --}}
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900">Всі відповіді</h3>
        </div>

        @forelse ($responses as $response)
            <div class="px-6 py-4 border-b border-gray-50 hover:bg-gray-50">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <span class="font-medium text-gray-900">{{ $response->tenant->name }}</span>
                        <span class="text-sm text-gray-500 ml-2">{{ $response->created_at->format('d.m.Y H:i') }}</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm {{ $response->overall_rating >= 4 ? 'text-emerald-600' : ($response->overall_rating >= 3 ? 'text-yellow-600' : 'text-red-600') }}">
                            Оцінка: {{ $response->overall_rating }}/5
                        </span>
                        <span class="text-sm {{ $response->nps_score >= 9 ? 'text-emerald-600' : ($response->nps_score >= 7 ? 'text-yellow-600' : 'text-red-600') }}">
                            NPS: {{ $response->nps_score }}
                        </span>
                    </div>
                </div>

                @if ($response->open_comment)
                    <p class="text-sm text-gray-600 italic">"{{ $response->open_comment }}"</p>
                @endif

                @if (!empty($response->problems) && !in_array('none', $response->problems))
                    <div class="flex flex-wrap gap-1 mt-2">
                        @foreach ($response->problems as $problem)
                            <span class="px-2 py-0.5 bg-red-50 text-red-600 text-xs rounded-full">{{ $problemLabels[$problem] ?? $problem }}</span>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <div class="px-6 py-12 text-center text-gray-500">
                Поки немає відповідей
            </div>
        @endforelse
    </div>

    {{-- Survey Links --}}
    <div class="bg-white rounded-xl shadow-sm p-6 mt-8">
        <h3 class="font-semibold text-gray-900 mb-4">Посилання на опитування</h3>
        <div class="space-y-2">
            @foreach ($tenants as $id => $name)
                @php
                    $tenant = \App\Models\Tenant::find($id);
                @endphp
                @if ($tenant && $tenant->slug)
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <span class="text-sm text-gray-700">{{ $name }}</span>
                        <code class="text-xs text-emerald-600 bg-emerald-50 px-2 py-1 rounded">{{ url('/survey/' . $tenant->slug) }}</code>
                    </div>
                @endif
            @endforeach
        </div>
    </div>
</div>

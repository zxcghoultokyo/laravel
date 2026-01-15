<div>
    @section('title', 'Експорт даних')

    <!-- Header -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Експорт даних</h1>
        <p class="text-gray-600">Завантажте аналітику у форматі CSV або JSON</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Export Options -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sticky top-6">
                <h2 class="font-semibold text-gray-900 mb-4">Параметри експорту</h2>
                
                <div class="space-y-4">
                    <!-- Export Type -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Тип даних</label>
                        <div class="space-y-2">
                            @foreach($exportTypes as $type)
                                <label class="flex items-start p-3 border rounded-lg cursor-pointer transition {{ $exportType === $type['key'] ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:border-gray-300' }}">
                                    <input type="radio" wire:model.live="exportType" value="{{ $type['key'] }}" class="mt-0.5 text-blue-600 focus:ring-blue-500">
                                    <div class="ml-3">
                                        <div class="flex items-center gap-2">
                                            <span>{{ $type['icon'] }}</span>
                                            <span class="font-medium text-gray-900">{{ $type['label'] }}</span>
                                        </div>
                                        <p class="text-xs text-gray-500">{{ $type['description'] }}</p>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <!-- Date Range -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Від</label>
                            <input type="date" wire:model.live="dateFrom" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">До</label>
                            <input type="date" wire:model.live="dateTo" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <!-- Format -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Формат</label>
                        <div class="flex gap-3">
                            <label class="flex-1 flex items-center justify-center p-3 border rounded-lg cursor-pointer transition {{ $format === 'csv' ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:border-gray-300' }}">
                                <input type="radio" wire:model.live="format" value="csv" class="sr-only">
                                <span class="font-medium {{ $format === 'csv' ? 'text-blue-700' : 'text-gray-700' }}">📄 CSV</span>
                            </label>
                            <label class="flex-1 flex items-center justify-center p-3 border rounded-lg cursor-pointer transition {{ $format === 'json' ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:border-gray-300' }}">
                                <input type="radio" wire:model.live="format" value="json" class="sr-only">
                                <span class="font-medium {{ $format === 'json' ? 'text-blue-700' : 'text-gray-700' }}">{ } JSON</span>
                            </label>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="pt-4 space-y-3">
                        <button wire:click="preview" wire:loading.attr="disabled" class="w-full px-4 py-2.5 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition flex items-center justify-center gap-2">
                            <svg wire:loading wire:target="preview" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            <span wire:loading.remove wire:target="preview">👁️</span>
                            Попередній перегляд
                        </button>
                        <button wire:click="download" class="w-full px-4 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Завантажити {{ strtoupper($format) }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Preview -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="p-4 border-b border-gray-200 flex items-center justify-between">
                    <h2 class="font-semibold text-gray-900">Попередній перегляд</h2>
                    @if($previewData)
                        <span class="text-sm text-gray-500">{{ $previewData['rows_count'] }} записів</span>
                    @endif
                </div>

                @if(session()->has('error'))
                    <div class="p-4 bg-red-50 border-b border-red-100 text-red-700">
                        {{ session('error') }}
                    </div>
                @endif

                @if($previewData && count($previewData['data']) > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    @foreach(array_keys($previewData['data'][0]) as $header)
                                        <th class="px-4 py-2 text-left font-medium text-gray-700 whitespace-nowrap">{{ $header }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($previewData['data'] as $row)
                                    <tr class="hover:bg-gray-50">
                                        @foreach($row as $value)
                                            <td class="px-4 py-2 text-gray-600 whitespace-nowrap max-w-xs truncate" title="{{ $value }}">
                                                {{ is_array($value) ? json_encode($value) : $value }}
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($previewData['rows_count'] > 10)
                        <div class="p-4 bg-gray-50 text-center text-sm text-gray-500">
                            Показано перші 10 з {{ $previewData['rows_count'] }} записів
                        </div>
                    @endif
                @elseif($previewData)
                    <div class="p-12 text-center text-gray-500">
                        <svg class="w-12 h-12 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        <p>Немає даних за обраний період</p>
                    </div>
                @else
                    <div class="p-12 text-center text-gray-500">
                        <svg class="w-12 h-12 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        <p>Оберіть тип даних та натисніть "Попередній перегляд"</p>
                    </div>
                @endif
            </div>

            <!-- Help -->
            <div class="mt-6 bg-blue-50 rounded-xl p-6 border border-blue-100">
                <h3 class="font-semibold text-blue-900 mb-2">💡 Підказка</h3>
                <ul class="text-sm text-blue-800 space-y-1">
                    <li>• <strong>CSV</strong> — ідеально для Excel та Google Sheets</li>
                    <li>• <strong>JSON</strong> — для інтеграції з іншими системами</li>
                    <li>• Денна статистика — найкращий огляд для звітів</li>
                    <li>• Воронка конверсій — аналіз ефективності чат-бота</li>
                </ul>
            </div>
        </div>
    </div>
</div>

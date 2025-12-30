<div wire:poll.5s="loadSession">
    <!-- Header -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <a href="{{ route('admin.chats.index') }}" class="text-sm text-blue-600 hover:underline mb-2 inline-block">
                ← Назад до списку
            </a>
            <h2 class="text-2xl font-bold text-gray-900">Сесія: {{ substr($session->session_id, 0, 8) }}...</h2>
            <p class="mt-1 text-sm text-gray-500">{{ $session->messages_count }} повідомлень</p>
        </div>
        <div class="flex gap-2">
            @if($operatorMode)
                <button wire:click="release" class="px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                    Повернути AI
                </button>
            @else
                <button wire:click="takeOver" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Взяти в роботу
                </button>
            @endif
            @if($session->status === 'open')
            <button wire:click="markAsResolved" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                Позначити вирішеним
            </button>
            @endif
            <button wire:click="flagSession" class="px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700">
                Позначити
            </button>
            <button wire:click="copySessionSummary" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                Копіювати звіт
            </button>
        </div>
    </div>

    <!-- Operator Mode Banner -->
    @if($operatorMode)
    <div class="mb-4 bg-green-50 border border-green-200 rounded-lg p-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <span class="flex h-3 w-3 relative">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-3 w-3 bg-green-500"></span>
            </span>
            <span class="text-green-800 font-medium">Ви ведете цей чат. AI вимкнено для цієї сесії.</span>
        </div>
    </div>
    @endif

    <div class="grid grid-cols-3 gap-6">
        <!-- Messages Timeline -->
        <div class="col-span-2 space-y-4">
            @foreach($messages as $message)
            <div class="bg-white rounded-lg shadow-sm p-4 @if($message->role === 'user') border-l-4 border-blue-500 @elseif(isset($message->meta['operator'])) border-l-4 border-green-500 @else border-l-4 border-gray-300 @endif">
                <div class="flex items-start justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-1 text-xs font-medium rounded-full @if($message->role === 'user') bg-blue-100 text-blue-800 @elseif(isset($message->meta['operator'])) bg-green-100 text-green-800 @else bg-gray-100 text-gray-800 @endif">
                            @if(isset($message->meta['operator']))
                                operator
                            @else
                                {{ $message->role }}
                            @endif
                        </span>
                        <span class="text-xs text-gray-500">{{ $message->created_at->format('H:i:s') }}</span>
                    </div>
                </div>

                <div class="prose prose-sm max-w-none">
                    <p class="text-gray-900">{{ $message->content }}</p>
                </div>

                @if($message->role === 'assistant' && isset($message->meta['intent']))
                <!-- AI Agent Debug Info -->
                <div class="mt-3 border-t pt-3">
                    <div class="flex items-center gap-2 mb-2">
                        <svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                        </svg>
                        <span class="text-sm font-semibold text-purple-700">AI Agent Flow</span>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <!-- Intent -->
                        <div>
                            <span class="text-gray-500">Intent:</span>
                            <span class="ml-1 px-2 py-0.5 bg-purple-100 text-purple-800 rounded font-medium">
                                {{ $message->meta['intent'] }}
                            </span>
                        </div>
                        
                        <!-- Ambiguous -->
                        @if(isset($message->meta['ambiguous']))
                        <div>
                            <span class="text-gray-500">Ambiguous:</span>
                            <span class="ml-1 px-2 py-0.5 rounded font-medium {{ $message->meta['ambiguous'] ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800' }}">
                                {{ $message->meta['ambiguous'] ? 'Yes' : 'No' }}
                            </span>
                        </div>
                        @endif
                        
                        <!-- Products shown -->
                        @if(isset($message->meta['products_shown']))
                        <div>
                            <span class="text-gray-500">Products shown:</span>
                            <span class="ml-1 font-medium text-gray-900">{{ $message->meta['products_shown'] }}</span>
                        </div>
                        @endif
                        
                        <!-- Chosen IDs -->
                        @if(!empty($message->meta['chosen_articles']))
                        <div class="col-span-2">
                            <span class="text-gray-500">Обрані артикули:</span>
                            <div class="mt-1 flex flex-wrap gap-1">
                                @foreach(array_slice($message->meta['chosen_articles'], 0, 10) as $article)
                                <code class="px-2 py-0.5 bg-blue-50 text-blue-800 rounded text-xs font-mono">{{ $article }}</code>
                                @endforeach
                                @if(count($message->meta['chosen_articles']) > 10)
                                <span class="text-xs text-gray-400">+{{ count($message->meta['chosen_articles']) - 10 }} more</span>
                                @endif
                            </div>
                        </div>
                        @elseif(!empty($message->meta['chosen_ids']))
                        <div>
                            <span class="text-gray-500">Chosen IDs:</span>
                            <span class="ml-1 font-mono text-xs text-gray-900">{{ implode(', ', array_slice($message->meta['chosen_ids'], 0, 5)) }}@if(count($message->meta['chosen_ids']) > 5)...@endif</span>
                        </div>
                        @endif
                    </div>
                    
                    <!-- Refined query -->
                    @if(!empty($message->meta['refined_query']))
                    <div class="mt-2">
                        <span class="text-xs text-gray-500">Refined query:</span>
                        <code class="ml-1 px-2 py-0.5 bg-gray-100 text-gray-800 rounded text-xs">{{ $message->meta['refined_query'] }}</code>
                    </div>
                    @endif
                    
                    <!-- Filters -->
                    @if(!empty($message->meta['filters']))
                    <div class="mt-2">
                        <span class="text-xs text-gray-500">Filters:</span>
                        <div class="flex flex-wrap gap-1 mt-1">
                            @foreach($message->meta['filters'] as $key => $value)
                            <span class="px-2 py-0.5 bg-blue-100 text-blue-800 rounded text-xs font-medium">
                                {{ $key }}: {{ is_array($value) ? json_encode($value) : $value }}
                            </span>
                            @endforeach
                        </div>
                    </div>
                    @endif
                    
                    <!-- Search debug -->
                    @if(!empty($message->meta['search_debug']))
                    <details class="mt-2">
                        <summary class="text-xs text-gray-500 cursor-pointer hover:text-gray-700">🔍 Search Debug</summary>
                        <pre class="mt-1 text-xs bg-gray-50 p-2 rounded overflow-x-auto border">{{ json_encode($message->meta['search_debug'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </details>
                    @endif
                </div>
                @endif

                @if($message->meta)
                <details class="mt-3">
                    <summary class="text-xs text-gray-500 cursor-pointer hover:text-gray-700">📋 Full Metadata</summary>
                    <pre class="mt-2 text-xs bg-gray-50 p-2 rounded overflow-x-auto">{{ json_encode($message->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </details>
                @endif
            </div>
            @endforeach
        </div>

        <!-- Operator Message Form -->
        @if($operatorMode)
        <div class="bg-white rounded-lg shadow-sm p-4 border-2 border-green-200">
            <form wire:submit="sendOperatorMessage" class="flex gap-3">
                <input 
                    type="text" 
                    wire:model="operatorMessage"
                    placeholder="Написати повідомлення клієнту..."
                    class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                    autofocus
                >
                <button 
                    type="submit" 
                    class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 flex items-center gap-2"
                    wire:loading.attr="disabled"
                >
                    <span wire:loading.remove wire:target="sendOperatorMessage">Надіслати</span>
                    <span wire:loading wire:target="sendOperatorMessage">...</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                    </svg>
                </button>
            </form>
        </div>
        @endif

        <!-- Metadata Sidebar -->
        <div class="space-y-4">
            <!-- Operator Status Card -->
            <div class="bg-white rounded-lg shadow-sm p-4">
                <h3 class="font-semibold text-gray-900 mb-3">Статус обробки</h3>
                <div class="flex items-center gap-3">
                    @if($operatorMode)
                        <span class="flex h-3 w-3 relative">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-3 w-3 bg-green-500"></span>
                        </span>
                        <span class="text-sm font-medium text-green-700">Оператор веде</span>
                    @else
                        <span class="flex h-3 w-3 relative">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-3 w-3 bg-blue-500"></span>
                        </span>
                        <span class="text-sm font-medium text-blue-700">AI обробляє</span>
                    @endif
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm p-4">
                <h3 class="font-semibold text-gray-900 mb-3">Метадані</h3>
                <dl class="space-y-2 text-sm">
                    <div>
                        <dt class="text-gray-500">ID сесії</dt>
                        <dd class="font-mono text-xs text-gray-900 break-all">{{ $session->session_id }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Статус</dt>
                        <dd>
                            <span class="px-2 py-1 text-xs font-medium rounded-full
                                @if($session->status === 'open') bg-green-100 text-green-800
                                @elseif($session->status === 'closed') bg-gray-100 text-gray-800
                                @else bg-red-100 text-red-800
                                @endif">
                                {{ ucfirst($session->status) }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Останній інтент</dt>
                        <dd class="text-gray-900">{{ $session->last_intent ?? 'N/A' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Мова</dt>
                        <dd class="text-gray-900">{{ $session->language ?? 'N/A' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Створено</dt>
                        <dd class="text-gray-900">{{ $session->created_at->format('d.m.Y H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Остання активність</dt>
                        <dd class="text-gray-900">{{ $session->last_message_at?->diffForHumans() }}</dd>
                    </div>
                </dl>
            </div>

            @if($session->meta)
            <div class="bg-white rounded-lg shadow-sm p-4">
                <h3 class="font-semibold text-gray-900 mb-3">Додаткова інформація</h3>
                <pre class="text-xs bg-gray-50 p-2 rounded overflow-x-auto">{{ json_encode($session->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
            @endif
        </div>
    </div>
</div>

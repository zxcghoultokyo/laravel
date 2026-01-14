<div class="h-[calc(100vh-6rem)] flex" wire:poll.3s.keep-alive="loadSession">
    <!-- Main Chat Area -->
    <div class="flex-1 flex flex-col bg-gray-50 rounded-l-lg overflow-hidden">
        <!-- Chat Header -->
        <div class="bg-white border-b px-4 py-3 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-4">
                <a href="{{ route('admin.chats.index') }}" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <div>
                    <h2 class="font-semibold text-gray-900">Чат #{{ substr($session->session_id, -8) }}</h2>
                    <p class="text-xs text-gray-500">{{ $session->messages_count }} повідомлень</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                @if($operatorMode)
                    <span class="flex items-center gap-2 px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-sm font-medium">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                        </span>
                        Ви ведете
                    </span>
                    <button wire:click="release" class="px-3 py-1.5 text-sm bg-orange-100 text-orange-700 rounded-lg hover:bg-orange-200 transition">
                        Повернути AI
                    </button>
                @else
                    <span class="flex items-center gap-2 px-3 py-1.5 bg-blue-100 text-blue-700 rounded-full text-sm font-medium">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-blue-500"></span>
                        </span>
                        AI обробляє
                    </span>
                    <button wire:click="takeOver" class="px-3 py-1.5 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                        Взяти в роботу
                    </button>
                @endif
            </div>
        </div>

        <!-- Messages Container -->
        <div 
            id="messages-container" 
            class="flex-1 overflow-y-auto p-4 space-y-3"
            x-data
            x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
            wire:ignore.self
        >
            @foreach($messages as $message)
                @php
                    $isUser = $message->role === 'user';
                    $isOperator = $message->role === 'operator' || isset($message->meta['operator']);
                    $isAssistant = $message->role === 'assistant' && !$isOperator;
                @endphp
                
                <div class="flex {{ $isUser ? 'justify-end' : 'justify-start' }} gap-2">
                    @if(!$isUser)
                    <!-- Avatar -->
                    <div class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center {{ $isOperator ? 'bg-green-500' : 'bg-gray-600' }} text-white text-xs font-bold">
                        {{ $isOperator ? '👤' : '🤖' }}
                    </div>
                    @endif
                    
                    <div class="max-w-[70%] {{ $isUser ? 'order-first' : '' }}">
                        <!-- Role & Time -->
                        <div class="flex items-center gap-2 mb-1 {{ $isUser ? 'justify-end' : 'justify-start' }}">
                            <span class="text-xs font-medium {{ $isUser ? 'text-blue-600' : ($isOperator ? 'text-green-600' : 'text-gray-600') }}">
                                {{ $isUser ? 'Клієнт' : ($isOperator ? 'Оператор' : 'AI') }}
                            </span>
                            <span class="text-xs text-gray-400">{{ $message->created_at->format('H:i') }}</span>
                        </div>
                        
                        <!-- Message Bubble -->
                        <div class="rounded-2xl px-4 py-2.5 {{ $isUser ? 'bg-blue-500 text-white rounded-br-md' : ($isOperator ? 'bg-green-100 text-gray-900 rounded-bl-md' : 'bg-white text-gray-900 shadow-sm rounded-bl-md') }}">
                            @php
                                $content = $message->content;
                                $parsedContent = null;
                                
                                // Try to parse JSON content for AI responses
                                if ($isAssistant && str_starts_with(trim($content), '{')) {
                                    $parsed = json_decode($content, true);
                                    if (json_last_error() === JSON_ERROR_NONE) {
                                        $parsedContent = $parsed;
                                    }
                                }
                            @endphp
                            
                            @if($parsedContent && isset($parsedContent['intro']))
                                <p>{{ $parsedContent['intro'] }}</p>
                                @if(!empty($parsedContent['outro']))
                                    <p class="mt-2 text-sm opacity-80">{{ $parsedContent['outro'] }}</p>
                                @endif
                            @elseif($parsedContent && isset($parsedContent['text']))
                                <p>{{ $parsedContent['text'] }}</p>
                            @else
                                <p class="whitespace-pre-wrap">{{ $content }}</p>
                            @endif
                        </div>
                        
                        <!-- Products shown (compact) -->
                        @if($isAssistant && !empty($message->meta['products']))
                        <div class="mt-2 flex flex-wrap gap-1">
                            @foreach(array_slice($message->meta['products'], 0, 5) as $product)
                            <span class="inline-flex items-center gap-1 px-2 py-1 bg-gray-100 rounded text-xs text-gray-600">
                                📦 {{ Str::limit($product['title'] ?? 'Товар', 20) }}
                            </span>
                            @endforeach
                            @if(count($message->meta['products']) > 5)
                            <span class="px-2 py-1 text-xs text-gray-400">+{{ count($message->meta['products']) - 5 }}</span>
                            @endif
                        </div>
                        @endif
                        
                        <!-- AI Intent badge -->
                        @if($isAssistant && isset($message->meta['intent']))
                        <div class="mt-1 flex items-center gap-1">
                            <span class="text-xs px-1.5 py-0.5 bg-purple-100 text-purple-700 rounded">
                                {{ $message->meta['intent'] }}
                            </span>
                            @if(!empty($message->meta['products_shown']))
                            <span class="text-xs text-gray-400">· {{ $message->meta['products_shown'] }} товарів</span>
                            @endif
                        </div>
                        @endif
                    </div>
                    
                    @if($isUser)
                    <!-- User Avatar -->
                    <div class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center bg-blue-500 text-white text-xs font-bold">
                        👤
                    </div>
                    @endif
                </div>
            @endforeach
            
            <!-- Scroll anchor -->
            <div id="messages-end"></div>
        </div>

        <!-- Input Area (always visible, disabled when not operator) -->
        <div class="bg-white border-t p-4 shrink-0">
            @if($operatorMode)
            <form wire:submit="sendOperatorMessage" class="flex gap-3">
                <input 
                    type="text" 
                    wire:model="operatorMessage"
                    placeholder="Напишіть відповідь клієнту..."
                    class="flex-1 px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent text-sm"
                    autofocus
                    x-on:keydown.enter.prevent="$wire.sendOperatorMessage()"
                >
                <button 
                    type="submit" 
                    class="px-6 py-3 bg-green-600 text-white rounded-xl hover:bg-green-700 transition flex items-center gap-2 font-medium"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-50"
                >
                    <span wire:loading.remove wire:target="sendOperatorMessage">Надіслати</span>
                    <span wire:loading wire:target="sendOperatorMessage">Відправка...</span>
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                    </svg>
                </button>
            </form>
            @else
            <div class="flex items-center justify-center gap-2 py-3 text-gray-400 text-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
                <span>Натисніть "Взяти в роботу" щоб відповісти клієнту</span>
            </div>
            @endif
        </div>
    </div>

    <!-- Right Sidebar -->
    <div class="w-80 bg-white border-l flex flex-col overflow-hidden shrink-0">
        <!-- Session Info Header -->
        <div class="p-4 border-b">
            <h3 class="font-semibold text-gray-900 mb-3">Інформація</h3>
            
            <!-- Status -->
            <div class="flex items-center justify-between py-2">
                <span class="text-sm text-gray-500">Статус чату</span>
                <span class="px-2 py-1 text-xs font-medium rounded-full {{ $session->status === 'open' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                    {{ $session->status === 'open' ? 'Відкритий' : 'Закритий' }}
                </span>
            </div>
            
            <!-- Processing -->
            <div class="flex items-center justify-between py-2 border-t">
                <span class="text-sm text-gray-500">Обробка</span>
                <span class="text-sm font-medium {{ $operatorMode ? 'text-green-600' : 'text-blue-600' }}">
                    {{ $operatorMode ? 'Оператор' : 'AI' }}
                </span>
            </div>
            
            <!-- Last Activity -->
            <div class="flex items-center justify-between py-2 border-t">
                <span class="text-sm text-gray-500">Активність</span>
                <span class="text-sm text-gray-900">{{ $session->last_message_at?->diffForHumans() ?? 'N/A' }}</span>
            </div>
            
            <!-- Last Intent -->
            @if($session->last_intent)
            <div class="flex items-center justify-between py-2 border-t">
                <span class="text-sm text-gray-500">Інтент</span>
                <span class="px-2 py-1 text-xs bg-purple-100 text-purple-700 rounded">{{ $session->last_intent }}</span>
            </div>
            @endif
        </div>
        
        <!-- Actions -->
        <div class="p-4 border-b space-y-2">
            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Дії</h4>
            
            @if($session->status === 'open')
            <button wire:click="markAsResolved" class="w-full px-3 py-2 text-sm bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition flex items-center justify-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                Закрити чат
            </button>
            @endif
            
            <button wire:click="flagSession" class="w-full px-3 py-2 text-sm bg-yellow-50 text-yellow-700 rounded-lg hover:bg-yellow-100 transition flex items-center justify-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"/>
                </svg>
                Додати закладку
            </button>
            
            <button wire:click="copySessionSummary" class="w-full px-3 py-2 text-sm bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition flex items-center justify-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                </svg>
                Копіювати звіт
            </button>
        </div>
        
        <!-- Session Meta -->
        <div class="p-4 flex-1 overflow-y-auto">
            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Технічні дані</h4>
            
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-gray-500 text-xs">Session ID</dt>
                    <dd class="font-mono text-xs text-gray-700 break-all mt-0.5">{{ $session->session_id }}</dd>
                </div>
                
                <div>
                    <dt class="text-gray-500 text-xs">Створено</dt>
                    <dd class="text-gray-700 mt-0.5">{{ $session->created_at->format('d.m.Y H:i:s') }}</dd>
                </div>
                
                @if($session->language)
                <div>
                    <dt class="text-gray-500 text-xs">Мова</dt>
                    <dd class="text-gray-700 mt-0.5">{{ $session->language }}</dd>
                </div>
                @endif
                
                @if($activeSessionData)
                <div class="pt-3 border-t">
                    <dt class="text-gray-500 text-xs mb-1">Active Session</dt>
                    <dd class="text-xs">
                        <div class="space-y-1">
                            <div class="flex justify-between">
                                <span class="text-gray-500">Повідомлень:</span>
                                <span class="text-gray-700">{{ $activeSessionData->message_count ?? 0 }}</span>
                            </div>
                            @if($activeSessionData->operator_took_at)
                            <div class="flex justify-between">
                                <span class="text-gray-500">Взято:</span>
                                <span class="text-gray-700">{{ \Carbon\Carbon::parse($activeSessionData->operator_took_at)->format('H:i') }}</span>
                            </div>
                            @endif
                        </div>
                    </dd>
                </div>
                @endif
            </dl>
            
            @if($session->meta && count((array)$session->meta) > 0)
            <details class="mt-4">
                <summary class="text-xs text-gray-500 cursor-pointer hover:text-gray-700">Показати метадані</summary>
                <pre class="mt-2 text-xs bg-gray-50 p-2 rounded overflow-x-auto">{{ json_encode($session->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </details>
            @endif
        </div>
    </div>
</div>

<script>
    // Auto-scroll to bottom on load and updates
    document.addEventListener('livewire:init', () => {
        const container = document.getElementById('messages-container');
        if (container) {
            container.scrollTop = container.scrollHeight;
        }
    });
    
    // Scroll after Livewire updates
    Livewire.hook('morph.updated', ({ el }) => {
        const container = document.getElementById('messages-container');
        if (container) {
            container.scrollTop = container.scrollHeight;
        }
    });
</script>

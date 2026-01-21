<div class="h-[calc(100vh-4rem)] md:h-[calc(100vh-6rem)] flex flex-col md:flex-row" 
     wire:poll.3s.keep-alive="loadSession"
     x-data="{ showSidebar: false }">
    
    <!-- Main Chat Area -->
    <div class="flex-1 flex flex-col bg-gray-50 md:rounded-l-lg overflow-hidden min-h-0">
        <!-- Chat Header -->
        <div class="bg-white border-b px-3 md:px-4 py-2 md:py-3 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-2 md:gap-4">
                @if(!$embedded)
                <a href="{{ route('admin.chats.index') }}" class="text-gray-400 hover:text-gray-600 p-1">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                @endif
                <div>
                    <h2 class="font-semibold text-gray-900 text-sm md:text-base">Чат #{{ substr($session->session_id, -8) }}</h2>
                    <p class="text-xs text-gray-500 hidden md:block">{{ $session->messages_count }} повідомлень</p>
                </div>
            </div>
            <div class="flex items-center gap-1 md:gap-2">
                @if($operatorMode)
                    <span class="hidden md:flex items-center gap-2 px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-sm font-medium">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                        </span>
                        Ви ведете
                    </span>
                    <span class="md:hidden w-3 h-3 bg-green-500 rounded-full"></span>
                    <button wire:click="release" class="px-2 md:px-3 py-1.5 text-xs md:text-sm bg-orange-100 text-orange-700 rounded-lg hover:bg-orange-200 transition">
                        <span class="hidden md:inline">Повернути AI</span>
                        <span class="md:hidden">AI</span>
                    </button>
                @else
                    <span class="hidden md:flex items-center gap-2 px-3 py-1.5 bg-blue-100 text-blue-700 rounded-full text-sm font-medium">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-blue-500"></span>
                        </span>
                        AI обробляє
                    </span>
                    <span class="md:hidden w-3 h-3 bg-blue-500 rounded-full animate-pulse"></span>
                    <button wire:click="takeOver" class="px-2 md:px-3 py-1.5 text-xs md:text-sm bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                        <span class="hidden md:inline">Взяти в роботу</span>
                        <span class="md:hidden">Взяти</span>
                    </button>
                @endif
                
                <!-- Copy Report Button -->
                <button 
                    wire:click="copyReport"
                    class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition"
                    title="Копіювати звіт"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                </button>
                
                <!-- Mobile Info Button -->
                <button 
                    @click="showSidebar = true" 
                    class="md:hidden p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition"
                    title="Інформація"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Messages Container -->
        <div 
            id="messages-container" 
            class="flex-1 overflow-y-auto p-3 md:p-4 space-y-3"
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
                    <div class="w-7 h-7 md:w-8 md:h-8 rounded-full flex-shrink-0 flex items-center justify-center {{ $isOperator ? 'bg-green-500' : 'bg-gray-600' }} text-white text-xs font-bold">
                        {{ $isOperator ? '👤' : '🤖' }}
                    </div>
                    @endif
                    
                    <div class="max-w-[85%] md:max-w-[70%] {{ $isUser ? 'order-first' : '' }}">
                        <!-- Role & Time -->
                        <div class="flex items-center gap-2 mb-1 {{ $isUser ? 'justify-end' : 'justify-start' }}">
                            <span class="text-xs font-medium {{ $isUser ? 'text-blue-600' : ($isOperator ? 'text-green-600' : 'text-gray-600') }}">
                                {{ $isUser ? 'Клієнт' : ($isOperator ? 'Оператор' : 'AI') }}
                            </span>
                            <span class="text-xs text-gray-400">{{ $message->created_at->format('H:i') }}</span>
                        </div>
                        
                        <!-- Message Bubble -->
                        <div class="rounded-2xl px-3 md:px-4 py-2 md:py-2.5 {{ $isUser ? 'bg-blue-500 text-white rounded-br-md' : ($isOperator ? 'bg-green-100 text-gray-900 rounded-bl-md' : 'bg-white text-gray-900 shadow-sm rounded-bl-md') }}">
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
        <div class="bg-white border-t p-2 md:p-4 shrink-0">
            @if($operatorMode)
            <!-- Canned Responses Panel -->
            @if($showCannedResponses)
            <div class="mb-3 p-3 bg-gray-50 rounded-xl border border-gray-200" x-data>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-gray-700">📋 Шаблони відповідей</span>
                    <button wire:click="toggleCannedResponses" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                
                <!-- Search -->
                <div class="mb-2">
                    <input type="text" wire:model.live.debounce.300ms="cannedSearch" 
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500"
                           placeholder="Пошук шаблонів... (або введіть /shortcut)">
                </div>
                
                <!-- Category Filter -->
                <div class="flex flex-wrap gap-1 mb-2">
                    <button wire:click="$set('selectedCategory', '')" 
                            class="px-2 py-1 text-xs rounded {{ $selectedCategory === '' ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                        Всі
                    </button>
                    <button wire:click="$set('selectedCategory', 'greeting')" 
                            class="px-2 py-1 text-xs rounded {{ $selectedCategory === 'greeting' ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                        👋 Привітання
                    </button>
                    <button wire:click="$set('selectedCategory', 'closing')" 
                            class="px-2 py-1 text-xs rounded {{ $selectedCategory === 'closing' ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                        👋 Завершення
                    </button>
                    <button wire:click="$set('selectedCategory', 'info')" 
                            class="px-2 py-1 text-xs rounded {{ $selectedCategory === 'info' ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                        ℹ️ Інформація
                    </button>
                    <button wire:click="$set('selectedCategory', 'clarify')" 
                            class="px-2 py-1 text-xs rounded {{ $selectedCategory === 'clarify' ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                        ❓ Уточнення
                    </button>
                </div>
                
                <!-- Templates Grid -->
                <div class="max-h-40 overflow-y-auto space-y-1">
                    @forelse($cannedResponses as $response)
                    <div class="flex items-start gap-2 p-2 bg-white rounded-lg border border-gray-100 hover:border-green-300 cursor-pointer group"
                         wire:click="useCannedResponse({{ $response->id }})">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-sm text-gray-900 truncate">{{ $response->title }}</span>
                                @if($response->shortcut)
                                <code class="text-xs text-green-600 bg-green-50 px-1 rounded">/{{ $response->shortcut }}</code>
                                @endif
                            </div>
                            <p class="text-xs text-gray-500 truncate">{{ Str::limit($response->content, 60) }}</p>
                        </div>
                        <button wire:click.stop="appendCannedResponse({{ $response->id }})" 
                                class="opacity-0 group-hover:opacity-100 p-1 text-gray-400 hover:text-green-600 transition"
                                title="Додати до повідомлення">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        </button>
                    </div>
                    @empty
                    <div class="text-center py-4 text-sm text-gray-400">
                        Шаблонів не знайдено
                    </div>
                    @endforelse
                </div>
            </div>
            @endif

            <form wire:submit="sendOperatorMessage" class="flex gap-2 md:gap-3">
                <!-- Templates Button -->
                <button 
                    type="button"
                    wire:click="toggleCannedResponses"
                    class="px-3 py-3 bg-gray-100 text-gray-600 rounded-xl hover:bg-gray-200 transition flex-shrink-0 {{ $showCannedResponses ? 'ring-2 ring-green-500' : '' }}"
                    title="Шаблони відповідей"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                    </svg>
                </button>
                
                <input 
                    type="text" 
                    wire:model="operatorMessage"
                    placeholder="Напишіть відповідь..."
                    class="flex-1 px-3 md:px-4 py-2.5 md:py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent text-sm"
                    autofocus
                    x-on:keydown.enter.prevent="$wire.sendOperatorMessage()"
                >
                <button 
                    type="submit" 
                    class="px-4 md:px-6 py-3 bg-green-600 text-white rounded-xl hover:bg-green-700 transition flex items-center gap-2 font-medium flex-shrink-0"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-50"
                >
                    <span class="hidden md:inline" wire:loading.remove wire:target="sendOperatorMessage">Надіслати</span>
                    <span class="hidden md:inline" wire:loading wire:target="sendOperatorMessage">...</span>
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                    </svg>
                </button>
            </form>
            @else
            <div class="flex items-center justify-center gap-2 py-2 md:py-3 text-gray-400 text-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
                <span class="text-xs md:text-sm">Натисніть "Взяти в роботу" щоб відповісти клієнту</span>
            </div>
            @endif
        </div>
    </div>

    <!-- Right Sidebar - Desktop: always visible, Mobile: slide-in overlay -->
    <div 
        x-show="showSidebar"
        x-cloak
        @click.self="showSidebar = false"
        class="md:hidden fixed inset-0 bg-black/50 z-40"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    ></div>
    
    <div 
        :class="{ 'translate-x-0': showSidebar, 'translate-x-full': !showSidebar }"
        class="fixed md:relative inset-y-0 right-0 z-50 md:z-auto md:translate-x-0 w-80 bg-white border-l flex flex-col overflow-hidden shrink-0 transform transition-transform duration-300 ease-in-out md:transition-none"
    >
        <!-- Mobile Close Button -->
        <div class="md:hidden flex items-center justify-between p-4 border-b bg-gray-50">
            <h3 class="font-semibold text-gray-900">Інформація про сесію</h3>
            <button @click="showSidebar = false" class="p-1 text-gray-400 hover:text-gray-600 rounded">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        
        <!-- Session Info Header -->
        <div class="p-4 border-b md:border-t-0">
            <h3 class="hidden md:block font-semibold text-gray-900 mb-3">Інформація</h3>
            
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
            
            <!-- Events History -->
            @if(count($chatEvents) > 0)
            <div class="mt-6 pt-4 border-t">
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">
                    📊 Історія івентів 
                    <span class="text-gray-400">({{ count($chatEvents) }})</span>
                </h4>
                
                <div class="space-y-2 max-h-80 overflow-y-auto">
                    @foreach($chatEvents as $event)
                        @php
                            $eventIcons = [
                                'page_view' => '👁️',
                                'widget_open' => '💬',
                                'widget_close' => '❌',
                                'message' => '✉️',
                                'product_view' => '🔍',
                                'product_click' => '👆',
                                'add_to_cart' => '🛒',
                                'checkout' => '💳',
                                'purchase' => '✅',
                                'checkout_success' => '🎉',
                            ];
                            $icon = $eventIcons[$event->event_type] ?? '📌';
                            
                            $eventLabels = [
                                'page_view' => 'Перегляд сторінки',
                                'widget_open' => 'Відкрив чат',
                                'widget_close' => 'Закрив чат',
                                'message' => 'Повідомлення',
                                'product_view' => 'Перегляд товару',
                                'product_click' => 'Клік на товар',
                                'add_to_cart' => 'Додав у кошик',
                                'checkout' => 'Оформлення',
                                'purchase' => 'Покупка',
                                'checkout_success' => 'Замовлення оформлено',
                            ];
                            $label = $eventLabels[$event->event_type] ?? $event->event_type;
                            
                            $bgColors = [
                                'purchase' => 'bg-green-50 border-green-200',
                                'checkout_success' => 'bg-green-50 border-green-200',
                                'add_to_cart' => 'bg-yellow-50 border-yellow-200',
                                'product_click' => 'bg-blue-50 border-blue-200',
                            ];
                            $bgColor = $bgColors[$event->event_type] ?? 'bg-gray-50 border-gray-100';
                        @endphp
                        
                        <div class="p-2 rounded-lg border {{ $bgColor }} text-xs">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex items-center gap-1.5 flex-1 min-w-0">
                                    <span>{{ $icon }}</span>
                                    <span class="font-medium text-gray-700">{{ $label }}</span>
                                </div>
                                <span class="text-gray-400 whitespace-nowrap">
                                    {{ \Carbon\Carbon::parse($event->created_at)->format('H:i:s') }}
                                </span>
                            </div>
                            
                            @if(isset($event->product) && $event->product)
                                <div class="mt-1.5 pl-5">
                                    <div class="flex items-center gap-2">
                                        <span class="text-gray-500">Товар:</span>
                                        @if($event->product->url)
                                            <a href="{{ $event->product->url }}" 
                                               target="_blank" 
                                               class="text-blue-600 hover:text-blue-800 hover:underline truncate"
                                               title="{{ $event->product->title }}">
                                                {{ Str::limit($event->product->title, 40) }}
                                            </a>
                                            <a href="{{ $event->product->url }}" 
                                               target="_blank"
                                               class="text-gray-400 hover:text-gray-600 flex-shrink-0"
                                               title="Відкрити на сайті">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                                </svg>
                                            </a>
                                        @else
                                            <span class="text-gray-700 truncate">{{ Str::limit($event->product->title, 40) }}</span>
                                        @endif
                                    </div>
                                    @if($event->product->article)
                                        <div class="text-gray-400 mt-0.5">арт. {{ $event->product->article }}</div>
                                    @endif
                                    @if($event->product_price)
                                        <div class="text-green-600 font-medium mt-0.5">₴{{ number_format($event->product_price, 0) }}</div>
                                    @endif
                                </div>
                            @elseif($event->product_article)
                                <div class="mt-1.5 pl-5 text-gray-500">
                                    арт. {{ $event->product_article }}
                                    @if($event->product_price)
                                        <span class="text-green-600">• ₴{{ number_format($event->product_price, 0) }}</span>
                                    @endif
                                </div>
                            @endif
                            
                            @if($event->page_url && trim(parse_url($event->page_url, PHP_URL_PATH) ?: '') !== '' && parse_url($event->page_url, PHP_URL_PATH) !== '/')
                                <div class="mt-1 pl-5 flex items-center gap-1">
                                    <a href="{{ $event->page_url }}" 
                                       target="_blank" 
                                       class="text-blue-500 hover:text-blue-700 hover:underline truncate text-xs"
                                       title="{{ $event->page_url }}">
                                        {{ parse_url($event->page_url, PHP_URL_PATH) }}
                                    </a>
                                    <a href="{{ $event->page_url }}" 
                                       target="_blank"
                                       class="text-gray-400 hover:text-gray-600 flex-shrink-0">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                        </svg>
                                    </a>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
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
        
        // Listen for clipboard copy events
        Livewire.on('clipboard-copy', ({ text }) => {
            // Try modern clipboard API first
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(() => {
                    showCopyNotification('✓ Звіт скопійовано');
                }).catch(err => {
                    console.error('Clipboard API failed:', err);
                    fallbackCopy(text);
                });
            } else {
                fallbackCopy(text);
            }
        });
        
        function showCopyNotification(message) {
            const notification = document.createElement('div');
            notification.className = 'fixed bottom-4 right-4 bg-green-600 text-white px-4 py-2 rounded-lg shadow-lg z-50';
            notification.textContent = message;
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 2000);
        }
        
        function fallbackCopy(text) {
            // Fallback using textarea
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            textarea.style.top = '0';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            
            try {
                const successful = document.execCommand('copy');
                if (successful) {
                    showCopyNotification('✓ Звіт скопійовано');
                } else {
                    alert('Не вдалося скопіювати. Текст:\n\n' + text.substring(0, 500) + '...');
                }
            } catch (err) {
                console.error('execCommand failed:', err);
                alert('Не вдалося скопіювати. Текст:\n\n' + text.substring(0, 500) + '...');
            }
            
            document.body.removeChild(textarea);
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

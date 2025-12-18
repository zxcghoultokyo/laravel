<div>
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
            @if($session->status === 'open')
            <button wire:click="markAsResolved" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
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

    <div class="grid grid-cols-3 gap-6">
        <!-- Messages Timeline -->
        <div class="col-span-2 space-y-4">
            @foreach($messages as $message)
            <div class="bg-white rounded-lg shadow-sm p-4 @if($message->role === 'user') border-l-4 border-blue-500 @else border-l-4 border-gray-300 @endif">
                <div class="flex items-start justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-1 text-xs font-medium rounded-full @if($message->role === 'user') bg-blue-100 text-blue-800 @else bg-gray-100 text-gray-800 @endif">
                            {{ $message->role }}
                        </span>
                        <span class="text-xs text-gray-500">{{ $message->created_at->format('H:i:s') }}</span>
                    </div>
                </div>

                <div class="prose prose-sm max-w-none">
                    <p class="text-gray-900">{{ $message->content }}</p>
                </div>

                @if($message->meta)
                <details class="mt-3">
                    <summary class="text-xs text-gray-500 cursor-pointer hover:text-gray-700">Системна інформація</summary>
                    <pre class="mt-2 text-xs bg-gray-50 p-2 rounded overflow-x-auto">{{ json_encode($message->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </details>
                @endif
            </div>
            @endforeach
        </div>

        <!-- Metadata Sidebar -->
        <div class="space-y-4">
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

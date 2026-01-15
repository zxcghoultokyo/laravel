<div wire:poll.10s class="dark:bg-gray-900">
    <!-- Header -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold text-gray-900">Діалоги</h2>
            <p class="mt-1 text-sm text-gray-500">Історії чатів з користувачами</p>
        </div>
        <button 
            wire:click="$refresh" 
            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center gap-2"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Оновити
        </button>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
        <div class="grid grid-cols-5 gap-4">
            <div>
                <input 
                    type="text" 
                    wire:model.live.debounce.300ms="search" 
                    placeholder="Пошук по сесії або запиту..."
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                >
            </div>
            <div>
                <select wire:model.live="statusFilter" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    <option value="all">Всі статуси</option>
                    <option value="open">Відкриті</option>
                    <option value="closed">Закриті</option>
                    <option value="flagged">Позначені</option>
                </select>
            </div>
            <div>
                <select wire:model.live="escalationFilter" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    <option value="all">Всі чати</option>
                    <option value="escalated">🚨 Потребують допомоги</option>
                    <option value="not_escalated">✅ Звичайні</option>
                </select>
            </div>
            <div>
                <select wire:model.live="intentFilter" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    <option value="all">Всі інтенти</option>
                    <option value="product_search">Пошук товару</option>
                    <option value="order_status">Статус замовлення</option>
                    <option value="faq">FAQ</option>
                    <option value="small_talk">Розмова</option>
                </select>
            </div>
            <div>
                <select wire:model.live="dateFilter" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    <option value="all">Всі періоди</option>
                    <option value="today">Сьогодні</option>
                    <option value="7days">Останні 7 днів</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Sessions Table -->
    <div class="bg-white rounded-lg shadow-sm overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Сесія</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Останній запит</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Інтент</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Повідомлень</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Статус</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Остання активність</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Дії</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($sessions as $session)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <a href="{{ route('admin.chats.show', $session->session_id) }}" class="text-xs font-mono text-blue-600 hover:underline break-all" title="Відкрити чат">
                            {{ $session->session_id }}
                        </a>
                    </td>
                    <td class="px-6 py-4">
                        <div class="text-sm text-gray-900 max-w-md truncate">{{ $session->last_user_query }}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @if($session->last_intent)
                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800">
                            {{ $session->last_intent }}
                        </span>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {{ $session->messages_count }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-1 text-xs font-medium rounded-full
                                @if($session->status === 'open') bg-green-100 text-green-800
                                @elseif($session->status === 'closed') bg-gray-100 text-gray-800
                                @else bg-red-100 text-red-800
                                @endif">
                                {{ ucfirst($session->status) }}
                            </span>
                            @if($session->needs_human)
                                <span class="px-2 py-1 text-xs font-bold rounded-full bg-orange-500 text-white animate-pulse" title="{{ $session->escalation_reason ?? 'Потрібна допомога оператора' }}">
                                    🚨 ЕСКАЛАЦІЯ
                                </span>
                            @endif
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {{ $session->last_message_at?->diffForHumans() }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        @if($session->status === 'open')
                        <button 
                            wire:click="markAsClosed('{{ $session->session_id }}')"
                            class="text-gray-600 hover:text-gray-900 mr-3"
                            title="Закрити"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                        </button>
                        @endif
                        <button 
                            wire:click="flagSession('{{ $session->session_id }}')"
                            class="text-yellow-600 hover:text-yellow-900"
                            title="Позначити"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9"/>
                            </svg>
                        </button>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-6 py-12 text-center text-sm text-gray-500">
                        Сесій не знайдено
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="mt-4">
        {{ $sessions->links() }}
    </div>
</div>

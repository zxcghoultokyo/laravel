<div>
    @section('title', 'Управління тенантами')

    <!-- Header -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Управління тенантами</h1>
        <p class="text-gray-600">Перегляд та управління всіма магазинами</p>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm p-4 border border-gray-100">
            <div class="text-2xl font-bold text-gray-900">{{ $stats['total'] }}</div>
            <div class="text-sm text-gray-500">Всього</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 border border-green-100">
            <div class="text-2xl font-bold text-green-600">{{ $stats['active'] }}</div>
            <div class="text-sm text-gray-500">Активних</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 border border-blue-100">
            <div class="text-2xl font-bold text-blue-600">{{ $stats['trial'] }}</div>
            <div class="text-sm text-gray-500">Тріал</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 border border-red-100">
            <div class="text-2xl font-bold text-red-600">{{ $stats['suspended'] }}</div>
            <div class="text-sm text-gray-500">Призупинено</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-xl shadow-sm p-4 mb-6 border border-gray-100">
        <div class="flex flex-wrap gap-4 items-center">
            <div class="flex-1 min-w-[200px]">
                <input type="text" 
                       wire:model.live.debounce.300ms="search"
                       placeholder="Пошук по назві, slug, домену..."
                       class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <select wire:model.live="statusFilter" class="px-4 py-2 border border-gray-200 rounded-lg">
                <option value="">Всі статуси</option>
                <option value="active">Активні</option>
                <option value="trial">Тріал</option>
                <option value="suspended">Призупинені</option>
            </select>
            <select wire:model.live="planFilter" class="px-4 py-2 border border-gray-200 rounded-lg">
                <option value="">Всі плани</option>
                @foreach($plans as $key => $plan)
                    <option value="{{ $key }}">{{ $plan['name'] ?? $key }}</option>
                @endforeach
            </select>
            <button wire:click="openCreateModal" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Додати
            </button>
        </div>
    </div>

    <!-- Flash Messages -->
    @if(session()->has('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 text-green-700 rounded-lg">
            {{ session('success') }}
        </div>
    @endif

    <!-- Table -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th wire:click="sortBy('name')" class="px-4 py-3 text-left text-sm font-semibold text-gray-700 cursor-pointer hover:bg-gray-100">
                        Назва
                        @if($sortBy === 'name')
                            <span class="ml-1">{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                        @endif
                    </th>
                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700">Власник</th>
                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700">План</th>
                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700">Статус</th>
                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700">Товари</th>
                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700">Чатів</th>
                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700">Тріал</th>
                    <th wire:click="sortBy('created_at')" class="px-4 py-3 text-left text-sm font-semibold text-gray-700 cursor-pointer hover:bg-gray-100">
                        Створено
                        @if($sortBy === 'created_at')
                            <span class="ml-1">{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                        @endif
                    </th>
                    <th class="px-4 py-3 text-right text-sm font-semibold text-gray-700">Дії</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($tenants as $tenant)
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.tenants.show', $tenant) }}" class="block hover:text-blue-600">
                                <div class="font-medium text-gray-900 hover:text-blue-600">{{ $tenant->name }}</div>
                                <div class="text-sm text-gray-500">{{ $tenant->slug }}</div>
                            </a>
                        </td>
                        <td class="px-4 py-3">
                            @if($tenant->owner)
                                <div class="text-sm">{{ $tenant->owner->name }}</div>
                                <div class="text-xs text-gray-500">{{ $tenant->owner->email }}</div>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 text-xs font-medium rounded-full 
                                {{ $tenant->subscription?->plan === 'enterprise' ? 'bg-purple-100 text-purple-700' : '' }}
                                {{ $tenant->subscription?->plan === 'pro' ? 'bg-blue-100 text-blue-700' : '' }}
                                {{ $tenant->subscription?->plan === 'starter' ? 'bg-green-100 text-green-700' : '' }}
                                {{ $tenant->subscription?->plan === 'trial' ? 'bg-gray-100 text-gray-700' : '' }}">
                                {{ ucfirst($tenant->subscription?->plan ?? 'trial') }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 text-xs font-medium rounded-full 
                                {{ $tenant->status === 'active' ? 'bg-green-100 text-green-700' : '' }}
                                {{ $tenant->status === 'trial' ? 'bg-blue-100 text-blue-700' : '' }}
                                {{ $tenant->status === 'suspended' ? 'bg-red-100 text-red-700' : '' }}">
                                {{ $tenant->status === 'active' ? 'Активний' : ($tenant->status === 'trial' ? 'Тріал' : 'Призупинено') }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            {{ number_format($tenant->products_count ?? 0) }}
                            @if($tenant->platform === 'horoshop')
                                @php
                                    $syncRunning = \Illuminate\Support\Facades\Cache::get("sync_running_{$tenant->id}", false);
                                @endphp
                                @if($syncRunning)
                                    <span class="inline-flex items-center text-xs text-blue-600">
                                        <svg class="animate-spin w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                        синхр...
                                    </span>
                                @endif
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            {{ $tenant->chat_sessions_count ?? 0 }}
                        </td>
                        <td class="px-4 py-3">
                            @if($tenant->trial_ends_at)
                                @if($tenant->trial_ends_at->isFuture())
                                    <div class="text-sm">
                                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-700">
                                            🟢 {{ $tenant->trial_ends_at->diffForHumans() }}
                                        </span>
                                        <div class="text-xs text-gray-500 mt-1">
                                            до {{ $tenant->trial_ends_at->format('d.m.Y H:i') }}
                                        </div>
                                    </div>
                                @else
                                    <div class="text-sm">
                                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-700">
                                            🔴 закінчився
                                        </span>
                                        <div class="text-xs text-gray-500 mt-1">
                                            {{ $tenant->trial_ends_at->format('d.m.Y') }}
                                        </div>
                                    </div>
                                @endif
                                <div class="flex gap-1 mt-1">
                                    <button wire:click="openExtendTrialModal({{ $tenant->id }})" class="text-xs px-2 py-0.5 bg-blue-100 text-blue-600 rounded hover:bg-blue-200" title="Продовжити">
                                        +
                                    </button>
                                    <button wire:click="removeTrial({{ $tenant->id }})" wire:confirm="Прибрати тріал для {{ $tenant->name }}?" class="text-xs px-2 py-0.5 bg-red-100 text-red-600 rounded hover:bg-red-200" title="Прибрати тріал">
                                        ✕
                                    </button>
                                </div>
                            @else
                                <div class="text-sm">
                                    <span class="text-gray-400">—</span>
                                    <div class="flex gap-1 mt-1">
                                        <button wire:click="quickAddTrial({{ $tenant->id }}, 7)" class="text-xs px-2 py-0.5 bg-green-100 text-green-600 rounded hover:bg-green-200" title="Тріал на 7 днів">
                                            +7д
                                        </button>
                                        <button wire:click="quickAddTrial({{ $tenant->id }}, 14)" class="text-xs px-2 py-0.5 bg-green-100 text-green-600 rounded hover:bg-green-200" title="Тріал на 14 днів">
                                            +14д
                                        </button>
                                        <button wire:click="quickAddTrial({{ $tenant->id }}, 30)" class="text-xs px-2 py-0.5 bg-green-100 text-green-600 rounded hover:bg-green-200" title="Тріал на 30 днів">
                                            +30д
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            {{ $tenant->created_at->format('d.m.Y') }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                {{-- View details --}}
                                <a href="{{ route('admin.tenants.show', $tenant) }}" class="p-2 text-gray-600 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition" title="Деталі">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </a>
                                
                                {{-- Sync controls --}}
                                @if($tenant->platform === 'horoshop' && !empty($tenant->platform_credentials))
                                    @php
                                        $syncRunning = \Illuminate\Support\Facades\Cache::get("sync_running_{$tenant->id}", false);
                                    @endphp
                                    @if($syncRunning)
                                        <button wire:click="cancelSync({{ $tenant->id }})" class="p-2 text-gray-600 hover:text-red-600 hover:bg-red-50 rounded-lg transition" title="Скасувати синхронізацію">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    @else
                                        <button wire:click="startSync({{ $tenant->id }})" class="p-2 text-gray-600 hover:text-green-600 hover:bg-green-50 rounded-lg transition" title="Запустити синхронізацію">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                        </button>
                                    @endif
                                    @if(($tenant->products_count ?? 0) > 0)
                                        <button wire:click="clearProducts({{ $tenant->id }})" wire:confirm="Видалити всі {{ $tenant->products_count }} товарів {{ $tenant->name }}?" class="p-2 text-gray-600 hover:text-orange-600 hover:bg-orange-50 rounded-lg transition" title="Очистити товари">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    @endif
                                @endif
                                
                                {{-- Edit --}}
                                <button wire:click="edit({{ $tenant->id }})" class="p-2 text-gray-600 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition" title="Редагувати">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </button>
                                
                                {{-- Suspend/Reactivate --}}
                                @if($tenant->status !== 'suspended')
                                    <button wire:click="suspend({{ $tenant->id }})" wire:confirm="Призупинити {{ $tenant->name }}?" class="p-2 text-gray-600 hover:text-red-600 hover:bg-red-50 rounded-lg transition" title="Призупинити">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                    </button>
                                @else
                                    <button wire:click="reactivate({{ $tenant->id }})" class="p-2 text-gray-600 hover:text-green-600 hover:bg-green-50 rounded-lg transition" title="Активувати">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </button>
                                @endif
                                
                                {{-- Reset usage --}}
                                <button wire:click="resetUsage({{ $tenant->id }})" wire:confirm="Скинути лічильники для {{ $tenant->name }}?" class="p-2 text-gray-600 hover:text-orange-600 hover:bg-orange-50 rounded-lg transition" title="Скинути лічильники">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-8 text-center text-gray-500">
                            Тенантів не знайдено
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        
        <div class="px-4 py-3 border-t border-gray-100">
            {{ $tenants->links() }}
        </div>
    </div>

    <!-- Edit Modal -->
    @if($editingTenantId)
        <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center" wire:click.self="cancelEdit">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-xl font-bold">Редагування тенанта</h2>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Назва</label>
                        <input type="text" wire:model="editForm.name" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Slug</label>
                        <input type="text" wire:model="editForm.slug" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Домен</label>
                        <input type="text" wire:model="editForm.domain" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="example.com">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Статус</label>
                            <select wire:model="editForm.status" class="w-full px-3 py-2 border border-gray-200 rounded-lg">
                                <option value="active">Активний</option>
                                <option value="trial">Тріал</option>
                                <option value="suspended">Призупинено</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">План</label>
                            <select wire:model="editForm.plan" class="w-full px-3 py-2 border border-gray-200 rounded-lg">
                                @foreach($plans as $key => $plan)
                                    <option value="{{ $key }}">{{ $plan['name'] ?? $key }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Ліміт повідомлень</label>
                            <input type="number" wire:model="editForm.messages_limit" min="0" max="2147483647" class="w-full px-3 py-2 border border-gray-200 rounded-lg">
                            <p class="text-xs text-gray-500 mt-1">0 = без ліміту</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Ліміт товарів</label>
                            <input type="number" wire:model="editForm.products_limit" min="0" max="2147483647" class="w-full px-3 py-2 border border-gray-200 rounded-lg">
                            <p class="text-xs text-gray-500 mt-1">0 = без ліміту</p>
                        </div>
                    </div>
                    
                    <!-- Trial settings -->
                    <div class="border-t border-gray-200 pt-4 mt-4">
                        <h3 class="font-medium text-gray-900 mb-3">🕐 Тріал період</h3>
                        <div class="flex items-center gap-3 mb-3">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" wire:model.live="editForm.has_trial" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="text-sm text-gray-700">Тріал активний</span>
                            </label>
                        </div>
                        @if($editForm['has_trial'] ?? false)
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Дата закінчення тріалу</label>
                                <input type="datetime-local" wire:model="editForm.trial_ends_at" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <div class="flex gap-2 mt-2">
                                    <button type="button" wire:click="$set('editForm.trial_ends_at', '{{ now()->addDays(7)->format('Y-m-d\\TH:i') }}')" class="text-xs px-2 py-1 bg-gray-100 text-gray-600 rounded hover:bg-gray-200">
                                        +7 днів
                                    </button>
                                    <button type="button" wire:click="$set('editForm.trial_ends_at', '{{ now()->addDays(14)->format('Y-m-d\\TH:i') }}')" class="text-xs px-2 py-1 bg-gray-100 text-gray-600 rounded hover:bg-gray-200">
                                        +14 днів
                                    </button>
                                    <button type="button" wire:click="$set('editForm.trial_ends_at', '{{ now()->addDays(30)->format('Y-m-d\\TH:i') }}')" class="text-xs px-2 py-1 bg-gray-100 text-gray-600 rounded hover:bg-gray-200">
                                        +30 днів
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
                <div class="p-6 border-t border-gray-200 flex justify-end gap-3">
                    <button wire:click="cancelEdit" class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition">
                        Скасувати
                    </button>
                    <button wire:click="update" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                        Зберегти
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Create Modal -->
    @if($showCreateModal)
        <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center" wire:click.self="closeCreateModal">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-xl font-bold">Новий тенант</h2>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Назва магазину *</label>
                        <input type="text" wire:model="createForm.name" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="Мій магазин">
                        @error('createForm.name') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Slug *</label>
                        <input type="text" wire:model="createForm.slug" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="my-store">
                        @error('createForm.slug') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Домен (опціонально)</label>
                        <input type="text" wire:model="createForm.domain" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="store.example.com">
                    </div>
                    <hr class="my-4">
                    <h3 class="font-medium text-gray-900">Власник акаунту</h3>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Ім'я *</label>
                        <input type="text" wire:model="createForm.owner_name" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="Іван Петренко">
                        @error('createForm.owner_name') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                        <input type="email" wire:model="createForm.owner_email" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="owner@example.com">
                        @error('createForm.owner_email') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Пароль</label>
                        <input type="text" wire:model="createForm.owner_password" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="Залиште порожнім для password123">
                    </div>
                </div>
                <div class="p-6 border-t border-gray-200 flex justify-end gap-3">
                    <button wire:click="closeCreateModal" class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition">
                        Скасувати
                    </button>
                    <button wire:click="create" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                        Створити
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Extend Trial Modal -->
    @if($extendTrialTenantId)
        @php
            $extendTenant = \App\Models\Tenant::find($extendTrialTenantId);
        @endphp
        <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center" wire:click.self="closeExtendTrialModal">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-xl font-bold">🕐 Продовжити тріал</h2>
                    <p class="text-sm text-gray-500 mt-1">{{ $extendTenant?->name }}</p>
                </div>
                <div class="p-6 space-y-4">
                    @if($extendTenant?->trial_ends_at)
                        <div class="p-3 bg-gray-50 rounded-lg">
                            <div class="text-sm text-gray-600">Поточна дата закінчення:</div>
                            <div class="font-medium {{ $extendTenant->trial_ends_at->isFuture() ? 'text-green-600' : 'text-red-600' }}">
                                {{ $extendTenant->trial_ends_at->format('d.m.Y H:i') }}
                                ({{ $extendTenant->trial_ends_at->diffForHumans() }})
                            </div>
                        </div>
                    @else
                        <div class="p-3 bg-yellow-50 rounded-lg text-yellow-700">
                            <span>⚠️</span> Тріал не активний. Буде додано від поточної дати.
                        </div>
                    @endif
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Продовжити на (днів)</label>
                        <input type="number" wire:model="extendTrialDays" min="1" max="365" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="$set('extendTrialDays', 3)" class="px-3 py-1 text-sm {{ $extendTrialDays == 3 ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }} rounded-lg hover:bg-blue-500 hover:text-white transition">
                            3 дні
                        </button>
                        <button type="button" wire:click="$set('extendTrialDays', 7)" class="px-3 py-1 text-sm {{ $extendTrialDays == 7 ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }} rounded-lg hover:bg-blue-500 hover:text-white transition">
                            7 днів
                        </button>
                        <button type="button" wire:click="$set('extendTrialDays', 14)" class="px-3 py-1 text-sm {{ $extendTrialDays == 14 ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }} rounded-lg hover:bg-blue-500 hover:text-white transition">
                            14 днів
                        </button>
                        <button type="button" wire:click="$set('extendTrialDays', 30)" class="px-3 py-1 text-sm {{ $extendTrialDays == 30 ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }} rounded-lg hover:bg-blue-500 hover:text-white transition">
                            30 днів
                        </button>
                        <button type="button" wire:click="$set('extendTrialDays', 60)" class="px-3 py-1 text-sm {{ $extendTrialDays == 60 ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }} rounded-lg hover:bg-blue-500 hover:text-white transition">
                            60 днів
                        </button>
                        <button type="button" wire:click="$set('extendTrialDays', 90)" class="px-3 py-1 text-sm {{ $extendTrialDays == 90 ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }} rounded-lg hover:bg-blue-500 hover:text-white transition">
                            90 днів
                        </button>
                    </div>
                    
                    @php
                        $baseDate = $extendTenant?->trial_ends_at && $extendTenant?->trial_ends_at->isFuture() 
                            ? $extendTenant->trial_ends_at 
                            : now();
                        $newEndDate = $baseDate->copy()->addDays($extendTrialDays);
                    @endphp
                    <div class="p-3 bg-blue-50 rounded-lg">
                        <div class="text-sm text-blue-600">Нова дата закінчення:</div>
                        <div class="font-bold text-blue-700">{{ $newEndDate->format('d.m.Y H:i') }}</div>
                    </div>
                </div>
                <div class="p-6 border-t border-gray-200 flex justify-end gap-3">
                    <button wire:click="closeExtendTrialModal" class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition">
                        Скасувати
                    </button>
                    <button wire:click="extendTrial" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                        Продовжити тріал
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

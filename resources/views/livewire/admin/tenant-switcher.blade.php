<div class="relative" x-data="{ open: @entangle('showDropdown') }">
    @if($isSuperAdmin)
        <button @click="open = !open" 
                class="flex items-center gap-2 px-3 py-2 text-sm font-medium rounded-lg transition
                       {{ $selectedTenantId 
                           ? 'bg-purple-100 text-purple-800 hover:bg-purple-200' 
                           : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
            @if($currentTenant)
                <span class="w-2 h-2 bg-purple-500 rounded-full"></span>
                <span>{{ $currentTenant->name }}</span>
            @else
                <span class="w-2 h-2 bg-gray-400 rounded-full"></span>
                <span>Всі тенанти</span>
            @endif
            <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="open" 
             @click.away="open = false"
             x-transition:enter="transition ease-out duration-100"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-75"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="absolute right-0 mt-2 w-64 bg-white rounded-lg shadow-lg border border-gray-200 z-50 overflow-hidden">
            
            <div class="p-2 border-b border-gray-100 bg-gray-50">
                <span class="text-xs font-medium text-gray-500 uppercase">Переключити тенанта</span>
            </div>
            
            <div class="max-h-80 overflow-y-auto">
                {{-- All tenants option --}}
                <button wire:click="selectTenant(null)" 
                        class="w-full flex items-center gap-3 px-4 py-2 text-sm hover:bg-gray-50 transition
                               {{ !$selectedTenantId ? 'bg-purple-50 text-purple-700' : 'text-gray-700' }}">
                    <span class="w-8 h-8 flex items-center justify-center rounded-full bg-gray-200 text-gray-600 text-xs font-bold">
                        ALL
                    </span>
                    <div class="text-left">
                        <div class="font-medium">Всі тенанти</div>
                        <div class="text-xs text-gray-500">Без фільтрації</div>
                    </div>
                    @if(!$selectedTenantId)
                        <svg class="w-5 h-5 text-purple-600 ml-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                    @endif
                </button>

                <div class="border-t border-gray-100"></div>

                {{-- Individual tenants --}}
                @foreach($tenants as $tenant)
                    <button wire:click="selectTenant({{ $tenant->id }})" 
                            class="w-full flex items-center gap-3 px-4 py-2 text-sm hover:bg-gray-50 transition
                                   {{ $selectedTenantId == $tenant->id ? 'bg-purple-50 text-purple-700' : 'text-gray-700' }}">
                        <span class="w-8 h-8 flex items-center justify-center rounded-full text-white text-xs font-bold
                                     {{ $tenant->plan === 'pro' || $tenant->plan === 'trial' ? 'bg-purple-500' : 'bg-blue-500' }}">
                            {{ strtoupper(substr($tenant->name, 0, 2)) }}
                        </span>
                        <div class="text-left flex-1 min-w-0">
                            <div class="font-medium truncate">{{ $tenant->name }}</div>
                            <div class="text-xs text-gray-500 truncate">{{ $tenant->domain ?? $tenant->slug }}</div>
                        </div>
                        <span class="px-1.5 py-0.5 text-xs rounded 
                                    {{ $tenant->plan === 'trial' ? 'bg-amber-100 text-amber-700' : '' }}
                                    {{ $tenant->plan === 'pro' ? 'bg-purple-100 text-purple-700' : '' }}
                                    {{ $tenant->plan === 'starter' ? 'bg-blue-100 text-blue-700' : '' }}">
                            {{ ucfirst($tenant->plan) }}
                        </span>
                        @if($selectedTenantId == $tenant->id)
                            <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>
    @endif
</div>
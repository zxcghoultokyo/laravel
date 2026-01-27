<!-- Step 3: Sync Products -->
{{-- Non-blocking: user can continue while sync runs in background --}}
<div class="text-center mb-8">
    <h3 class="text-2xl font-bold text-gray-900">Налаштування магазину</h3>
    <p class="mt-2 text-gray-600">Синхронізація, AI-аналіз та індексація йдуть у фоні</p>
</div>

<div class="max-w-lg mx-auto">
    @php
        $onboardingProgress = \App\Models\TenantOnboardingProgress::where('tenant_id', $tenant->id)->first();
    @endphp
    
    {{-- Always show progress component - it handles all states --}}
    <livewire:onboarding-progress :compact="false" />
    
    @if($productsCount > 0)
        {{-- Show stats if we have products --}}
        <div class="mt-6 p-4 bg-gray-50 rounded-xl">
            <div class="grid grid-cols-2 gap-4 text-center">
                <div class="p-3 bg-white rounded-lg">
                    <p class="text-2xl font-bold text-gray-900">{{ number_format($productsCount) }}</p>
                    <p class="text-sm text-gray-500">товарів</p>
                </div>
                <div class="p-3 bg-white rounded-lg">
                    <p class="text-2xl font-bold text-gray-900">{{ $categoriesCount }}</p>
                    <p class="text-sm text-gray-500">категорій</p>
                </div>
            </div>
        </div>
    @endif
    
    {{-- Info box about background processing --}}
    <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
        <div class="flex items-start gap-3">
            <svg class="w-5 h-5 text-blue-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <p class="text-blue-800 text-sm">
                <strong>Не чекайте!</strong> Процес триває у фоні. Ви можете продовжити налаштування — 
                прогрес буде видно на дашборді.
            </p>
        </div>
    </div>
</div>

<form method="POST" action="{{ route('onboarding.step3.save') }}" id="step3-form">
    @csrf
    <div class="flex justify-between mt-8">
        <a href="{{ route('onboarding.step2') }}" class="inline-flex items-center px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-md text-gray-700">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
            Назад
        </a>
        @php
            $onboardingProgress = \App\Models\TenantOnboardingProgress::where('tenant_id', $tenant->id)->first();
            $onboardingInProgress = $onboardingProgress && $onboardingProgress->status === 'in_progress';
            // User can ALWAYS continue - sync runs in background, don't block the wizard!
        @endphp
        <x-primary-button id="continue-btn">
            @if($onboardingInProgress)
                Продовжити
                <span class="ml-2 text-xs opacity-75">(sync у фоні)</span>
            @else
                Продовжити
            @endif
            <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
        </x-primary-button>
    </div>
</form>

{{-- No auto-refresh needed - Livewire handles polling, user can continue anytime --}}

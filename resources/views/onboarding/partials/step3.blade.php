<!-- Step 3: Sync Products -->
{{-- Updated: force recompile --}}
<div class="text-center mb-8">
    <h3 class="text-2xl font-bold text-gray-900">Синхронізація товарів</h3>
    <p class="mt-2 text-gray-600">Імпортуємо каталог та налаштовуємо пошук</p>
</div>

<div class="max-w-lg mx-auto">
    @if($productsCount > 0)
        {{-- Already synced - show compact progress or success --}}
        @php
            $onboardingProgress = \App\Models\TenantOnboardingProgress::where('tenant_id', $tenant->id)->first();
        @endphp
        
        @if($onboardingProgress && $onboardingProgress->status === 'in_progress')
            {{-- Onboarding in progress - show detailed progress --}}
            <livewire:onboarding-progress :compact="false" />
        @else
            {{-- Sync completed --}}
            <div id="sync-status" class="p-6 bg-gray-50 rounded-xl text-center">
                <div class="w-16 h-16 mx-auto mb-4 bg-green-100 rounded-full flex items-center justify-center">
                    <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                </div>
                <h4 class="font-semibold text-lg text-green-700">Синхронізовано!</h4>
                <div class="mt-4 grid grid-cols-2 gap-4 text-left">
                    <div class="p-3 bg-white rounded-lg">
                        <p class="text-2xl font-bold text-gray-900">{{ number_format($productsCount) }}</p>
                        <p class="text-sm text-gray-500">товарів</p>
                    </div>
                    <div class="p-3 bg-white rounded-lg">
                        <p class="text-2xl font-bold text-gray-900">{{ $categoriesCount }}</p>
                        <p class="text-sm text-gray-500">категорій</p>
                    </div>
                </div>
                
                @if($onboardingProgress && $onboardingProgress->status === 'completed')
                    <div class="mt-4 p-3 bg-green-50 rounded-lg">
                        <p class="text-sm text-green-700">
                            ✅ AI-аналіз та індексація завершені
                        </p>
                    </div>
                @endif
            </div>
        @endif
    @else
        {{-- No products yet - show onboarding progress component --}}
        <livewire:onboarding-progress :compact="false" />
    @endif

    @if($tenant->platform === 'manual')
        <div class="mt-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
            <p class="text-yellow-800 text-sm">
                <strong>Ручний режим:</strong> Ви зможете додати товари пізніше через панель адміністратора або завантажити CSV файл.
            </p>
        </div>
    @endif
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
            $canContinue = $productsCount > 0 || $tenant->platform === 'manual';
            $onboardingProgress = \App\Models\TenantOnboardingProgress::where('tenant_id', $tenant->id)->first();
            $onboardingInProgress = $onboardingProgress && $onboardingProgress->status === 'in_progress';
        @endphp
        <x-primary-button id="continue-btn" :disabled="!$canContinue || $onboardingInProgress">
            @if($onboardingInProgress)
                <svg class="animate-spin w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                Зачекайте...
            @else
                Продовжити
                <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>
            @endif
        </x-primary-button>
    </div>
</form>

@if($onboardingInProgress ?? false)
    {{-- Auto-refresh page while onboarding in progress --}}
    <script>
        setTimeout(function() {
            window.location.reload();
        }, 5000);
    </script>
@endif

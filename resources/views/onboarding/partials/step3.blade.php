<!-- Step 3: Sync Products -->
{{-- Updated: force recompile --}}
<div class="text-center mb-8">
    <h3 class="text-2xl font-bold text-gray-900">Синхронізація товарів</h3>
    <p class="mt-2 text-gray-600">Імпортуємо каталог з вашого магазину</p>
</div>

<div class="max-w-md mx-auto">
    <div id="sync-status" class="p-6 bg-gray-50 rounded-xl text-center">
        @if($productsCount > 0)
            <!-- Already synced -->
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
        @else
            <!-- Ready to sync -->
            <div id="sync-ready">
                <div class="w-16 h-16 mx-auto mb-4 bg-blue-100 rounded-full flex items-center justify-center">
                    <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                </div>
                <h4 class="font-semibold text-lg">Готово до синхронізації</h4>
                <p class="text-sm text-gray-500 mt-2">Це може зайняти кілька хвилин</p>
                <button type="button" id="start-sync-btn" class="mt-4 px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Почати синхронізацію
                </button>
            </div>

            <!-- Syncing -->
            <div id="sync-progress" class="hidden">
                <div class="w-16 h-16 mx-auto mb-4">
                    <svg class="animate-spin w-16 h-16 text-blue-600" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </div>
                <h4 class="font-semibold text-lg">Синхронізація...</h4>
                <p class="text-sm text-gray-500 mt-2">
                    Знайдено товарів: <span id="products-count">0</span>
                </p>
            </div>
        @endif
    </div>

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
        <x-primary-button id="continue-btn" :disabled="$productsCount === 0 && $tenant->platform !== 'manual'">
            Продовжити
            <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
        </x-primary-button>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const startBtn = document.getElementById('start-sync-btn');
    const readyDiv = document.getElementById('sync-ready');
    const progressDiv = document.getElementById('sync-progress');
    const productsSpan = document.getElementById('products-count');
    const continueBtn = document.getElementById('continue-btn');

    if (startBtn) {
        startBtn.addEventListener('click', function() {
            // Show progress
            readyDiv.classList.add('hidden');
            progressDiv.classList.remove('hidden');
            
            // Start sync
            fetch('{{ route("onboarding.step3.start") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            }).then(r => r.json()).then(data => {
                // Poll for status
                pollStatus();
            });
        });
    }

    function pollStatus() {
        fetch('{{ route("onboarding.step3.status") }}')
            .then(r => r.json())
            .then(data => {
                productsSpan.textContent = data.products;
                
                if (data.completed) {
                    // Reload page to show completed state
                    window.location.reload();
                } else {
                    // Continue polling
                    setTimeout(pollStatus, 2000);
                }
            });
    }
});
</script>

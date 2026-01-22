<!-- Step 5: Embed Code & Finish -->
<div class="text-center mb-8">
    <div class="w-20 h-20 mx-auto mb-4 bg-green-100 rounded-full flex items-center justify-center">
        <svg class="w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
    </div>
    <h3 class="text-2xl font-bold text-gray-900">Все готово! 🎉</h3>
    <p class="mt-2 text-gray-600">Залишилось додати код на ваш сайт</p>
</div>

<div class="max-w-2xl mx-auto">
    <!-- AI Enrichment Progress Banner -->
    <div id="enrichment-progress" class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
        <div class="flex items-center justify-between mb-2">
            <div class="flex items-center">
                <svg id="enrichment-spinner" class="animate-spin w-5 h-5 text-blue-600 mr-2" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <svg id="enrichment-check" class="hidden w-5 h-5 text-green-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <span class="font-medium text-blue-800" id="enrichment-title">🧠 AI обробляє ваші товари...</span>
            </div>
            <span class="text-sm font-semibold text-blue-700" id="enrichment-percent">0%</span>
        </div>
        <div class="w-full bg-blue-200 rounded-full h-2">
            <div id="enrichment-bar" class="bg-blue-600 h-2 rounded-full transition-all duration-500" style="width: 0%"></div>
        </div>
        <p class="mt-2 text-xs text-blue-600" id="enrichment-details">
            AI-аналіз: <span id="ai-count">0</span> | Пошуковий індекс: <span id="meili-count">0</span>
        </p>
    </div>

    <!-- Embed code -->
    <div class="mb-8">
        <label class="block text-sm font-medium text-gray-700 mb-2">
            Вставте цей код перед закриваючим тегом &lt;/body&gt;
        </label>
        <div class="relative">
            <pre class="p-4 bg-gray-900 text-green-400 rounded-lg text-sm overflow-x-auto"><code id="embed-code">{{ $embedCode }}</code></pre>
            <button type="button" 
                    id="copy-btn"
                    class="absolute top-2 right-2 px-3 py-1 bg-gray-700 hover:bg-gray-600 text-white text-sm rounded">
                📋 Копіювати
            </button>
        </div>
        <p id="copy-success" class="hidden mt-2 text-green-600 text-sm">✓ Скопійовано!</p>
    </div>

    <!-- Alternative: Send to developer -->
    <div class="p-4 bg-gray-50 rounded-lg mb-8">
        <h4 class="font-medium text-gray-900 mb-2">Або надішліть інструкцію розробнику</h4>
        <div class="flex space-x-2">
            <x-text-input type="email" 
                          id="developer-email" 
                          placeholder="developer@company.com"
                          class="flex-1" />
            <button type="button" 
                    id="send-email-btn"
                    class="px-4 py-2 bg-gray-200 hover:bg-gray-300 rounded-md text-gray-700">
                Надіслати
            </button>
        </div>
    </div>

    <!-- Trial info -->
    <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg mb-8">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <div class="ml-3">
                <h4 class="font-medium text-blue-800">Ваш тріал активний</h4>
                <p class="mt-1 text-sm text-blue-700">
                    У вас є <strong>14 днів</strong> безкоштовного використання з лімітом 
                    <strong>100 повідомлень</strong>. Потім оберіть план, що підходить вам.
                </p>
            </div>
        </div>
    </div>

    <!-- What's next -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="p-4 bg-white border rounded-lg">
            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center mb-3">
                <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
            </div>
            <h4 class="font-medium text-gray-900">Аналітика</h4>
            <p class="text-sm text-gray-500 mt-1">Слідкуйте за розмовами та конверсіями</p>
        </div>
        
        <div class="p-4 bg-white border rounded-lg">
            <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center mb-3">
                <svg class="w-5 h-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
            </div>
            <h4 class="font-medium text-gray-900">Налаштування</h4>
            <p class="text-sm text-gray-500 mt-1">Кастомізуйте поведінку бота</p>
        </div>
        
        <div class="p-4 bg-white border rounded-lg">
            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mb-3">
                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                </svg>
            </div>
            <h4 class="font-medium text-gray-900">Live Chat</h4>
            <p class="text-sm text-gray-500 mt-1">Підключайтесь до розмов вживу</p>
        </div>
    </div>
</div>

<form method="POST" action="{{ route('onboarding.complete') }}">
    @csrf
    <div class="flex justify-between mt-8">
        <a href="{{ route('onboarding.step4') }}" class="inline-flex items-center px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-md text-gray-700">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
            Назад
        </a>
        <x-primary-button class="px-6">
            Перейти в Dashboard
            <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
            </svg>
        </x-primary-button>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const copyBtn = document.getElementById('copy-btn');
    const embedCode = document.getElementById('embed-code');
    const copySuccess = document.getElementById('copy-success');

    copyBtn.addEventListener('click', function() {
        navigator.clipboard.writeText(embedCode.textContent).then(function() {
            copySuccess.classList.remove('hidden');
            copyBtn.textContent = '✓ Скопійовано';
            
            setTimeout(function() {
                copySuccess.classList.add('hidden');
                copyBtn.textContent = '📋 Копіювати';
            }, 2000);
        });
    });

    // AI Enrichment Progress Polling
    const progressBar = document.getElementById('enrichment-bar');
    const progressPercent = document.getElementById('enrichment-percent');
    const progressTitle = document.getElementById('enrichment-title');
    const progressDetails = document.getElementById('enrichment-details');
    const progressContainer = document.getElementById('enrichment-progress');
    const spinner = document.getElementById('enrichment-spinner');
    const checkIcon = document.getElementById('enrichment-check');
    const aiCount = document.getElementById('ai-count');
    const meiliCount = document.getElementById('meili-count');

    let pollInterval = null;
    let completedOnce = false;

    function updateProgress() {
        fetch('{{ route("onboarding.enrichment.progress") }}')
            .then(r => r.json())
            .then(data => {
                const percent = Math.round(data.overall_percent);
                progressBar.style.width = percent + '%';
                progressPercent.textContent = percent + '%';
                aiCount.textContent = data.ai_enrichment.completed + '/' + data.total_products;
                meiliCount.textContent = data.meili_indexing.completed + '/' + data.total_products;

                if (data.status === 'completed' && !completedOnce) {
                    completedOnce = true;
                    progressTitle.textContent = '✅ Всі товари оброблено!';
                    progressContainer.classList.remove('bg-blue-50', 'border-blue-200');
                    progressContainer.classList.add('bg-green-50', 'border-green-200');
                    progressBar.classList.remove('bg-blue-600');
                    progressBar.classList.add('bg-green-500');
                    spinner.classList.add('hidden');
                    checkIcon.classList.remove('hidden');
                    progressPercent.classList.remove('text-blue-700');
                    progressPercent.classList.add('text-green-700');
                    progressDetails.classList.remove('text-blue-600');
                    progressDetails.classList.add('text-green-600');
                    
                    // Stop polling when complete
                    if (pollInterval) {
                        clearInterval(pollInterval);
                        pollInterval = null;
                    }
                } else if (data.status === 'no_products') {
                    progressContainer.classList.add('hidden');
                    if (pollInterval) {
                        clearInterval(pollInterval);
                        pollInterval = null;
                    }
                }
            })
            .catch(err => console.error('Progress fetch error:', err));
    }

    // Initial check and start polling
    updateProgress();
    pollInterval = setInterval(updateProgress, 3000); // Poll every 3 seconds

    // Cleanup on page leave
    window.addEventListener('beforeunload', function() {
        if (pollInterval) clearInterval(pollInterval);
    });
});
</script>

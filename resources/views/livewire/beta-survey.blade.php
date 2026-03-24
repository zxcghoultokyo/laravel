<div class="min-h-screen bg-gradient-to-b from-emerald-50 to-white py-8 px-4">
    <div class="max-w-2xl mx-auto">

        @if ($submitted)
            {{-- Success State --}}
            <div class="bg-white rounded-2xl shadow-lg p-8 text-center">
                <div class="text-6xl mb-4">🎉</div>
                <h2 class="text-2xl font-bold text-gray-900 mb-2">Дякуємо за відповіді!</h2>
                <p class="text-gray-600">Ваш зворотний зв'язок дуже цінний для нас. Ми використаємо його, щоб зробити AI-чат ще кращим.</p>
            </div>
        @else
            {{-- Survey Header --}}
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-gray-900 mb-2">Зворотний зв'язок — AI-чат</h1>
                <p class="text-gray-600">{{ $tenant->name }}</p>
                <p class="text-sm text-gray-500 mt-2">~3 хвилини &middot; 10 питань</p>
            </div>

            <form wire:submit="submit" class="space-y-8">

                {{-- Q1: Overall Rating --}}
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h3 class="font-semibold text-gray-900 mb-4">1. Як загалом оцінюєте роботу AI-чату у вашому магазині? <span class="text-red-500">*</span></h3>
                    <div class="flex flex-wrap gap-3">
                        @foreach ([1 => 'Погано', 2 => 'Слабо', 3 => 'Нормально', 4 => 'Добре', 5 => 'Відмінно'] as $value => $label)
                            <button
                                type="button"
                                wire:click="$set('overall_rating', {{ $value }})"
                                class="px-4 py-2 rounded-lg border-2 text-sm font-medium transition-colors {{ $overall_rating === $value ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-gray-200 hover:border-gray-300 text-gray-700' }}"
                            >
                                {{ $value }} — {{ $label }}
                            </button>
                        @endforeach
                    </div>
                    @error('overall_rating') <p class="text-red-500 text-sm mt-2">{{ $message }}</p> @enderror
                </div>

                {{-- Q2: Recommendation Accuracy --}}
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h3 class="font-semibold text-gray-900 mb-4">2. Наскільки точно бот знаходить і рекомендує потрібні товари? <span class="text-red-500">*</span></h3>
                    <div class="space-y-2">
                        @foreach ([
                            'always' => 'Майже завжди знаходить те, що потрібно',
                            'mostly' => 'Частіше знаходить, ніж ні',
                            'half' => '50/50 — іноді влучає, іноді ні',
                            'rarely' => 'Часто показує не те',
                            'never' => 'Практично ніколи не знаходить потрібне',
                        ] as $value => $label)
                            <label class="flex items-center gap-3 p-3 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors {{ $recommendation_accuracy === $value ? 'bg-emerald-50' : '' }}">
                                <input type="radio" wire:model="recommendation_accuracy" value="{{ $value }}" class="text-emerald-500 focus:ring-emerald-500">
                                <span class="text-gray-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('recommendation_accuracy') <p class="text-red-500 text-sm mt-2">{{ $message }}</p> @enderror
                </div>

                {{-- Q3: Problems --}}
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h3 class="font-semibold text-gray-900 mb-4">3. Які проблеми ви помічали? (можна обрати кілька)</h3>
                    <div class="space-y-2">
                        @foreach ([
                            'out_of_stock' => 'Показує товари, яких немає в наявності',
                            'wrong_category' => 'Рекомендує не ті товари (не та категорія, вік тощо)',
                            'wrong_language' => 'Відповідає іншою мовою',
                            'hallucinations' => 'Вигадує ціни або характеристики',
                            'broken_links' => 'Генерує посилання, які не працюють',
                            'slow' => 'Відповідає занадто довго',
                            'no_ukrainian' => 'Не розуміє запити українською',
                            'repetitive' => 'Повторює одні й ті ж товари',
                            'none' => 'Не було помітних проблем',
                        ] as $value => $label)
                            <label class="flex items-center gap-3 p-3 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors">
                                <input type="checkbox" wire:model="problems" value="{{ $value }}" class="rounded text-emerald-500 focus:ring-emerald-500">
                                <span class="text-gray-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                {{-- Q4: Tone --}}
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h3 class="font-semibold text-gray-900 mb-4">4. Як оцінюєте стиль спілкування бота? <span class="text-red-500">*</span></h3>
                    <div class="space-y-2">
                        @foreach ([
                            'too_formal' => 'Занадто формальний / «роботизований»',
                            'normal' => 'Нормальний, прийнятний для магазину',
                            'friendly' => 'Дружній і приємний',
                            'too_casual' => 'Занадто неформальний / панібратський',
                            'no_opinion' => 'Не звертав(ла) увагу',
                        ] as $value => $label)
                            <label class="flex items-center gap-3 p-3 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors {{ $tone_feedback === $value ? 'bg-emerald-50' : '' }}">
                                <input type="radio" wire:model="tone_feedback" value="{{ $value }}" class="text-emerald-500 focus:ring-emerald-500">
                                <span class="text-gray-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('tone_feedback') <p class="text-red-500 text-sm mt-2">{{ $message }}</p> @enderror
                </div>

                {{-- Q5: Business Impact --}}
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h3 class="font-semibold text-gray-900 mb-4">5. Чи помітили ви зміни після підключення чату? (можна обрати кілька)</h3>
                    <div class="space-y-2">
                        @foreach ([
                            'less_direct' => 'Клієнти стали менше писати в Direct / месенджери',
                            'orders_via_chat' => 'Є замовлення, які прийшли через чат',
                            'positive_feedback' => 'Клієнти залишають позитивні відгуки про чат',
                            'complaints' => 'Клієнти скаржаться на чат',
                            'no_changes' => 'Поки не помітно жодних змін',
                            'too_early' => 'Ще рано оцінювати',
                        ] as $value => $label)
                            <label class="flex items-center gap-3 p-3 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors">
                                <input type="checkbox" wire:model="business_impact" value="{{ $value }}" class="rounded text-emerald-500 focus:ring-emerald-500">
                                <span class="text-gray-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                {{-- Q6: Best Feature --}}
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h3 class="font-semibold text-gray-900 mb-4">6. Що найбільше подобається в чаті? <span class="text-red-500">*</span></h3>
                    <div class="space-y-2">
                        @foreach ([
                            'fast_search' => 'Швидкий пошук товарів без копання в каталозі',
                            'age_category' => 'Рекомендації за віком / категорією',
                            'faq' => 'Відповіді на питання про доставку / оплату',
                            '24_7' => 'Працює 24/7 без менеджера',
                            'product_cards' => 'Картки товарів прямо в чаті',
                            'nothing' => 'Поки нічого не виділяю',
                        ] as $value => $label)
                            <label class="flex items-center gap-3 p-3 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors {{ $best_feature === $value ? 'bg-emerald-50' : '' }}">
                                <input type="radio" wire:model="best_feature" value="{{ $value }}" class="text-emerald-500 focus:ring-emerald-500">
                                <span class="text-gray-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('best_feature') <p class="text-red-500 text-sm mt-2">{{ $message }}</p> @enderror
                </div>

                {{-- Q7: Missing Feature --}}
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h3 class="font-semibold text-gray-900 mb-4">7. Якої функції вам найбільше не вистачає? <span class="text-red-500">*</span></h3>
                    <div class="space-y-2">
                        @foreach ([
                            'compare' => 'Порівняння товарів',
                            'gift' => 'Підбір подарунка за бюджетом',
                            'promo' => 'Знижки / промокоди через чат',
                            'checkout' => 'Оформлення замовлення прямо в чаті',
                            'messengers' => 'Підключення до Instagram / Telegram',
                            'satisfied' => 'Мене все влаштовує',
                        ] as $value => $label)
                            <label class="flex items-center gap-3 p-3 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors {{ $missing_feature === $value ? 'bg-emerald-50' : '' }}">
                                <input type="radio" wire:model="missing_feature" value="{{ $value }}" class="text-emerald-500 focus:ring-emerald-500">
                                <span class="text-gray-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('missing_feature') <p class="text-red-500 text-sm mt-2">{{ $message }}</p> @enderror
                </div>

                {{-- Q8: Willingness to Pay --}}
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h3 class="font-semibold text-gray-900 mb-4">8. За умови поточної якості, чи готові ви платити за чат після завершення бета-тесту? <span class="text-red-500">*</span></h3>
                    <div class="space-y-2">
                        @foreach ([
                            'ready' => 'Так, вже готовий(а) платити',
                            'after_fixes' => 'Так, якщо виправлять основні проблеми',
                            'need_time' => 'Можливо, потрібно більше часу для оцінки',
                            'no_value' => 'Ні, поки не бачу цінності',
                            'depends_price' => 'Залежить від ціни — яка вартість?',
                        ] as $value => $label)
                            <label class="flex items-center gap-3 p-3 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors {{ $willingness_to_pay === $value ? 'bg-emerald-50' : '' }}">
                                <input type="radio" wire:model="willingness_to_pay" value="{{ $value }}" class="text-emerald-500 focus:ring-emerald-500">
                                <span class="text-gray-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('willingness_to_pay') <p class="text-red-500 text-sm mt-2">{{ $message }}</p> @enderror
                </div>

                {{-- Q9: NPS --}}
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h3 class="font-semibold text-gray-900 mb-4">9. Наскільки ймовірно, що ви порекомендуєте цей чат іншому магазину? <span class="text-red-500">*</span></h3>
                    <div class="grid grid-cols-11 gap-1">
                        @for ($i = 0; $i <= 10; $i++)
                            <button
                                type="button"
                                wire:click="$set('nps_score', {{ $i }})"
                                class="aspect-square rounded-lg border-2 text-sm font-bold transition-colors
                                    {{ $nps_score === $i
                                        ? ($i <= 6 ? 'border-red-400 bg-red-50 text-red-700' : ($i <= 8 ? 'border-yellow-400 bg-yellow-50 text-yellow-700' : 'border-emerald-500 bg-emerald-50 text-emerald-700'))
                                        : 'border-gray-200 hover:border-gray-300 text-gray-600' }}"
                            >
                                {{ $i }}
                            </button>
                        @endfor
                    </div>
                    <div class="flex justify-between text-xs text-gray-400 mt-2 px-1">
                        <span>Точно ні</span>
                        <span>Обов'язково!</span>
                    </div>
                    @error('nps_score') <p class="text-red-500 text-sm mt-2">{{ $message }}</p> @enderror
                </div>

                {{-- Q10: Open Comment --}}
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h3 class="font-semibold text-gray-900 mb-4">10. Що б ви хотіли сказати нам?</h3>
                    <p class="text-sm text-gray-500 mb-3">Побажання, скарги, ідеї — все цінне!</p>
                    <textarea
                        wire:model="open_comment"
                        rows="4"
                        class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500 resize-none"
                        placeholder="Ваш коментар (необов'язково)..."
                    ></textarea>
                </div>

                {{-- Submit --}}
                <div class="text-center">
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        class="px-8 py-3 bg-emerald-500 hover:bg-emerald-600 text-white font-semibold rounded-xl shadow-lg shadow-emerald-500/25 transition-all disabled:opacity-50"
                    >
                        <span wire:loading.remove>Надіслати відповіді</span>
                        <span wire:loading>Надсилаємо...</span>
                    </button>
                </div>

            </form>
        @endif

        <div class="text-center mt-8 text-xs text-gray-400">
            Powered by <a href="https://aintento.laravel.cloud" class="text-emerald-500 hover:underline">AIntento</a>
        </div>
    </div>
</div>

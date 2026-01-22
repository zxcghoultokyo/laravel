<div class="max-w-4xl mx-auto">
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">🚿 Генератор тестових товарів</h1>
        <p class="mt-2 text-gray-600 dark:text-gray-400">Генерація CSV файлу з тестовими товарами сантехніки для імпорту в Horoshop</p>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">⚙️ Налаштування</h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Кількість товарів
                </label>
                <input 
                    type="number" 
                    wire:model.live="productCount" 
                    min="10" 
                    max="5000" 
                    step="10"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500"
                >
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Від 10 до 5000 товарів</p>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Тип магазину
                </label>
                <select 
                    wire:model="shopType"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500"
                >
                    <option value="plumbing">🚿 Сантехніка</option>
                </select>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Поки що доступна тільки сантехніка</p>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">📦 Категорії товарів (30 категорій)</h2>
        
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
            <div class="p-3 bg-blue-50 dark:bg-blue-900/30 rounded-lg text-sm">
                <span class="font-medium text-blue-700 dark:text-blue-300">🚿 Змішувачі</span>
                <p class="text-blue-600 dark:text-blue-400 text-xs mt-1">Кухня, ванна, умивальник</p>
            </div>
            <div class="p-3 bg-purple-50 dark:bg-purple-900/30 rounded-lg text-sm">
                <span class="font-medium text-purple-700 dark:text-purple-300">🚽 Унітази</span>
                <p class="text-purple-600 dark:text-purple-400 text-xs mt-1">Підлогові, підвісні</p>
            </div>
            <div class="p-3 bg-green-50 dark:bg-green-900/30 rounded-lg text-sm">
                <span class="font-medium text-green-700 dark:text-green-300">🪥 Раковини</span>
                <p class="text-green-600 dark:text-green-400 text-xs mt-1">Накладні, вбудовані</p>
            </div>
            <div class="p-3 bg-cyan-50 dark:bg-cyan-900/30 rounded-lg text-sm">
                <span class="font-medium text-cyan-700 dark:text-cyan-300">🛁 Ванни</span>
                <p class="text-cyan-600 dark:text-cyan-400 text-xs mt-1">Акрилові, чавунні</p>
            </div>
            <div class="p-3 bg-teal-50 dark:bg-teal-900/30 rounded-lg text-sm">
                <span class="font-medium text-teal-700 dark:text-teal-300">🚿 Душові кабіни</span>
                <p class="text-teal-600 dark:text-teal-400 text-xs mt-1">Квадратні, кутові</p>
            </div>
            <div class="p-3 bg-amber-50 dark:bg-amber-900/30 rounded-lg text-sm">
                <span class="font-medium text-amber-700 dark:text-amber-300">🪞 Меблі</span>
                <p class="text-amber-600 dark:text-amber-400 text-xs mt-1">Тумби, дзеркала</p>
            </div>
            <div class="p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg text-sm">
                <span class="font-medium text-gray-700 dark:text-gray-300">🔧 Труби</span>
                <p class="text-gray-600 dark:text-gray-400 text-xs mt-1">ППР, металопластик</p>
            </div>
            <div class="p-3 bg-red-50 dark:bg-red-900/30 rounded-lg text-sm">
                <span class="font-medium text-red-700 dark:text-red-300">🔥 Водонагрівачі</span>
                <p class="text-red-600 dark:text-red-400 text-xs mt-1">Бойлери, проточні</p>
            </div>
            <div class="p-3 bg-orange-50 dark:bg-orange-900/30 rounded-lg text-sm">
                <span class="font-medium text-orange-700 dark:text-orange-300">♨️ Радіатори</span>
                <p class="text-orange-600 dark:text-orange-400 text-xs mt-1">Сталеві, біметал</p>
            </div>
            <div class="p-3 bg-lime-50 dark:bg-lime-900/30 rounded-lg text-sm">
                <span class="font-medium text-lime-700 dark:text-lime-300">💧 Насоси</span>
                <p class="text-lime-600 dark:text-lime-400 text-xs mt-1">Циркуляційні, дренажні</p>
            </div>
            <div class="p-3 bg-sky-50 dark:bg-sky-900/30 rounded-lg text-sm">
                <span class="font-medium text-sky-700 dark:text-sky-300">💦 Фільтри</span>
                <p class="text-sky-600 dark:text-sky-400 text-xs mt-1">Проточні, осмос</p>
            </div>
            <div class="p-3 bg-indigo-50 dark:bg-indigo-900/30 rounded-lg text-sm">
                <span class="font-medium text-indigo-700 dark:text-indigo-300">🔩 Інсталяції</span>
                <p class="text-indigo-600 dark:text-indigo-400 text-xs mt-1">Geberit, Grohe</p>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">📄 Формат CSV для Horoshop</h2>
        
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="bg-gray-100 dark:bg-gray-700">
                        <th class="px-3 py-2 text-left text-gray-700 dark:text-gray-300">Поле</th>
                        <th class="px-3 py-2 text-left text-gray-700 dark:text-gray-300">Опис</th>
                        <th class="px-3 py-2 text-left text-gray-700 dark:text-gray-300">Приклад</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                    <tr>
                        <td class="px-3 py-2 font-mono text-blue-600 dark:text-blue-400">article</td>
                        <td class="px-3 py-2 text-gray-600 dark:text-gray-400">Артикул товару</td>
                        <td class="px-3 py-2 text-gray-500">ZMK-00001</td>
                    </tr>
                    <tr>
                        <td class="px-3 py-2 font-mono text-blue-600 dark:text-blue-400">title</td>
                        <td class="px-3 py-2 text-gray-600 dark:text-gray-400">Назва товару</td>
                        <td class="px-3 py-2 text-gray-500">Змішувач кухонний Grohe</td>
                    </tr>
                    <tr>
                        <td class="px-3 py-2 font-mono text-blue-600 dark:text-blue-400">price</td>
                        <td class="px-3 py-2 text-gray-600 dark:text-gray-400">Ціна</td>
                        <td class="px-3 py-2 text-gray-500">2500</td>
                    </tr>
                    <tr>
                        <td class="px-3 py-2 font-mono text-blue-600 dark:text-blue-400">category</td>
                        <td class="px-3 py-2 text-gray-600 dark:text-gray-400">Категорія</td>
                        <td class="px-3 py-2 text-gray-500">Змішувачі/Для кухні</td>
                    </tr>
                    <tr>
                        <td class="px-3 py-2 font-mono text-blue-600 dark:text-blue-400">brand</td>
                        <td class="px-3 py-2 text-gray-600 dark:text-gray-400">Бренд</td>
                        <td class="px-3 py-2 text-gray-500">Grohe</td>
                    </tr>
                    <tr>
                        <td class="px-3 py-2 font-mono text-blue-600 dark:text-blue-400">description</td>
                        <td class="px-3 py-2 text-gray-600 dark:text-gray-400">Опис</td>
                        <td class="px-3 py-2 text-gray-500">Змішувач від бренду Grohe...</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            Роздільник: <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">;</code> (крапка з комою)
            | Кодування: <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">UTF-8 with BOM</code>
        </p>
    </div>

    <div class="flex flex-col gap-4">
        <button 
            wire:click="downloadCsv"
            class="w-full px-6 py-4 bg-gradient-to-r from-green-600 to-emerald-600 text-white font-semibold rounded-lg hover:from-green-700 hover:to-emerald-700 transition-all shadow-lg flex items-center justify-center gap-3 text-lg"
        >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            Завантажити CSV ({{ number_format($productCount) }} товарів)
        </button>
    </div>

    <div class="mt-6 p-4 bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-700 rounded-lg">
        <h3 class="font-semibold text-blue-800 dark:text-blue-200 mb-2">📋 Інструкція по імпорту в Horoshop:</h3>
        <ol class="list-decimal list-inside text-sm text-blue-700 dark:text-blue-300 space-y-1">
            <li>Натисніть кнопку "Завантажити CSV" вище</li>
            <li>Увійдіть в адмін-панель Horoshop</li>
            <li>Перейдіть в <strong>Каталог → Імпорт товарів</strong></li>
            <li>Виберіть завантажений CSV файл</li>
            <li>Вкажіть роздільник: <code class="bg-blue-100 dark:bg-blue-800 px-1 rounded">;</code></li>
            <li>Зіставте поля CSV з полями Horoshop</li>
            <li>Запустіть імпорт</li>
        </ol>
    </div>
    
    <div class="mt-4 p-4 bg-yellow-50 dark:bg-yellow-900/30 border border-yellow-200 dark:border-yellow-700 rounded-lg">
        <h3 class="font-semibold text-yellow-800 dark:text-yellow-200 mb-2">💡 Примітка:</h3>
        <p class="text-sm text-yellow-700 dark:text-yellow-300">
            Файл генерується "на льоту" і завантажується напряму. 
            Для 1000 товарів це займає ~1-2 секунди.
            Зображення використовують placeholder-и, які можна замінити після імпорту.
        </p>
    </div>
</div>

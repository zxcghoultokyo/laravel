<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 - Сторінку не знайдено | Aintento</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            25% { transform: translateY(-10px) rotate(-5deg); }
            75% { transform: translateY(-10px) rotate(5deg); }
        }
        .float-animation {
            animation: float 3s ease-in-out infinite;
        }
        @keyframes wander {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(30px); }
            75% { transform: translateX(-30px); }
        }
        .wander-animation {
            animation: wander 4s ease-in-out infinite;
        }
        @keyframes blink {
            0%, 90%, 100% { opacity: 1; }
            95% { opacity: 0; }
        }
        .blink-animation {
            animation: blink 3s ease-in-out infinite;
        }
        @keyframes pulse-slow {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        .pulse-slow {
            animation: pulse-slow 2s ease-in-out infinite;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-900 via-blue-900 to-slate-900 min-h-screen flex items-center justify-center p-4">
    <div class="text-center max-w-lg">
        <!-- Animated Robot Icon -->
        <div class="float-animation mb-8">
            <div class="text-8xl mb-4 wander-animation">🤖</div>
        </div>
        
        <!-- Error Code -->
        <h1 class="text-9xl font-black text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-cyan-600 mb-4">
            404
        </h1>
        
        <!-- Title -->
        <h2 class="text-3xl font-bold text-white mb-4">
            Ой! Заблукав... <span class="blink-animation">🧭</span>
        </h2>
        
        <!-- Description -->
        <p class="text-gray-300 text-lg mb-2">
            Цієї сторінки не існує (або ми її загубили).
        </p>
        <p class="text-gray-400 mb-8">
            Можливо, AI-бот з'їв цю сторінку... Він іноді такий голодний на дані! 🍽️
        </p>
        
        <!-- Fun Suggestions -->
        <div class="bg-white/5 rounded-xl p-4 mb-8 border border-white/10 text-left">
            <p class="text-gray-300 mb-2 font-semibold">Що можна зробити:</p>
            <ul class="text-gray-400 space-y-2">
                <li>🔍 Перевірте URL — може, є друкарська помилка?</li>
                <li>🏠 Поверніться на головну і почніть спочатку</li>
                <li>💬 Напишіть нашому AI-боту — він допоможе!</li>
                <li>☕ Випийте кави і спробуйте ще раз</li>
            </ul>
        </div>
        
        <!-- Action Buttons -->
        <div class="flex flex-col sm:flex-row gap-4 justify-center">
            <a href="{{ url('/dashboard') }}" 
               class="inline-flex items-center justify-center px-6 py-3 bg-gradient-to-r from-blue-600 to-cyan-600 text-white font-semibold rounded-lg hover:from-blue-700 hover:to-cyan-700 transition-all transform hover:scale-105 shadow-lg">
                <span class="mr-2">📊</span> До дашборду
            </a>
            <a href="{{ url('/') }}" 
               class="inline-flex items-center justify-center px-6 py-3 bg-white/10 text-white font-semibold rounded-lg hover:bg-white/20 transition-all border border-white/20">
                <span class="mr-2">🏠</span> На головну
            </a>
        </div>
        
        <!-- Easter Egg -->
        <div class="mt-8">
            <button onclick="this.innerHTML='🎉 Ви знайшли Easter Egg! Але сторінка все одно 404 😅'" 
                    class="text-gray-500 hover:text-gray-300 transition-colors text-sm cursor-pointer">
                Натисни, якщо тобі сумно 😢
            </button>
        </div>
        
        <!-- Branding -->
        <div class="mt-8 pulse-slow">
            <p class="text-gray-500 text-sm">
                Powered by <span class="text-blue-400 font-semibold">Aintento AI</span> 🤖
            </p>
        </div>
    </div>
</body>
</html>

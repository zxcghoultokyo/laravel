<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>500 - Серверна помилка | Aintento</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @keyframes spin-slow {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .spin-slow {
            animation: spin-slow 10s linear infinite;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-3px); }
            20%, 40%, 60%, 80% { transform: translateX(3px); }
        }
        .shake-animation {
            animation: shake 0.5s ease-in-out infinite;
        }
        @keyframes pulse-slow {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        .pulse-slow {
            animation: pulse-slow 2s ease-in-out infinite;
        }
        @keyframes glitch {
            0%, 100% { text-shadow: 2px 0 #ff0040, -2px 0 #00ff88; }
            25% { text-shadow: -2px 0 #ff0040, 2px 0 #00ff88; }
            50% { text-shadow: 2px 2px #ff0040, -2px -2px #00ff88; }
            75% { text-shadow: -2px 2px #ff0040, 2px -2px #00ff88; }
        }
        .glitch {
            animation: glitch 0.3s infinite;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-900 via-orange-900 to-slate-900 min-h-screen flex items-center justify-center p-4">
    <div class="text-center max-w-lg">
        <!-- Animated Icon -->
        <div class="mb-8">
            <div class="text-8xl mb-4 shake-animation">🔥</div>
            <div class="text-4xl spin-slow inline-block">⚙️</div>
        </div>
        
        <!-- Error Code -->
        <h1 class="text-9xl font-black text-transparent bg-clip-text bg-gradient-to-r from-orange-400 to-red-600 mb-4 glitch">
            500
        </h1>
        
        <!-- Title -->
        <h2 class="text-3xl font-bold text-white mb-4">
            Щось пішло не так! 💥
        </h2>
        
        <!-- Description -->
        <p class="text-gray-300 text-lg mb-2">
            Сервер вирішив взяти перерву на каву.
        </p>
        <p class="text-gray-400 mb-8">
            Наші AI-роботи вже працюють над проблемою! Вони не сплять, не їдять, тільки фіксять баги 🤖🔧
        </p>
        
        <!-- Status -->
        <div class="bg-white/5 rounded-xl p-4 mb-8 border border-orange-500/30">
            <div class="flex items-center justify-center gap-3 mb-2">
                <div class="w-3 h-3 bg-orange-500 rounded-full animate-pulse"></div>
                <span class="text-orange-400 font-semibold">Статус: Робимо магію...</span>
            </div>
            <p class="text-gray-400 text-sm">
                Зазвичай це займає кілька хвилин. Якщо проблема не зникає — напишіть нам!
            </p>
        </div>
        
        <!-- Action Buttons -->
        <div class="flex flex-col sm:flex-row gap-4 justify-center">
            <button onclick="window.location.reload()" 
               class="inline-flex items-center justify-center px-6 py-3 bg-gradient-to-r from-orange-600 to-red-600 text-white font-semibold rounded-lg hover:from-orange-700 hover:to-red-700 transition-all transform hover:scale-105 shadow-lg">
                <span class="mr-2">🔄</span> Спробувати ще раз
            </button>
            <a href="{{ url('/') }}" 
               class="inline-flex items-center justify-center px-6 py-3 bg-white/10 text-white font-semibold rounded-lg hover:bg-white/20 transition-all border border-white/20">
                <span class="mr-2">🏠</span> На головну
            </a>
        </div>
        
        <!-- Contact -->
        <div class="mt-8 text-gray-500 text-sm">
            <p>Проблема не зникає? Напишіть нам: 
                <a href="mailto:support@aintento.com" class="text-orange-400 hover:text-orange-300">support@aintento.com</a>
            </p>
        </div>
        
        <!-- Branding -->
        <div class="mt-8 pulse-slow">
            <p class="text-gray-500 text-sm">
                Powered by <span class="text-orange-400 font-semibold">Aintento AI</span> 🤖
            </p>
        </div>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>403 - Forbidden | Aintento</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        .shake-animation {
            animation: shake 0.8s ease-in-out;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(-5deg); }
            50% { transform: translateY(-15px) rotate(5deg); }
        }
        .float-animation {
            animation: float 4s ease-in-out infinite;
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
<body class="bg-gradient-to-br from-slate-900 via-red-900 to-slate-900 min-h-screen flex items-center justify-center p-4">
    <div class="text-center max-w-lg">
        <!-- Animated Icon -->
        <div class="float-animation mb-8">
            <div class="text-8xl mb-4">🚫</div>
        </div>
        
        <!-- Error Code -->
        <h1 class="text-9xl font-black text-transparent bg-clip-text bg-gradient-to-r from-red-400 to-orange-600 mb-4">
            403
        </h1>
        
        <!-- Title -->
        <h2 class="text-3xl font-bold text-white mb-4">
            Сюди не можна! 🙅‍♂️
        </h2>
        
        <!-- Description -->
        <p class="text-gray-300 text-lg mb-2">
            Доступ до цієї сторінки заборонено.
        </p>
        <p class="text-gray-400 mb-8">
            Навіть AI не може вам тут допомогти — це територія з підвищеною секретністю! 🔒
        </p>
        
        <!-- Fun Message -->
        <div class="bg-white/5 rounded-xl p-4 mb-8 border border-white/10">
            <p class="text-gray-300 italic">
                "Навіть наш AI-бот не має тут доступу... а він знає майже все!" 
                <span class="text-red-400">— Aintento Security Team</span>
            </p>
        </div>
        
        <!-- Action Buttons -->
        <div class="flex flex-col sm:flex-row gap-4 justify-center">
            <a href="{{ url('/dashboard') }}" 
               class="inline-flex items-center justify-center px-6 py-3 bg-gradient-to-r from-red-600 to-orange-600 text-white font-semibold rounded-lg hover:from-red-700 hover:to-orange-700 transition-all transform hover:scale-105 shadow-lg">
                <span class="mr-2">📊</span> До дашборду
            </a>
            <a href="{{ url('/') }}" 
               class="inline-flex items-center justify-center px-6 py-3 bg-white/10 text-white font-semibold rounded-lg hover:bg-white/20 transition-all border border-white/20">
                <span class="mr-2">🏠</span> На головну
            </a>
        </div>
        
        <!-- Contact -->
        <div class="mt-8 text-gray-500 text-sm">
            <p>Потрібна допомога? Напишіть у Telegram: 
                <a href="https://t.me/AIntento" target="_blank" class="text-red-400 hover:text-red-300">@AIntento</a>
            </p>
        </div>
        
        <!-- Branding -->
        <div class="mt-4 pulse-slow">
            <p class="text-gray-500 text-sm">
                Powered by <span class="text-red-400 font-semibold">Aintento AI</span> 🤖
            </p>
        </div>
    </div>
</body>
</html>

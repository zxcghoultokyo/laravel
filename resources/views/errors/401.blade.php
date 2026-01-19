<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>401 - Unauthorized | Aintento</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }
        .float-animation {
            animation: float 3s ease-in-out infinite;
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
<body class="bg-gradient-to-br from-slate-900 via-purple-900 to-slate-900 min-h-screen flex items-center justify-center p-4">
    <div class="text-center max-w-lg">
        <!-- Animated Icon -->
        <div class="float-animation mb-8">
            <div class="text-8xl mb-4">🔐</div>
        </div>
        
        <!-- Error Code -->
        <h1 class="text-9xl font-black text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-pink-600 mb-4">
            401
        </h1>
        
        <!-- Title -->
        <h2 class="text-3xl font-bold text-white mb-4">
            Хто тут? 🕵️
        </h2>
        
        <!-- Description -->
        <p class="text-gray-300 text-lg mb-2">
            Ви не авторизовані, щоб побачити цю сторінку.
        </p>
        <p class="text-gray-400 mb-8">
            Це як прийти на VIP-вечірку без запрошення — охорона не пропустить! 🎭
        </p>
        
        <!-- Action Buttons -->
        <div class="flex flex-col sm:flex-row gap-4 justify-center">
            <a href="{{ url('/login') }}" 
               class="inline-flex items-center justify-center px-6 py-3 bg-gradient-to-r from-purple-600 to-pink-600 text-white font-semibold rounded-lg hover:from-purple-700 hover:to-pink-700 transition-all transform hover:scale-105 shadow-lg">
                <span class="mr-2">🔑</span> Увійти в систему
            </a>
            <a href="{{ url('/') }}" 
               class="inline-flex items-center justify-center px-6 py-3 bg-white/10 text-white font-semibold rounded-lg hover:bg-white/20 transition-all border border-white/20">
                <span class="mr-2">🏠</span> На головну
            </a>
        </div>
        
        <!-- Branding -->
        <div class="mt-12 pulse-slow">
            <p class="text-gray-500 text-sm">
                Powered by <span class="text-purple-400 font-semibold">Aintento AI</span> 🤖
            </p>
        </div>
    </div>
</body>
</html>

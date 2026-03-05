<!DOCTYPE html>
<html lang="uk">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Contractor Login</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }
        </style>
    </head>
    <body class="font-sans antialiased bg-gray-100 flex items-center justify-center min-h-screen">
        <div class="w-full max-w-sm">
            <div class="bg-white rounded-lg shadow-md p-8">
                <h1 class="text-xl font-semibold text-gray-800 mb-6 text-center">📦 Contractor Panel</h1>

                @if ($errors->any())
                    <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded text-sm text-red-600">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('contractor.login.submit') }}">
                    @csrf
                    <div class="mb-4">
                        <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Логін</label>
                        <input type="text" id="username" name="username" value="{{ old('username') }}"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent"
                               required autofocus>
                    </div>
                    <div class="mb-6">
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Пароль</label>
                        <input type="password" id="password" name="password"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent"
                               required>
                    </div>
                    <button type="submit"
                            class="w-full bg-emerald-600 text-white py-2 px-4 rounded-md hover:bg-emerald-700 transition font-medium">
                        Увійти
                    </button>
                </form>
            </div>
        </div>
    </body>
</html>

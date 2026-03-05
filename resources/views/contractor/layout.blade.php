<!DOCTYPE html>
<html lang="uk">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Contractor Panel — Розетка</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
        <style>
            body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }
        </style>
    </head>
    <body class="font-sans antialiased bg-gray-50">
        <nav class="bg-white border-b border-gray-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-14">
                    <div class="flex items-center space-x-4">
                        <span class="text-lg font-semibold text-gray-800">📦 Contractor Panel</span>
                        <a href="{{ route('contractor.rozetka.index') }}"
                           class="text-sm font-medium text-emerald-600 hover:text-emerald-800">
                            Розетка
                        </a>
                    </div>
                    <div class="flex items-center">
                        <span class="text-sm text-gray-500 mr-4">{{ session('contractor_username', 'contractor') }}</span>
                        <form method="POST" action="{{ route('contractor.logout') }}">
                            @csrf
                            <button type="submit" class="text-sm text-red-500 hover:text-red-700">Вийти</button>
                        </form>
                    </div>
                </div>
            </div>
        </nav>
        <main class="py-4">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                {{ $slot }}
            </div>
        </main>
        @livewireScripts
    </body>
</html>

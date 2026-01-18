<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'AIntento') }} — AI Асистент для e-commerce</title>

        <!-- Fonts (same as landing) -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|space-grotesk:600,700" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        
        <style>
            :root {
                --primary: #10b981;
                --primary-dark: #059669;
                --primary-light: #d1fae5;
                --secondary: #065f46;
                --bg-light: #f0fdf4;
            }
            
            body {
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
                background: linear-gradient(180deg, var(--bg-light) 0%, #ffffff 100%);
                min-height: 100vh;
            }
            
            .logo-text {
                font-family: 'Space Grotesk', sans-serif;
                font-size: 28px;
                font-weight: 700;
                color: var(--primary);
                text-decoration: none;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .logo-text:hover {
                color: var(--primary-dark);
            }
            
            .auth-card {
                background: white;
                border-radius: 20px;
                box-shadow: 0 25px 80px rgba(16, 185, 129, 0.12);
                border: 1px solid var(--primary-light);
            }
            
            /* Override Tailwind primary colors */
            .bg-gray-100 {
                background: transparent !important;
            }
            
            /* Primary button style */
            button[type="submit"], .btn-primary {
                background: var(--primary) !important;
                border-color: var(--primary) !important;
            }
            
            button[type="submit"]:hover, .btn-primary:hover {
                background: var(--primary-dark) !important;
                border-color: var(--primary-dark) !important;
            }
            
            /* Focus rings */
            input:focus {
                border-color: var(--primary) !important;
                --tw-ring-color: var(--primary-light) !important;
            }
            
            /* Links */
            a.text-gray-600:hover, a.underline:hover {
                color: var(--primary) !important;
            }
            
            /* Trust badge */
            .trust-badge {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 6px 12px;
                background: var(--primary-light);
                border-radius: 20px;
                font-size: 12px;
                font-weight: 500;
                color: var(--secondary);
            }
        </style>
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0">
            <!-- Logo -->
            <div class="mb-2">
                <a href="/" class="logo-text">
                    🤖 AIntento
                </a>
            </div>
            
            <!-- Trust badge -->
            <div class="mb-6">
                <span class="trust-badge">
                    🇺🇦 Зроблено в Україні
                </span>
            </div>

            <!-- Auth Card -->
            <div class="w-full sm:max-w-md px-6 py-8 auth-card">
                {{ $slot }}
            </div>
            
            <!-- Footer links -->
            <div class="mt-6 text-center text-sm text-gray-500">
                <a href="/" class="hover:text-emerald-600">← На головну</a>
                <span class="mx-2">•</span>
                <a href="https://t.me/AIntento" target="_blank" class="hover:text-emerald-600">💬 Підтримка</a>
            </div>
        </div>
    </body>
</html>

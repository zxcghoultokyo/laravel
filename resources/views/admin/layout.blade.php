<!DOCTYPE html>
<html lang="uk" x-data="{ 
    darkMode: localStorage.getItem('darkMode') === 'true',
    sidebarOpen: window.innerWidth >= 1024,
    sidebarCollapsed: localStorage.getItem('sidebarCollapsed') === 'true',
    searchOpen: false
}" 
x-init="$watch('darkMode', val => localStorage.setItem('darkMode', val)); $watch('sidebarCollapsed', val => localStorage.setItem('sidebarCollapsed', val))"
:class="{ 'dark': darkMode }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Адмін') - AIntento</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        [x-cloak] { display: none !important; }
        
        /* Dark mode styles */
        .dark body { background-color: #111827; }
        .dark .bg-white { background-color: #1f2937; }
        .dark .bg-gray-50 { background-color: #111827; }
        .dark .bg-gray-100 { background-color: #374151; }
        .dark .text-gray-900 { color: #f9fafb; }
        .dark .text-gray-700 { color: #d1d5db; }
        .dark .text-gray-600 { color: #9ca3af; }
        .dark .text-gray-500 { color: #9ca3af; }
        .dark .border-gray-200 { border-color: #374151; }
        .dark .border-gray-300 { border-color: #4b5563; }
        .dark .hover\:bg-gray-100:hover { background-color: #374151; }
        .dark .shadow-sm { box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.3); }
        
        /* Sidebar transition */
        .sidebar-transition {
            transition: width 0.2s ease-in-out, transform 0.2s ease-in-out;
        }
        
        /* Scrollbar styling */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .dark ::-webkit-scrollbar-thumb { background: #4b5563; }
        
        /* Search modal backdrop */
        .search-backdrop {
            backdrop-filter: blur(4px);
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 transition-colors duration-200">
    <div class="flex h-screen overflow-hidden">
        
        <!-- Mobile Sidebar Overlay -->
        <div x-show="sidebarOpen" 
             x-cloak
             @click="sidebarOpen = false"
             class="fixed inset-0 bg-black/50 z-40 lg:hidden"
             x-transition:enter="transition-opacity ease-out duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition-opacity ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0">
        </div>

        <!-- Sidebar -->
        <aside class="sidebar-transition fixed lg:relative z-50 h-full bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 flex flex-col"
               :class="{
                   'w-64': !sidebarCollapsed,
                   'w-20': sidebarCollapsed,
                   '-translate-x-full lg:translate-x-0': !sidebarOpen,
                   'translate-x-0': sidebarOpen
               }">
            
            <!-- Logo -->
            <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700">
                <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-3" :class="{ 'justify-center w-full': sidebarCollapsed }">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white font-bold text-lg shadow-lg flex-shrink-0">
                        A
                    </div>
                    <div x-show="!sidebarCollapsed" x-cloak>
                        <h1 class="text-lg font-bold text-gray-900 dark:text-white">AIntento</h1>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Admin Panel</p>
                    </div>
                </a>
                <!-- Collapse button (desktop only) -->
                <button @click="sidebarCollapsed = !sidebarCollapsed" 
                        class="hidden lg:flex p-1.5 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500"
                        x-show="!sidebarCollapsed">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>
                    </svg>
                </button>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 overflow-y-auto p-3 space-y-1">
                <!-- Dashboard -->
                <a href="{{ route('admin.dashboard') }}" 
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.dashboard') ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' }}"
                   :class="{ 'justify-center': sidebarCollapsed }"
                   :title="sidebarCollapsed ? 'Dashboard' : ''">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/>
                    </svg>
                    <span x-show="!sidebarCollapsed" class="font-medium">Dashboard</span>
                </a>

                <!-- Analytics Section -->
                <div class="pt-4" x-show="!sidebarCollapsed">
                    <p class="px-3 text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Аналітика</p>
                </div>

                <a href="{{ route('admin.analytics') }}" 
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.analytics') ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' }}"
                   :class="{ 'justify-center': sidebarCollapsed }"
                   :title="sidebarCollapsed ? 'Аналітика' : ''">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                    <span x-show="!sidebarCollapsed" class="font-medium">Статистика</span>
                </a>

                <a href="{{ route('admin.conversions') }}" 
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.conversions') ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' }}"
                   :class="{ 'justify-center': sidebarCollapsed }"
                   :title="sidebarCollapsed ? 'Конверсії' : ''">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                    <span x-show="!sidebarCollapsed" class="font-medium">Конверсії</span>
                </a>

                <!-- Communication Section -->
                <div class="pt-4" x-show="!sidebarCollapsed">
                    <p class="px-3 text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Комунікація</p>
                </div>

                <a href="{{ route('admin.chats.index') }}" 
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.chats.*') ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' }}"
                   :class="{ 'justify-center': sidebarCollapsed }"
                   :title="sidebarCollapsed ? 'Діалоги' : ''">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                    </svg>
                    <span x-show="!sidebarCollapsed" class="font-medium">Діалоги</span>
                </a>

                <!-- Settings Section -->
                <div class="pt-4" x-show="!sidebarCollapsed">
                    <p class="px-3 text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Налаштування</p>
                </div>

                <a href="{{ route('admin.widget.settings') }}" 
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.widget.*') ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' }}"
                   :class="{ 'justify-center': sidebarCollapsed }"
                   :title="sidebarCollapsed ? 'Віджет' : ''">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <span x-show="!sidebarCollapsed" class="font-medium">Віджет</span>
                </a>

                <a href="{{ route('admin.greetings') }}" 
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.greetings') ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' }}"
                   :class="{ 'justify-center': sidebarCollapsed }"
                   :title="sidebarCollapsed ? 'Привітання' : ''">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
                    </svg>
                    <span x-show="!sidebarCollapsed" class="font-medium">Привітання</span>
                </a>

                <a href="{{ route('admin.prompts') }}" 
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.prompts') ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' }}"
                   :class="{ 'justify-center': sidebarCollapsed }"
                   :title="sidebarCollapsed ? 'Промпти' : ''">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <span x-show="!sidebarCollapsed" class="font-medium">Промпти</span>
                </a>
            </nav>

            <!-- Sidebar Footer -->
            <div class="p-3 border-t border-gray-200 dark:border-gray-700">
                <!-- Expand button when collapsed -->
                <button @click="sidebarCollapsed = false" 
                        x-show="sidebarCollapsed"
                        class="w-full p-2.5 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500 flex justify-center">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/>
                    </svg>
                </button>
                
                <!-- Dark mode toggle -->
                <button @click="darkMode = !darkMode" 
                        x-show="!sidebarCollapsed"
                        class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                    <template x-if="!darkMode">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                        </svg>
                    </template>
                    <template x-if="darkMode">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                    </template>
                    <span class="font-medium" x-text="darkMode ? 'Світла тема' : 'Темна тема'"></span>
                </button>
            </div>
        </aside>

        <!-- Main Content Area -->
        <div class="flex-1 flex flex-col min-w-0">
            <!-- Top Header Bar -->
            <header class="h-16 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between px-4 lg:px-6 flex-shrink-0">
                <!-- Left: Mobile menu + Breadcrumb -->
                <div class="flex items-center gap-4">
                    <!-- Mobile menu button -->
                    <button @click="sidebarOpen = !sidebarOpen" 
                            class="lg:hidden p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                    
                    <!-- Breadcrumb -->
                    <nav class="hidden sm:flex items-center text-sm">
                        <a href="{{ route('admin.dashboard') }}" class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                            Admin
                        </a>
                        @hasSection('breadcrumb')
                            <svg class="w-4 h-4 mx-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                            <span class="text-gray-900 dark:text-white font-medium">@yield('breadcrumb')</span>
                        @endif
                    </nav>
                </div>

                <!-- Right: Search + Actions -->
                <div class="flex items-center gap-2">
                    <!-- Search Button -->
                    <button @click="searchOpen = true" 
                            class="flex items-center gap-2 px-3 py-2 rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-500 dark:text-gray-400 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <span class="hidden sm:inline text-sm">Пошук</span>
                        <kbd class="hidden md:inline px-1.5 py-0.5 text-xs bg-gray-200 dark:bg-gray-600 rounded">⌘K</kbd>
                    </button>

                    <!-- Dark mode toggle (compact for header) -->
                    <button @click="darkMode = !darkMode" 
                            class="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500 dark:text-gray-400">
                        <template x-if="!darkMode">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                            </svg>
                        </template>
                        <template x-if="darkMode">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                            </svg>
                        </template>
                    </button>
                </div>
            </header>

            <!-- Main Content -->
            <main class="flex-1 overflow-y-auto p-4 lg:p-6">
                {{ $slot }}
            </main>
        </div>
    </div>

    <!-- Search Modal (Cmd+K) -->
    <div x-show="searchOpen" 
         x-cloak
         @keydown.escape.window="searchOpen = false"
         @keydown.meta.k.window.prevent="searchOpen = !searchOpen"
         @keydown.ctrl.k.window.prevent="searchOpen = !searchOpen"
         class="fixed inset-0 z-[100] flex items-start justify-center pt-[15vh]">
        
        <!-- Backdrop -->
        <div @click="searchOpen = false" 
             class="absolute inset-0 bg-black/50 search-backdrop"
             x-transition:enter="transition-opacity ease-out duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition-opacity ease-in duration-150"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0">
        </div>

        <!-- Search Panel -->
        <div class="relative w-full max-w-xl mx-4 bg-white dark:bg-gray-800 rounded-xl shadow-2xl"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             @click.away="searchOpen = false">
            
            <!-- Search Input -->
            <div class="flex items-center px-4 border-b border-gray-200 dark:border-gray-700">
                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="text" 
                       placeholder="Пошук сторінок, налаштувань..."
                       class="w-full px-4 py-4 bg-transparent border-0 focus:ring-0 text-gray-900 dark:text-white placeholder-gray-400"
                       x-ref="searchInput"
                       @keydown.enter="navigateToResult()">
                <kbd class="px-2 py-1 text-xs bg-gray-100 dark:bg-gray-700 text-gray-500 rounded">ESC</kbd>
            </div>

            <!-- Quick Links -->
            <div class="p-2 max-h-80 overflow-y-auto">
                <p class="px-3 py-2 text-xs font-semibold text-gray-400 uppercase">Швидкі посилання</p>
                
                <a href="{{ route('admin.dashboard') }}" 
                   @click="searchOpen = false"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/>
                    </svg>
                    <span>Dashboard</span>
                </a>

                <a href="{{ route('admin.analytics') }}" 
                   @click="searchOpen = false"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                    <span>Аналітика</span>
                </a>

                <a href="{{ route('admin.conversions') }}" 
                   @click="searchOpen = false"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                    <span>Конверсії</span>
                </a>

                <a href="{{ route('admin.chats.index') }}" 
                   @click="searchOpen = false"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                    </svg>
                    <span>Діалоги</span>
                </a>

                <a href="{{ route('admin.widget.settings') }}" 
                   @click="searchOpen = false"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <span>Налаштування віджета</span>
                </a>

                <a href="{{ route('admin.greetings') }}" 
                   @click="searchOpen = false"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
                    </svg>
                    <span>Привітання</span>
                </a>
            </div>
        </div>
    </div>

    @livewireScripts
</body>
</html>

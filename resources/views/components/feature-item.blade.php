@props([
    'feature' => null,       // Feature key (e.g., 'advanced_analytics')
    'meta' => null,          // Feature metadata (from getFeaturesStatus)
    'available' => false,    // Is this feature available?
    'upgradeTo' => 'pro',    // Plan needed to unlock
    'size' => 'default',     // 'small', 'default', 'large'
])

@php
    $sizeClasses = match($size) {
        'small' => 'p-2 text-xs',
        'large' => 'p-4 text-base',
        default => 'p-3 text-sm',
    };
    
    $iconSize = match($size) {
        'small' => 'text-lg',
        'large' => 'text-3xl',
        default => 'text-xl',
    };
    
    $planLabels = [
        'starter' => 'Starter',
        'pro' => 'Pro',
        'enterprise' => 'Enterprise',
    ];
    
    $planColors = [
        'starter' => 'bg-blue-100 text-blue-700',
        'pro' => 'bg-purple-100 text-purple-700',
        'enterprise' => 'bg-amber-100 text-amber-700',
    ];
@endphp

@if($available)
    {{-- Available feature - normal display --}}
    <div {{ $attributes->merge(['class' => "flex items-center gap-3 {$sizeClasses} bg-white rounded-lg"]) }}>
        @if($meta && isset($meta['icon']))
            <span class="{{ $iconSize }}">{{ $meta['icon'] }}</span>
        @endif
        <div>
            <p class="font-medium text-gray-900">{{ $meta['label'] ?? $feature }}</p>
            @if($size !== 'small' && isset($meta['description']))
                <p class="text-gray-500 text-xs mt-0.5">{{ $meta['description'] }}</p>
            @endif
        </div>
    </div>
@else
    {{-- Locked feature - with Pro badge and tooltip --}}
    <div {{ $attributes->merge(['class' => "group relative flex items-center gap-3 {$sizeClasses} bg-gray-50 rounded-lg border border-dashed border-gray-300 opacity-75 hover:opacity-100 hover:bg-gray-100 transition cursor-pointer"]) }}>
        @if($meta && isset($meta['icon']))
            <span class="{{ $iconSize }} grayscale">{{ $meta['icon'] }}</span>
        @endif
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
                <p class="font-medium text-gray-500">{{ $meta['label'] ?? $feature }}</p>
                <span class="px-1.5 py-0.5 text-xs font-medium rounded {{ $planColors[$upgradeTo] ?? 'bg-purple-100 text-purple-700' }}">
                    {{ $planLabels[$upgradeTo] ?? ucfirst($upgradeTo) }}
                </span>
            </div>
            @if($size !== 'small' && isset($meta['description']))
                <p class="text-gray-400 text-xs mt-0.5 truncate">{{ $meta['description'] }}</p>
            @endif
        </div>
        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
        </svg>
        
        {{-- Tooltip on hover --}}
        <div class="absolute z-10 invisible group-hover:visible opacity-0 group-hover:opacity-100 transition-all 
                    bottom-full left-1/2 -translate-x-1/2 mb-2 w-64 p-3 
                    bg-gray-900 text-white text-xs rounded-lg shadow-lg">
            <p class="font-medium mb-1">{{ $meta['label'] ?? $feature }}</p>
            <p class="text-gray-300 mb-2">{{ $meta['description'] ?? '' }}</p>
            <a href="{{ route('billing.index') }}" 
               class="inline-flex items-center text-purple-300 hover:text-purple-200 font-medium">
                Перейти на {{ $planLabels[$upgradeTo] ?? ucfirst($upgradeTo) }} →
            </a>
            {{-- Arrow --}}
            <div class="absolute top-full left-1/2 -translate-x-1/2 border-8 border-transparent border-t-gray-900"></div>
        </div>
    </div>
@endif

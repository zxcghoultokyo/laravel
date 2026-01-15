<?php

namespace App\Services\Usage;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for tracking tenant usage (messages, products, etc.).
 */
class UsageTrackingService
{
    /**
     * Cache key prefix for usage counters.
     */
    protected const CACHE_PREFIX = 'tenant_usage_';

    /**
     * Increment message count for a tenant.
     */
    public function incrementMessages(Tenant $tenant, int $count = 1): int
    {
        // Use atomic increment in cache for speed
        $cacheKey = $this->getCacheKey($tenant->id, 'messages');
        
        // Get current cached value or load from DB
        if (!Cache::has($cacheKey)) {
            Cache::put($cacheKey, $tenant->messages_used, now()->endOfMonth());
        }
        
        $newCount = Cache::increment($cacheKey, $count);
        
        // Sync to DB periodically (every 10 messages or immediately if near limit)
        if ($newCount % 10 === 0 || $this->isNearLimit($tenant, $newCount)) {
            $this->syncToDatabase($tenant, 'messages_used', $newCount);
        }
        
        return $newCount;
    }

    /**
     * Get current message usage for a tenant.
     */
    public function getMessageUsage(Tenant $tenant): int
    {
        $cacheKey = $this->getCacheKey($tenant->id, 'messages');
        
        return Cache::remember($cacheKey, now()->endOfMonth(), function () use ($tenant) {
            return $tenant->messages_used;
        });
    }

    /**
     * Check if tenant has reached message limit.
     */
    public function hasReachedLimit(Tenant $tenant): bool
    {
        $usage = $this->getMessageUsage($tenant);
        return $usage >= $tenant->messages_limit;
    }

    /**
     * Check if tenant is near limit (80%+).
     */
    public function isNearLimit(Tenant $tenant, ?int $currentUsage = null): bool
    {
        $usage = $currentUsage ?? $this->getMessageUsage($tenant);
        $limit = $tenant->messages_limit;
        
        if ($limit <= 0) {
            return false;
        }
        
        return ($usage / $limit) >= 0.8;
    }

    /**
     * Get usage percentage (0-100).
     */
    public function getUsagePercentage(Tenant $tenant): float
    {
        $usage = $this->getMessageUsage($tenant);
        $limit = $tenant->messages_limit;
        
        if ($limit <= 0) {
            return 0;
        }
        
        return min(100, round(($usage / $limit) * 100, 1));
    }

    /**
     * Get remaining messages for a tenant.
     */
    public function getRemainingMessages(Tenant $tenant): int
    {
        $usage = $this->getMessageUsage($tenant);
        return max(0, $tenant->messages_limit - $usage);
    }

    /**
     * Reset monthly usage for a tenant.
     */
    public function resetMonthlyUsage(Tenant $tenant): void
    {
        $cacheKey = $this->getCacheKey($tenant->id, 'messages');
        Cache::forget($cacheKey);
        
        $tenant->update([
            'messages_used' => 0,
            'usage_reset_at' => now(),
        ]);
        
        Log::info('Tenant usage reset', [
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
        ]);
    }

    /**
     * Reset usage for all tenants (monthly cron job).
     */
    public function resetAllMonthlyUsage(): int
    {
        $count = Tenant::where('status', Tenant::STATUS_ACTIVE)
            ->update([
                'messages_used' => 0,
                'usage_reset_at' => now(),
            ]);
        
        // Clear all usage caches
        // Note: In production, use Redis SCAN or tags
        Log::info('All tenant usage reset', ['count' => $count]);
        
        return $count;
    }

    /**
     * Get usage statistics for a tenant.
     */
    public function getUsageStats(Tenant $tenant): array
    {
        $messagesUsed = $this->getMessageUsage($tenant);
        $messagesLimit = $tenant->messages_limit;
        $percentage = $this->getUsagePercentage($tenant);
        
        return [
            'messages' => [
                'used' => $messagesUsed,
                'limit' => $messagesLimit,
                'remaining' => max(0, $messagesLimit - $messagesUsed),
                'percentage' => $percentage,
                'is_near_limit' => $percentage >= 80,
                'is_at_limit' => $messagesUsed >= $messagesLimit,
            ],
            'products' => [
                'count' => $tenant->products()->count(),
                'limit' => $tenant->products_limit,
            ],
            'reset_at' => $tenant->usage_reset_at?->toDateTimeString(),
            'next_reset' => now()->endOfMonth()->toDateTimeString(),
        ];
    }

    /**
     * Sync cached usage to database.
     */
    protected function syncToDatabase(Tenant $tenant, string $field, int $value): void
    {
        try {
            DB::table('tenants')
                ->where('id', $tenant->id)
                ->update([$field => $value]);
        } catch (\Exception $e) {
            Log::error('Failed to sync usage to DB', [
                'tenant_id' => $tenant->id,
                'field' => $field,
                'value' => $value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get cache key for usage counter.
     */
    protected function getCacheKey(int $tenantId, string $type): string
    {
        return self::CACHE_PREFIX . "{$tenantId}_{$type}_" . date('Y_m');
    }

    /**
     * Force sync all cached usage to database.
     */
    public function syncAllToDatabase(): void
    {
        $tenants = Tenant::where('status', Tenant::STATUS_ACTIVE)->get();
        
        foreach ($tenants as $tenant) {
            $cacheKey = $this->getCacheKey($tenant->id, 'messages');
            
            if (Cache::has($cacheKey)) {
                $cachedValue = Cache::get($cacheKey);
                $this->syncToDatabase($tenant, 'messages_used', $cachedValue);
            }
        }
        
        Log::info('All tenant usage synced to database');
    }
}

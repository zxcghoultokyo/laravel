<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Services\Billing\Contracts\BillingServiceInterface;
use Illuminate\Support\Facades\Log;

/**
 * Abstract base class for billing providers.
 */
abstract class AbstractBillingService implements BillingServiceInterface
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Get config value with fallback.
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Generate unique order ID.
     */
    protected function generateOrderId(Tenant $tenant): string
    {
        return sprintf(
            '%s_%d_%s',
            $this->getName(),
            $tenant->id,
            uniqid()
        );
    }

    /**
     * Log billing event.
     */
    protected function log(string $message, array $context = []): void
    {
        Log::channel('billing')->info("[{$this->getName()}] {$message}", $context);
    }

    /**
     * Log billing error.
     */
    protected function logError(string $message, array $context = []): void
    {
        Log::channel('billing')->error("[{$this->getName()}] {$message}", $context);
    }

    /**
     * Format amount for display.
     */
    protected function formatAmount(int $amount, string $currency = 'UAH'): string
    {
        $value = $amount / 100;
        
        return match($currency) {
            'UAH' => number_format($value, 2, '.', ' ') . ' ₴',
            'USD' => '$' . number_format($value, 2, '.', ' '),
            'EUR' => '€' . number_format($value, 2, '.', ' '),
            default => number_format($value, 2) . ' ' . $currency,
        };
    }
}

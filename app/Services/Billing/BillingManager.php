<?php

namespace App\Services\Billing;

use App\Services\Billing\Contracts\BillingServiceInterface;
use App\Services\Billing\Drivers\LiqPayDriver;
use App\Services\Billing\Drivers\WayForPayDriver;
use InvalidArgumentException;

/**
 * Billing Manager - factory for payment drivers.
 * 
 * Usage:
 *   $billing = app(BillingManager::class);
 *   $wayforpay = $billing->driver('wayforpay');
 *   $liqpay = $billing->driver('liqpay');
 *   $default = $billing->driver(); // Uses default driver
 */
class BillingManager
{
    /**
     * Resolved driver instances.
     */
    protected array $drivers = [];

    /**
     * Get a billing driver instance.
     */
    public function driver(?string $name = null): BillingServiceInterface
    {
        $name = $name ?? $this->getDefaultDriver();

        if (!isset($this->drivers[$name])) {
            $this->drivers[$name] = $this->createDriver($name);
        }

        return $this->drivers[$name];
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return config('billing.default', 'wayforpay');
    }

    /**
     * Create a driver instance.
     */
    protected function createDriver(string $name): BillingServiceInterface
    {
        $config = config("billing.drivers.{$name}", []);

        return match ($name) {
            'wayforpay' => new WayForPayDriver($config),
            'liqpay' => new LiqPayDriver($config),
            default => throw new InvalidArgumentException("Billing driver [{$name}] not supported."),
        };
    }

    /**
     * Get all available driver names.
     */
    public function getAvailableDrivers(): array
    {
        return ['wayforpay', 'liqpay'];
    }

    /**
     * Check if a driver is configured.
     */
    public function isDriverConfigured(string $name): bool
    {
        $config = config("billing.drivers.{$name}", []);
        
        return match ($name) {
            'wayforpay' => !empty($config['merchant_account']) && !empty($config['merchant_secret']),
            'liqpay' => !empty($config['public_key']) && !empty($config['private_key']),
            default => false,
        };
    }

    /**
     * Dynamically call the default driver instance.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->driver()->$method(...$parameters);
    }
}

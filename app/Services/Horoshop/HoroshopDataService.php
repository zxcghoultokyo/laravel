<?php

namespace App\Services\Horoshop;

use App\Models\WidgetSettings;
use App\Services\Tenant\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Service для витягування та кешування даних з Horoshop API
 * (FAQ pages, delivery options, payment options)
 */
class HoroshopDataService
{
    private HoroshopClient $client;
    private const CACHE_TTL = 86400; // 24 hours

    public function __construct(HoroshopClient $client)
    {
        $this->client = $client;
    }

    /**
     * Build tenant-prefixed cache key.
     */
    private function cacheKey(string $suffix): string
    {
        $tenantId = app(TenantContext::class)->getTenantId() ?? 0;
        return "horoshop:{$tenantId}:{$suffix}";
    }

    /**
     * Витягти FAQ/Info pages з Horoshop
     * 
     * @param int $parentId - ID батьківської категорії (0 = root). Для FAQ зазвичай є специфічний ID
     * @return array
     */
    public function getFaqPages(int $parentId = 0): array
    {
        $cacheKey = $this->cacheKey("pages:{$parentId}");

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($parentId) {
            try {
                $response = $this->client->request('pages/export', [
                    'parent' => $parentId,
                ]);

                return $response['pages'] ?? [];
            } catch (\Exception $e) {
                Log::warning('HoroshopDataService: Failed to fetch FAQ pages', [
                    'parent_id' => $parentId,
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    /**
     * Витягти варіанти доставки
     * 
     * @return array
     */
    public function getDeliveryOptions(): array
    {
        $cacheKey = $this->cacheKey('delivery_options');

        return Cache::remember($cacheKey, self::CACHE_TTL, function () {
            try {
                $response = $this->client->request('delivery/export');

                return $response['delivery'] ?? [];
            } catch (\Exception $e) {
                Log::warning('HoroshopDataService: Failed to fetch delivery options', [
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    /**
     * Витягти типи доставки (Нова Пошта, Укрпошта, курʼєр, etc)
     * 
     * @return array
     */
    public function getDeliveryTypes(): array
    {
        $cacheKey = $this->cacheKey('delivery_types');

        return Cache::remember($cacheKey, self::CACHE_TTL, function () {
            try {
                $response = $this->client->request('delivery/exportTypes');

                return $response['deliveryTypes'] ?? [];
            } catch (\Exception $e) {
                Log::warning('HoroshopDataService: Failed to fetch delivery types', [
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    /**
     * Витягти варіанти оплати
     * 
     * @return array
     */
    public function getPaymentOptions(): array
    {
        $cacheKey = $this->cacheKey('payment_options');

        return Cache::remember($cacheKey, self::CACHE_TTL, function () {
            try {
                $response = $this->client->request('payment/export');

                return $response['payment'] ?? [];
            } catch (\Exception $e) {
                Log::warning('HoroshopDataService: Failed to fetch payment options', [
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    /**
     * Витягти методи оплати
     * 
     * @return array
     */
    public function getPaymentMethods(): array
    {
        $cacheKey = $this->cacheKey('payment_methods');

        return Cache::remember($cacheKey, self::CACHE_TTL, function () {
            try {
                $response = $this->client->request('payment/exportMethods');

                return $response['paymentMethods'] ?? [];
            } catch (\Exception $e) {
                Log::warning('HoroshopDataService: Failed to fetch payment methods', [
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    /**
     * Очистити кеш (коли дані оновлюються на Horoshop)
     */
    public function clearCache(): void
    {
        Cache::forget($this->cacheKey('pages:0'));
        Cache::forget($this->cacheKey('delivery_options'));
        Cache::forget($this->cacheKey('delivery_types'));
        Cache::forget($this->cacheKey('payment_options'));
        Cache::forget($this->cacheKey('payment_methods'));

        // Також очистити по всіх батьківськимID (якщо їх багато)
        for ($i = 1; $i <= 100; $i++) {
            Cache::forget($this->cacheKey("pages:{$i}"));
        }

        Log::info('HoroshopDataService: Cache cleared');
    }

    /**
     * Отримати дані про доставку з налаштувань
     * 
     * @return array
     */
    public function getShopDeliverySettings(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $settings = $tenantId
            ? WidgetSettings::where('tenant_id', $tenantId)->first()
            : WidgetSettings::first();

        if (!$settings) {
            return [];
        }

        return [
            'shop_phone' => $settings->shop_phone,
            'callback_form_url' => $settings->callback_form_url,
            'nova_poshta_tracking_url' => $settings->nova_poshta_tracking_url,
            'enable_delivery_tracking' => $settings->enable_delivery_tracking,
            'enable_faq_from_horoshop' => $settings->enable_faq_from_horoshop,
        ];
    }
}

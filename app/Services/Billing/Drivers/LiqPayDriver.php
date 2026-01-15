<?php

namespace App\Services\Billing\Drivers;

use App\Models\Tenant;
use App\Services\Billing\AbstractBillingService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * LiqPay billing driver.
 * 
 * Docs: https://www.liqpay.ua/documentation/api/home
 */
class LiqPayDriver extends AbstractBillingService
{
    protected Client $client;
    protected string $apiUrl = 'https://www.liqpay.ua/api/request';
    protected string $checkoutUrl = 'https://www.liqpay.ua/api/3/checkout';

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        
        $this->client = new Client([
            'timeout' => 30,
        ]);
    }

    public function getName(): string
    {
        return 'liqpay';
    }

    /**
     * Create checkout for one-time payment.
     */
    public function createCheckout(Tenant $tenant, array $options): array
    {
        $orderId = $this->generateOrderId($tenant);
        $amount = $options['amount'] / 100; // Convert from kopecks to UAH
        
        $params = [
            'public_key' => $this->getConfig('public_key'),
            'version' => '3',
            'action' => 'pay',
            'amount' => $amount,
            'currency' => $options['currency'] ?? 'UAH',
            'description' => $options['description'] ?? 'Ailure AI - Підписка',
            'order_id' => $orderId,
            'result_url' => $options['return_url'] ?? config('app.url') . '/billing/success',
            'server_url' => $options['webhook_url'] ?? config('app.url') . '/api/billing/webhook/liqpay',
            'language' => 'uk',
        ];
        
        $data = base64_encode(json_encode($params));
        $signature = $this->generateSignature($data);
        
        $this->log('Creating checkout', [
            'tenant_id' => $tenant->id,
            'order_id' => $orderId,
            'amount' => $amount,
        ]);

        return [
            'checkout_url' => $this->checkoutUrl,
            'order_id' => $orderId,
            'provider_data' => $params,
            'form_url' => $this->checkoutUrl,
            'form_data' => [
                'data' => $data,
                'signature' => $signature,
            ],
        ];
    }

    /**
     * Create subscription.
     * Note: LiqPay requires manual recurring implementation via tokenization.
     */
    public function createSubscription(Tenant $tenant, string $planId, array $options = []): array
    {
        $plan = $this->getPlanDetails($planId);
        $orderId = $this->generateOrderId($tenant);
        
        $params = [
            'public_key' => $this->getConfig('public_key'),
            'version' => '3',
            'action' => 'subscribe',
            'subscribe_date_start' => date('Y-m-d H:i:s'),
            'subscribe_periodicity' => 'month',
            'amount' => $plan['price'],
            'currency' => 'UAH',
            'description' => $plan['name'],
            'order_id' => $orderId,
            'result_url' => $options['return_url'] ?? config('app.url') . '/billing/success',
            'server_url' => config('app.url') . '/api/billing/webhook/liqpay',
            'language' => 'uk',
        ];
        
        $data = base64_encode(json_encode($params));
        $signature = $this->generateSignature($data);
        
        $this->log('Creating subscription', [
            'tenant_id' => $tenant->id,
            'plan_id' => $planId,
            'order_id' => $orderId,
        ]);

        return [
            'checkout_url' => $this->checkoutUrl,
            'order_id' => $orderId,
            'subscription_id' => $orderId,
            'provider_data' => $params,
            'form_url' => $this->checkoutUrl,
            'form_data' => [
                'data' => $data,
                'signature' => $signature,
            ],
        ];
    }

    /**
     * Cancel subscription.
     */
    public function cancelSubscription(string $subscriptionId, bool $immediately = false): bool
    {
        $params = [
            'public_key' => $this->getConfig('public_key'),
            'version' => '3',
            'action' => 'unsubscribe',
            'order_id' => $subscriptionId,
        ];
        
        try {
            $response = $this->apiRequest($params);
            
            $this->log('Subscription cancelled', [
                'subscription_id' => $subscriptionId,
                'response' => $response,
            ]);
            
            return ($response['status'] ?? '') === 'unsubscribed';
        } catch (\Exception $e) {
            $this->logError('Failed to cancel subscription', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get subscription status.
     */
    public function getSubscriptionStatus(string $subscriptionId): array
    {
        $params = [
            'public_key' => $this->getConfig('public_key'),
            'version' => '3',
            'action' => 'status',
            'order_id' => $subscriptionId,
        ];
        
        try {
            $response = $this->apiRequest($params);
            
            return [
                'status' => $this->mapStatus($response['status'] ?? ''),
                'current_period_end' => isset($response['end_date']) 
                    ? new \DateTime($response['end_date']) 
                    : null,
                'plan_id' => null,
                'raw' => $response,
            ];
        } catch (\Exception $e) {
            $this->logError('Failed to get subscription status', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'status' => 'unknown',
                'current_period_end' => null,
                'plan_id' => null,
            ];
        }
    }

    /**
     * Process webhook from LiqPay.
     */
    public function processWebhook(array $payload, array $headers = []): array
    {
        // LiqPay sends base64 encoded data
        $data = $payload['data'] ?? '';
        $signature = $payload['signature'] ?? '';
        
        // Decode payload
        $decoded = json_decode(base64_decode($data), true) ?? [];
        
        $this->log('Processing webhook', ['payload' => $decoded]);
        
        $status = $decoded['status'] ?? '';
        $orderId = $decoded['order_id'] ?? '';
        $amount = (int)(($decoded['amount'] ?? 0) * 100);
        
        $event = match($status) {
            'success', 'sandbox' => 'payment.success',
            'failure' => 'payment.failed',
            'error' => 'payment.error',
            'reversed' => 'payment.refunded',
            'subscribed' => 'subscription.created',
            'unsubscribed' => 'subscription.cancelled',
            default => 'unknown',
        };

        return [
            'event' => $event,
            'order_id' => $orderId,
            'subscription_id' => ($decoded['action'] ?? '') === 'subscribe' ? $orderId : null,
            'amount' => $amount,
            'status' => $status,
            'metadata' => [
                'transaction_id' => $decoded['transaction_id'] ?? null,
                'liqpay_order_id' => $decoded['liqpay_order_id'] ?? null,
                'sender_card_mask' => $decoded['sender_card_mask2'] ?? null,
                'sender_card_bank' => $decoded['sender_card_bank'] ?? null,
            ],
            'raw' => $decoded,
        ];
    }

    /**
     * Verify webhook signature.
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool
    {
        $data = $payload['data'] ?? '';
        $expectedSignature = $this->generateSignature($data);
        
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Refund payment.
     */
    public function refund(string $paymentId, ?int $amount = null): bool
    {
        $params = [
            'public_key' => $this->getConfig('public_key'),
            'version' => '3',
            'action' => 'refund',
            'order_id' => $paymentId,
        ];
        
        if ($amount !== null) {
            $params['amount'] = $amount / 100;
        }
        
        try {
            $response = $this->apiRequest($params);
            
            $this->log('Refund processed', [
                'payment_id' => $paymentId,
                'amount' => $amount,
                'response' => $response,
            ]);
            
            return ($response['status'] ?? '') === 'reversed';
        } catch (\Exception $e) {
            $this->logError('Refund failed', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get payment status.
     */
    public function getPaymentStatus(string $paymentId): array
    {
        $params = [
            'public_key' => $this->getConfig('public_key'),
            'version' => '3',
            'action' => 'status',
            'order_id' => $paymentId,
        ];
        
        try {
            $response = $this->apiRequest($params);
            
            return [
                'status' => $this->mapStatus($response['status'] ?? ''),
                'amount' => (int)(($response['amount'] ?? 0) * 100),
                'paid_at' => isset($response['create_date']) 
                    ? new \DateTime('@' . ($response['create_date'] / 1000)) 
                    : null,
                'raw' => $response,
            ];
        } catch (\Exception $e) {
            $this->logError('Failed to get payment status', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'status' => 'unknown',
                'amount' => 0,
                'paid_at' => null,
            ];
        }
    }

    /**
     * Make API request.
     */
    protected function apiRequest(array $params): array
    {
        $data = base64_encode(json_encode($params));
        $signature = $this->generateSignature($data);
        
        try {
            $response = $this->client->post($this->apiUrl, [
                'form_params' => [
                    'data' => $data,
                    'signature' => $signature,
                ],
            ]);
            
            return json_decode($response->getBody(), true) ?? [];
        } catch (GuzzleException $e) {
            throw new \RuntimeException('LiqPay API error: ' . $e->getMessage());
        }
    }

    /**
     * Generate signature.
     */
    protected function generateSignature(string $data): string
    {
        $privateKey = $this->getConfig('private_key');
        return base64_encode(sha1($privateKey . $data . $privateKey, true));
    }

    /**
     * Map LiqPay status to standard status.
     */
    protected function mapStatus(string $status): string
    {
        return match($status) {
            'success', 'sandbox' => 'success',
            'failure', 'error' => 'failed',
            'reversed', 'refund_wait' => 'refunded',
            'processing', 'wait_accept' => 'pending',
            'subscribed' => 'active',
            'unsubscribed' => 'cancelled',
            default => 'unknown',
        };
    }

    /**
     * Get plan details.
     */
    protected function getPlanDetails(string $planId): array
    {
        $plans = config('billing.plans', []);
        return $plans[$planId] ?? [
            'name' => 'Unknown Plan',
            'price' => 0,
        ];
    }
}

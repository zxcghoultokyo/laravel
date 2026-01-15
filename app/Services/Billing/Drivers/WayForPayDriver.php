<?php

namespace App\Services\Billing\Drivers;

use App\Models\Tenant;
use App\Services\Billing\AbstractBillingService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * WayForPay billing driver.
 * 
 * Docs: https://wiki.wayforpay.com/
 */
class WayForPayDriver extends AbstractBillingService
{
    protected Client $client;
    protected string $apiUrl = 'https://api.wayforpay.com/api';

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        
        $this->client = new Client([
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function getName(): string
    {
        return 'wayforpay';
    }

    /**
     * Create checkout for one-time payment.
     */
    public function createCheckout(Tenant $tenant, array $options): array
    {
        $orderId = $this->generateOrderId($tenant);
        $amount = $options['amount'] / 100; // Convert from kopecks to UAH
        
        $params = [
            'merchantAccount' => $this->getConfig('merchant_account'),
            'merchantDomainName' => $this->getConfig('domain'),
            'orderReference' => $orderId,
            'orderDate' => time(),
            'amount' => $amount,
            'currency' => $options['currency'] ?? 'UAH',
            'productName' => [$options['description'] ?? 'Ailure AI - Підписка'],
            'productPrice' => [$amount],
            'productCount' => [1],
            'clientEmail' => $tenant->email,
            'returnUrl' => $options['return_url'] ?? config('app.url') . '/billing/success',
            'serviceUrl' => $options['webhook_url'] ?? config('app.url') . '/api/billing/webhook/wayforpay',
            'language' => 'UA',
        ];
        
        // Add signature
        $params['merchantSignature'] = $this->generateSignature($params);
        
        $this->log('Creating checkout', [
            'tenant_id' => $tenant->id,
            'order_id' => $orderId,
            'amount' => $amount,
        ]);

        return [
            'checkout_url' => 'https://secure.wayforpay.com/pay?' . http_build_query(['data' => base64_encode(json_encode($params))]),
            'order_id' => $orderId,
            'provider_data' => $params,
            // Alternative: return form data for POST
            'form_url' => 'https://secure.wayforpay.com/pay',
            'form_data' => $params,
        ];
    }

    /**
     * Create subscription (regular payment).
     */
    public function createSubscription(Tenant $tenant, string $planId, array $options = []): array
    {
        $plan = $this->getPlanDetails($planId);
        $orderId = $this->generateOrderId($tenant);
        
        $params = [
            'merchantAccount' => $this->getConfig('merchant_account'),
            'merchantDomainName' => $this->getConfig('domain'),
            'orderReference' => $orderId,
            'orderDate' => time(),
            'amount' => $plan['price'],
            'currency' => 'UAH',
            'productName' => [$plan['name']],
            'productPrice' => [$plan['price']],
            'productCount' => [1],
            'clientEmail' => $tenant->email,
            'returnUrl' => $options['return_url'] ?? config('app.url') . '/billing/success',
            'serviceUrl' => config('app.url') . '/api/billing/webhook/wayforpay',
            'language' => 'UA',
            
            // Regular payment settings
            'regularMode' => 'monthly',
            'regularAmount' => $plan['price'],
            'regularCount' => 0, // 0 = unlimited
            'regularOn' => 1,
            'dateNext' => date('d.m.Y', strtotime('+1 month')),
            'dateEnd' => date('d.m.Y', strtotime('+10 years')),
        ];
        
        $params['merchantSignature'] = $this->generateSignature($params);
        
        $this->log('Creating subscription', [
            'tenant_id' => $tenant->id,
            'plan_id' => $planId,
            'order_id' => $orderId,
        ]);

        return [
            'checkout_url' => 'https://secure.wayforpay.com/pay',
            'order_id' => $orderId,
            'subscription_id' => $orderId, // WayForPay uses orderReference as subscription ID
            'provider_data' => $params,
            'form_url' => 'https://secure.wayforpay.com/pay',
            'form_data' => $params,
        ];
    }

    /**
     * Cancel subscription.
     */
    public function cancelSubscription(string $subscriptionId, bool $immediately = false): bool
    {
        $params = [
            'transactionType' => 'REGULAR_OFF',
            'merchantAccount' => $this->getConfig('merchant_account'),
            'orderReference' => $subscriptionId,
            'apiVersion' => 1,
        ];
        
        $params['merchantSignature'] = $this->generateApiSignature([
            $params['merchantAccount'],
            $params['orderReference'],
        ]);

        try {
            $response = $this->client->post($this->apiUrl, [
                'json' => $params,
            ]);
            
            $data = json_decode($response->getBody(), true);
            
            $this->log('Subscription cancelled', [
                'subscription_id' => $subscriptionId,
                'response' => $data,
            ]);
            
            return ($data['reasonCode'] ?? null) === 1100;
        } catch (GuzzleException $e) {
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
            'transactionType' => 'REGULAR_STATUS',
            'merchantAccount' => $this->getConfig('merchant_account'),
            'orderReference' => $subscriptionId,
            'apiVersion' => 1,
        ];
        
        $params['merchantSignature'] = $this->generateApiSignature([
            $params['merchantAccount'],
            $params['orderReference'],
        ]);

        try {
            $response = $this->client->post($this->apiUrl, [
                'json' => $params,
            ]);
            
            $data = json_decode($response->getBody(), true);
            
            return [
                'status' => $this->mapRegularStatus($data['regularStatus'] ?? ''),
                'current_period_end' => isset($data['dateNext']) 
                    ? \DateTime::createFromFormat('d.m.Y', $data['dateNext']) 
                    : null,
                'plan_id' => $data['regularAmount'] ?? null,
                'raw' => $data,
            ];
        } catch (GuzzleException $e) {
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
     * Process webhook from WayForPay.
     */
    public function processWebhook(array $payload, array $headers = []): array
    {
        $this->log('Processing webhook', ['payload' => $payload]);
        
        $transactionStatus = $payload['transactionStatus'] ?? '';
        $orderReference = $payload['orderReference'] ?? '';
        $amount = (int)(($payload['amount'] ?? 0) * 100); // Convert to kopecks
        
        // Determine event type
        $event = match($transactionStatus) {
            'Approved' => 'payment.success',
            'Declined' => 'payment.failed',
            'Expired' => 'payment.expired',
            'Refunded' => 'payment.refunded',
            'InProcessing' => 'payment.processing',
            default => 'unknown',
        };
        
        // Check if it's a regular payment
        if (!empty($payload['regularPayment'])) {
            $event = match($transactionStatus) {
                'Approved' => 'subscription.payment_success',
                'Declined' => 'subscription.payment_failed',
                default => $event,
            };
        }

        return [
            'event' => $event,
            'order_id' => $orderReference,
            'subscription_id' => $payload['regularPayment'] ? $orderReference : null,
            'amount' => $amount,
            'status' => strtolower($transactionStatus),
            'metadata' => [
                'transaction_id' => $payload['transactionId'] ?? null,
                'card_pan' => $payload['cardPan'] ?? null,
                'card_type' => $payload['cardType'] ?? null,
                'issuer_bank' => $payload['issuerBankName'] ?? null,
                'fee' => $payload['fee'] ?? null,
            ],
            'raw' => $payload,
        ];
    }

    /**
     * Verify webhook signature.
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool
    {
        $signString = implode(';', [
            $payload['merchantAccount'] ?? '',
            $payload['orderReference'] ?? '',
            $payload['amount'] ?? '',
            $payload['currency'] ?? '',
            $payload['authCode'] ?? '',
            $payload['cardPan'] ?? '',
            $payload['transactionStatus'] ?? '',
            $payload['reasonCode'] ?? '',
        ]);
        
        $expectedSignature = hash_hmac('md5', $signString, $this->getConfig('secret_key'));
        
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Refund payment.
     */
    public function refund(string $paymentId, ?int $amount = null): bool
    {
        $params = [
            'transactionType' => 'REFUND',
            'merchantAccount' => $this->getConfig('merchant_account'),
            'orderReference' => $paymentId,
            'apiVersion' => 1,
        ];
        
        if ($amount !== null) {
            $params['amount'] = $amount / 100;
        }
        
        $params['merchantSignature'] = $this->generateApiSignature([
            $params['merchantAccount'],
            $params['orderReference'],
            $params['amount'] ?? '',
        ]);

        try {
            $response = $this->client->post($this->apiUrl, [
                'json' => $params,
            ]);
            
            $data = json_decode($response->getBody(), true);
            
            $this->log('Refund processed', [
                'payment_id' => $paymentId,
                'amount' => $amount,
                'response' => $data,
            ]);
            
            return ($data['reasonCode'] ?? null) === 1100;
        } catch (GuzzleException $e) {
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
            'transactionType' => 'CHECK_STATUS',
            'merchantAccount' => $this->getConfig('merchant_account'),
            'orderReference' => $paymentId,
            'apiVersion' => 1,
        ];
        
        $params['merchantSignature'] = $this->generateApiSignature([
            $params['merchantAccount'],
            $params['orderReference'],
        ]);

        try {
            $response = $this->client->post($this->apiUrl, [
                'json' => $params,
            ]);
            
            $data = json_decode($response->getBody(), true);
            
            return [
                'status' => $this->mapTransactionStatus($data['transactionStatus'] ?? ''),
                'amount' => (int)(($data['amount'] ?? 0) * 100),
                'paid_at' => isset($data['createdDate']) 
                    ? new \DateTime($data['createdDate']) 
                    : null,
                'raw' => $data,
            ];
        } catch (GuzzleException $e) {
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
     * Generate signature for checkout form.
     */
    protected function generateSignature(array $params): string
    {
        $signString = implode(';', [
            $params['merchantAccount'],
            $params['merchantDomainName'],
            $params['orderReference'],
            $params['orderDate'],
            $params['amount'],
            $params['currency'],
            implode(';', $params['productName']),
            implode(';', $params['productCount']),
            implode(';', $params['productPrice']),
        ]);
        
        return hash_hmac('md5', $signString, $this->getConfig('secret_key'));
    }

    /**
     * Generate signature for API requests.
     */
    protected function generateApiSignature(array $values): string
    {
        $signString = implode(';', $values);
        return hash_hmac('md5', $signString, $this->getConfig('secret_key'));
    }

    /**
     * Map WayForPay transaction status to standard status.
     */
    protected function mapTransactionStatus(string $status): string
    {
        return match($status) {
            'Approved' => 'success',
            'Declined' => 'failed',
            'Expired' => 'expired',
            'Refunded', 'RefundInProcessing' => 'refunded',
            'InProcessing' => 'pending',
            default => 'unknown',
        };
    }

    /**
     * Map WayForPay regular status to standard status.
     */
    protected function mapRegularStatus(string $status): string
    {
        return match($status) {
            'Active' => 'active',
            'Suspended' => 'cancelled',
            'Removed' => 'cancelled',
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

    /**
     * Generate signature for webhook response.
     */
    public function generateResponseSignature(string $orderReference, string $status, int $time): string
    {
        $signString = implode(';', [
            $orderReference,
            $status,
            $time,
        ]);
        
        return hash_hmac('md5', $signString, $this->getConfig('secret_key'));
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\BillingManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BillingWebhookController extends Controller
{
    public function __construct(
        protected BillingManager $billing
    ) {}

    /**
     * Handle WayForPay webhook.
     */
    public function wayforpay(Request $request): JsonResponse
    {
        return $this->handleWebhook('wayforpay', $request);
    }

    /**
     * Handle LiqPay webhook.
     */
    public function liqpay(Request $request): JsonResponse
    {
        return $this->handleWebhook('liqpay', $request);
    }

    /**
     * Generic webhook handler.
     */
    protected function handleWebhook(string $provider, Request $request): JsonResponse
    {
        $payload = $request->all();
        $headers = $request->headers->all();
        
        Log::info("Billing webhook received: {$provider}", [
            'payload' => $payload,
        ]);
        
        try {
            $driver = $this->billing->driver($provider);
            
            // Verify signature
            $signature = $this->extractSignature($provider, $request);
            
            if ($signature && !$driver->verifyWebhookSignature($payload, $signature)) {
                Log::warning("Invalid webhook signature: {$provider}");
                return response()->json(['error' => 'Invalid signature'], 400);
            }
            
            // Process webhook
            $result = $driver->processWebhook($payload, $headers);
            
            Log::info("Webhook processed: {$provider}", $result);
            
            // Handle event
            $this->handleEvent($provider, $result);
            
            // Return appropriate response for provider
            return $this->buildResponse($provider, $result);
            
        } catch (\Exception $e) {
            Log::error("Webhook error: {$provider}", [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);
            
            return response()->json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * Handle webhook event.
     */
    protected function handleEvent(string $provider, array $result): void
    {
        $event = $result['event'] ?? 'unknown';
        $orderId = $result['order_id'] ?? null;
        
        if (!$orderId) {
            return;
        }
        
        // Find payment by order ID
        $payment = Payment::byProvider($provider)->byOrderId($orderId)->first();
        
        switch ($event) {
            case 'payment.success':
                $this->handlePaymentSuccess($payment, $result);
                break;
                
            case 'payment.failed':
                $this->handlePaymentFailed($payment, $result);
                break;
                
            case 'payment.refunded':
                $this->handlePaymentRefunded($payment, $result);
                break;
                
            case 'subscription.created':
            case 'subscription.payment_success':
                $this->handleSubscriptionPayment($payment, $result);
                break;
                
            case 'subscription.cancelled':
                $this->handleSubscriptionCancelled($result);
                break;
        }
    }

    /**
     * Handle successful payment.
     */
    protected function handlePaymentSuccess(?Payment $payment, array $result): void
    {
        if (!$payment) {
            Log::warning('Payment not found for success event', $result);
            return;
        }
        
        $metadata = $result['metadata'] ?? [];
        
        $payment->markAsSuccessful([
            'provider_payment_id' => $result['transaction_id'] ?? $metadata['transaction_id'] ?? null,
            'card_mask' => $metadata['card_mask'] ?? $metadata['sender_card_mask'] ?? null,
            'card_type' => $metadata['card_type'] ?? null,
            'card_bank' => $metadata['card_bank'] ?? $metadata['sender_card_bank'] ?? null,
            'metadata' => $metadata,
        ]);
        
        // Activate subscription if exists
        if ($payment->subscription_id) {
            $subscription = $payment->subscription;
            
            if ($subscription) {
                $periodStart = now();
                $periodEnd = now()->addMonth();
                
                $subscription->markAsActive($periodStart, $periodEnd);
                
                // Update tenant plan
                $this->updateTenantPlan($subscription->tenant, $subscription->plan_id);
            }
        }
        
        Log::info('Payment marked as successful', [
            'payment_id' => $payment->id,
            'subscription_id' => $payment->subscription_id,
        ]);
    }

    /**
     * Handle failed payment.
     */
    protected function handlePaymentFailed(?Payment $payment, array $result): void
    {
        if (!$payment) {
            return;
        }
        
        $reason = $result['metadata']['reason_code_description'] ?? $result['status'] ?? 'Unknown error';
        $payment->markAsFailed($reason);
        
        // Update subscription status if exists
        if ($payment->subscription_id) {
            $subscription = $payment->subscription;
            if ($subscription && $subscription->status !== Subscription::STATUS_ACTIVE) {
                $subscription->update(['status' => Subscription::STATUS_UNPAID]);
            }
        }
        
        Log::info('Payment marked as failed', [
            'payment_id' => $payment->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Handle refunded payment.
     */
    protected function handlePaymentRefunded(?Payment $payment, array $result): void
    {
        if (!$payment) {
            return;
        }
        
        $amount = $result['amount'] ?? null;
        $payment->markAsRefunded($amount);
        
        Log::info('Payment marked as refunded', [
            'payment_id' => $payment->id,
            'amount' => $amount,
        ]);
    }

    /**
     * Handle subscription payment (recurring).
     */
    protected function handleSubscriptionPayment(?Payment $payment, array $result): void
    {
        // For recurring payments, create new payment record if not exists
        if (!$payment && !empty($result['subscription_id'])) {
            $subscription = Subscription::where('provider_subscription_id', $result['subscription_id'])->first();
            
            if ($subscription) {
                $payment = Payment::create([
                    'tenant_id' => $subscription->tenant_id,
                    'subscription_id' => $subscription->id,
                    'amount' => $result['amount'] ?? 0,
                    'currency' => config('billing.currency', 'UAH'),
                    'status' => Payment::STATUS_PENDING,
                    'provider' => $subscription->provider,
                    'provider_order_id' => $result['order_id'] ?? null,
                    'description' => "Автоматична оплата підписки",
                ]);
            }
        }
        
        if ($payment) {
            $this->handlePaymentSuccess($payment, $result);
        }
    }

    /**
     * Handle subscription cancelled.
     */
    protected function handleSubscriptionCancelled(array $result): void
    {
        $subscriptionId = $result['subscription_id'] ?? null;
        
        if (!$subscriptionId) {
            return;
        }
        
        $subscription = Subscription::where('provider_subscription_id', $subscriptionId)->first();
        
        if ($subscription && !$subscription->isCancelled()) {
            $subscription->cancel();
            
            Log::info('Subscription cancelled via webhook', [
                'subscription_id' => $subscription->id,
            ]);
        }
    }

    /**
     * Update tenant plan after successful payment.
     */
    protected function updateTenantPlan(Tenant $tenant, string $planId): void
    {
        $plan = config("billing.plans.{$planId}", []);
        
        if (empty($plan)) {
            return;
        }
        
        $limits = $plan['limits'] ?? [];
        
        $tenant->update([
            'plan' => $planId,
            'is_active' => true,
            'messages_limit' => $limits['messages_per_month'] ?? 1000,
            'products_limit' => $limits['products_limit'] ?? 500,
        ]);
        
        Log::info('Tenant plan updated', [
            'tenant_id' => $tenant->id,
            'plan' => $planId,
        ]);
    }

    /**
     * Extract signature from request.
     */
    protected function extractSignature(string $provider, Request $request): ?string
    {
        return match($provider) {
            'wayforpay' => $request->input('merchantSignature'),
            'liqpay' => $request->input('signature'),
            default => null,
        };
    }

    /**
     * Build provider-specific response.
     */
    protected function buildResponse(string $provider, array $result): JsonResponse
    {
        // WayForPay expects specific response format
        if ($provider === 'wayforpay') {
            return response()->json([
                'orderReference' => $result['order_id'] ?? '',
                'status' => 'accept',
                'time' => time(),
                'signature' => $this->billing->driver($provider)->generateResponseSignature(
                    $result['order_id'] ?? '',
                    'accept',
                    time()
                ),
            ]);
        }
        
        return response()->json(['status' => 'ok']);
    }
}

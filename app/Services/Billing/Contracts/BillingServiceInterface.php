<?php

namespace App\Services\Billing\Contracts;

use App\Models\Tenant;

/**
 * Interface for billing service providers.
 * 
 * Supports: WayForPay, LiqPay, Fondy, Stripe, etc.
 */
interface BillingServiceInterface
{
    /**
     * Get provider name.
     */
    public function getName(): string;

    /**
     * Create a checkout session for one-time payment.
     *
     * @param Tenant $tenant
     * @param array $options [
     *   'amount' => int (in kopecks/cents),
     *   'currency' => string (UAH, USD),
     *   'description' => string,
     *   'return_url' => string,
     *   'webhook_url' => string,
     *   'metadata' => array,
     * ]
     * @return array [
     *   'checkout_url' => string,
     *   'order_id' => string,
     *   'provider_data' => array,
     * ]
     */
    public function createCheckout(Tenant $tenant, array $options): array;

    /**
     * Create a subscription (recurring payment).
     *
     * @param Tenant $tenant
     * @param string $planId Plan identifier
     * @param array $options [
     *   'return_url' => string,
     *   'trial_days' => int,
     * ]
     * @return array [
     *   'checkout_url' => string,
     *   'subscription_id' => string,
     *   'provider_data' => array,
     * ]
     */
    public function createSubscription(Tenant $tenant, string $planId, array $options = []): array;

    /**
     * Cancel a subscription.
     *
     * @param string $subscriptionId
     * @param bool $immediately Cancel now or at period end
     * @return bool
     */
    public function cancelSubscription(string $subscriptionId, bool $immediately = false): bool;

    /**
     * Get subscription status.
     *
     * @param string $subscriptionId
     * @return array [
     *   'status' => string (active, cancelled, past_due, trialing),
     *   'current_period_end' => \DateTime,
     *   'plan_id' => string,
     * ]
     */
    public function getSubscriptionStatus(string $subscriptionId): array;

    /**
     * Process webhook payload.
     *
     * @param array $payload Raw webhook data
     * @param array $headers Request headers (for signature verification)
     * @return array [
     *   'event' => string (payment.success, payment.failed, subscription.created, etc.),
     *   'order_id' => string,
     *   'subscription_id' => string|null,
     *   'amount' => int,
     *   'status' => string,
     *   'metadata' => array,
     * ]
     */
    public function processWebhook(array $payload, array $headers = []): array;

    /**
     * Verify webhook signature.
     *
     * @param array $payload
     * @param string $signature
     * @return bool
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool;

    /**
     * Refund a payment.
     *
     * @param string $paymentId
     * @param int|null $amount Partial refund amount (null = full refund)
     * @return bool
     */
    public function refund(string $paymentId, ?int $amount = null): bool;

    /**
     * Get payment status.
     *
     * @param string $paymentId
     * @return array [
     *   'status' => string (pending, success, failed, refunded),
     *   'amount' => int,
     *   'paid_at' => \DateTime|null,
     * ]
     */
    public function getPaymentStatus(string $paymentId): array;
}

<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\BillingManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class BillingController extends Controller
{
    public function __construct(
        protected BillingManager $billing
    ) {
        $this->middleware('auth');
    }

    /**
     * Show billing/plans page.
     */
    public function index()
    {
        $tenant = $this->getCurrentTenant();
        $plans = config('billing.plans', []);
        $currentSubscription = $tenant->subscriptions()->active()->first();
        $recentPayments = $tenant->payments()->recent()->orderByDesc('created_at')->limit(10)->get();
        
        return view('billing.index', [
            'tenant' => $tenant,
            'plans' => $plans,
            'currentSubscription' => $currentSubscription,
            'recentPayments' => $recentPayments,
            'trialDaysLeft' => $this->getTrialDaysLeft($currentSubscription),
        ]);
    }

    /**
     * Show checkout page for a plan.
     */
    public function checkout(string $planId)
    {
        $plans = config('billing.plans', []);
        
        if (!isset($plans[$planId])) {
            abort(404, 'План не знайдено');
        }
        
        $tenant = $this->getCurrentTenant();
        $plan = $plans[$planId];
        
        return view('billing.checkout', [
            'tenant' => $tenant,
            'planId' => $planId,
            'plan' => $plan,
            'provider' => config('billing.default'),
        ]);
    }

    /**
     * Create subscription and redirect to payment.
     */
    public function subscribe(Request $request, string $planId)
    {
        $request->validate([
            'provider' => 'sometimes|string|in:wayforpay,liqpay',
        ]);
        
        $plans = config('billing.plans', []);
        
        if (!isset($plans[$planId])) {
            return back()->withErrors(['plan' => 'План не знайдено']);
        }
        
        $tenant = $this->getCurrentTenant();
        $provider = $request->input('provider', config('billing.default'));
        $plan = $plans[$planId];
        
        // Check if already has active subscription
        if ($tenant->subscriptions()->active()->exists()) {
            return back()->withErrors(['subscription' => 'У вас вже є активна підписка']);
        }
        
        try {
            $driver = $this->billing->driver($provider);
            
            // Create subscription record (pending)
            $subscription = Subscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $planId,
                'status' => Subscription::STATUS_PENDING ?? 'pending',
                'provider' => $provider,
            ]);
            
            // Create payment record
            $payment = Payment::create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
                'amount' => $plan['price'] * 100, // Convert to kopecks
                'currency' => config('billing.currency', 'UAH'),
                'status' => Payment::STATUS_PENDING,
                'provider' => $provider,
                'description' => "Підписка {$plan['name']}",
            ]);
            
            // Get checkout data from provider
            $checkoutData = $driver->createSubscription($tenant, $planId, [
                'return_url' => route('billing.success'),
                'cancel_url' => route('billing.cancel'),
            ]);
            
            // Update payment with order ID
            $payment->update([
                'provider_order_id' => $checkoutData['order_id'] ?? null,
            ]);
            
            // Update subscription with provider ID
            if (!empty($checkoutData['subscription_id'])) {
                $subscription->update([
                    'provider_subscription_id' => $checkoutData['subscription_id'],
                ]);
            }
            
            // Return checkout view or redirect to payment URL
            if (!empty($checkoutData['form_data'])) {
                // LiqPay style - need to POST form
                return view('billing.redirect', [
                    'url' => $checkoutData['form_url'] ?? $checkoutData['checkout_url'],
                    'data' => $checkoutData['form_data'],
                ]);
            }
            
            // WayForPay style - redirect to URL
            return redirect()->away($checkoutData['checkout_url']);
            
        } catch (\Exception $e) {
            Log::error('Billing subscription failed', [
                'tenant_id' => $tenant->id,
                'plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
            
            return back()->withErrors(['payment' => 'Помилка створення платежу. Спробуйте пізніше.']);
        }
    }

    /**
     * Cancel subscription.
     */
    public function cancel(Request $request)
    {
        $tenant = $this->getCurrentTenant();
        $subscription = $tenant->subscriptions()->active()->first();
        
        if (!$subscription) {
            return back()->withErrors(['subscription' => 'Активну підписку не знайдено']);
        }
        
        try {
            $driver = $this->billing->driver($subscription->provider);
            
            // Cancel at provider if has provider subscription ID
            if ($subscription->provider_subscription_id) {
                $driver->cancelSubscription($subscription->provider_subscription_id);
            }
            
            // Mark subscription as cancelled (will still work until period end)
            $subscription->cancel();
            
            return redirect()->route('billing.index')
                ->with('success', 'Підписку скасовано. Вона буде активна до ' . $subscription->ends_at->format('d.m.Y'));
                
        } catch (\Exception $e) {
            Log::error('Subscription cancellation failed', [
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
            
            return back()->withErrors(['cancel' => 'Помилка скасування підписки']);
        }
    }

    /**
     * Resume cancelled subscription.
     */
    public function resume()
    {
        $tenant = $this->getCurrentTenant();
        $subscription = $tenant->subscriptions()
            ->where('status', Subscription::STATUS_CANCELLED)
            ->first();
        
        if (!$subscription || !$subscription->onGracePeriod()) {
            return back()->withErrors(['subscription' => 'Неможливо відновити підписку']);
        }
        
        $subscription->resume();
        
        return redirect()->route('billing.index')
            ->with('success', 'Підписку відновлено');
    }

    /**
     * Payment success page.
     */
    public function success(Request $request)
    {
        $tenant = $this->getCurrentTenant();
        
        return view('billing.success', [
            'tenant' => $tenant,
        ]);
    }

    /**
     * Payment cancelled page.
     */
    public function cancelled()
    {
        return view('billing.cancel');
    }

    /**
     * Show payment history.
     */
    public function history()
    {
        $tenant = $this->getCurrentTenant();
        $payments = $tenant->payments()
            ->with('subscription')
            ->orderByDesc('created_at')
            ->paginate(20);
        
        return view('billing.history', [
            'payments' => $payments,
        ]);
    }

    /**
     * Download invoice (simple).
     */
    public function invoice(Payment $payment)
    {
        $tenant = $this->getCurrentTenant();
        
        if ($payment->tenant_id !== $tenant->id) {
            abort(403);
        }
        
        return view('billing.invoice', [
            'payment' => $payment,
            'tenant' => $tenant,
        ]);
    }

    /**
     * Get current tenant.
     */
    protected function getCurrentTenant(): Tenant
    {
        return Auth::user()->tenant;
    }

    /**
     * Calculate trial days left.
     */
    protected function getTrialDaysLeft(?Subscription $subscription): int
    {
        if (!$subscription || !$subscription->onTrial()) {
            return 0;
        }
        
        return max(0, now()->diffInDays($subscription->trial_ends_at, false));
    }
}

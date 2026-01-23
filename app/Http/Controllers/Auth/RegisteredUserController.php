<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenant\DefaultTriggerService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'store_name' => ['required', 'string', 'max:255'],
        ]);

        // Create tenant and user in transaction
        $user = DB::transaction(function () use ($request) {
            // Generate unique slug
            $baseSlug = Str::slug($request->store_name);
            $slug = $baseSlug;
            $counter = 1;
            while (Tenant::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }

            // Create tenant with Pro limits during trial
            // This encourages users to stay on Pro after trial ends
            $tenant = Tenant::create([
                'name' => $request->store_name,
                'slug' => $slug,
                'email' => $request->email,
                'plan' => Tenant::PLAN_TRIAL,
                'trial_ends_at' => now()->addDays(14),
                'messages_limit' => Tenant::PLAN_LIMITS[Tenant::PLAN_PRO], // Pro limits during trial!
                'status' => Tenant::STATUS_ACTIVE,
            ]);

            // Create default widget settings
            $tenant->widgetSettings()->create([
                'domain' => $slug . '.aimbot.com.ua',
                'primary_color' => '#2563EB',
                'welcome_message' => 'Привіт! Чим можу допомогти?',
                'position' => 'bottom-right',
            ]);

            // Create default proactive triggers
            app(DefaultTriggerService::class)->createDefaultTriggers($tenant);

            // Create user as owner
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'tenant_id' => $tenant->id,
                'role' => User::ROLE_OWNER,
            ]);

            // Dispatch onboarding job (async - sync products, categories, AI, Meili)
            // This will run after user completes onboarding wizard and configures Horoshop
            \App\Jobs\OnboardTenantJob::dispatch($tenant->id)
                ->onQueue('default')
                ->delay(now()->addMinutes(5)); // Delay to allow user to configure Horoshop first

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        // Redirect to onboarding wizard
        return redirect(route('onboarding.index', absolute: false));
    }
}

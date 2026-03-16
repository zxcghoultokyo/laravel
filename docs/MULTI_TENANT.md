# Multi-Tenant Architecture

## Overview

AIntento is a **multi-tenant SaaS** where each tenant (customer) has isolated data:
- Products catalog
- Widget settings & customization
- Chat sessions & messages
- Analytics data
- Billing/subscription

## Database Schema

### Tenants Table
```sql
CREATE TABLE tenants (
    id BIGINT PRIMARY KEY,
    name VARCHAR(255),              -- Business name
    slug VARCHAR(255) UNIQUE,       -- URL-friendly identifier
    user_id BIGINT,                 -- Owner user
    
    -- API Credentials
    widget_key VARCHAR(64) UNIQUE,  -- Public API key for widget
    api_key VARCHAR(64) UNIQUE,     -- Private API key
    
    -- Platform Integration
    platform VARCHAR(50),           -- horoshop, shopify, woocommerce
    platform_domain VARCHAR(255),   -- https://shop.horoshop.ua
    platform_credentials JSON,      -- Encrypted API credentials
    
    -- Subscription
    plan VARCHAR(50),               -- starter, pro, enterprise
    plan_expires_at TIMESTAMP,
    trial_ends_at TIMESTAMP,
    
    -- Settings
    settings JSON,                  -- Widget config, prompts, etc.
    
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Tenant-Scoped Tables
```sql
-- All tenant data tables have tenant_id foreign key
products (tenant_id, ...)
categories (tenant_id, ...)
chat_sessions (tenant_id, ...)
chat_messages (tenant_id via chat_session)
widget_settings (tenant_id, ...)
```

## Key Components

### 1. Tenant Model
```php
// app/Models/Tenant.php

class Tenant extends Model
{
    // Subscription methods
    public function isOnTrial(): bool
    public function isTrialExpired(): bool
    public function hasActiveSubscription(): bool
    public function canUseWidget(): bool
    
    // Relations
    public function user(): BelongsTo
    public function products(): HasMany
    public function chatSessions(): HasMany
    public function widgetSettings(): HasOne
}
```

### 2. Tenant Scope
```php
// app/Scopes/TenantScope.php

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($tenantId = app('tenant.id')) {
            $builder->where('tenant_id', $tenantId);
        }
    }
}
```

### 3. BelongsToTenant Trait
```php
// app/Models/Traits/BelongsToTenant.php

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);
        
        static::creating(function ($model) {
            if (!$model->tenant_id && $tenantId = app('tenant.id')) {
                $model->tenant_id = $tenantId;
            }
        });
    }
    
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
```

### 4. ResolveTenantMiddleware
```php
// app/Http/Middleware/ResolveTenantMiddleware.php

class ResolveTenantMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $tenant = null;
        
        // 1. From authenticated user
        if ($user = $request->user()) {
            $tenant = $user->tenant;
        }
        
        // 2. From widget_key parameter
        if (!$tenant && $widgetKey = $request->input('widget_key')) {
            $tenant = Tenant::where('widget_key', $widgetKey)->first();
        }
        
        // 3. From X-Tenant-Key header
        if (!$tenant && $apiKey = $request->header('X-Tenant-Key')) {
            $tenant = Tenant::where('api_key', $apiKey)->first();
        }
        
        if ($tenant) {
            app()->instance('tenant', $tenant);
            app()->instance('tenant.id', $tenant->id);
        }
        
        return $next($request);
    }
}
```

## Usage Examples

### In Controllers
```php
// Get current tenant
$tenant = app('tenant');
$tenantId = app('tenant.id');

// Products automatically scoped
$products = Product::all(); // Only current tenant's products
```

### In API Routes
```php
// Widget identifies tenant by widget_key
Route::get('/api/chat/stream', [StreamingChatController::class, 'stream'])
    ->middleware('tenant.context');

// Request includes widget_key
GET /api/chat/stream?widget_key=wk_abc123&message=hello
```

### In Jobs
```php
// Pass tenant_id to job
dispatch(new SyncProductsJob($tenant->id));

// In job, set context
public function handle()
{
    $tenant = Tenant::find($this->tenantId);
    app()->instance('tenant.id', $tenant->id);
    
    // Now all queries are scoped
    $products = Product::all();
}
```

## Tenant Isolation

### Data Isolation
- **Products**: Each tenant has own product catalog
- **Categories**: Tenant-specific category tree
- **Chat History**: Sessions & messages isolated
- **Analytics**: Separate metrics per tenant

### Configuration Isolation
- **Widget Settings**: Colors, position, greeting
- **Prompts**: Custom AI prompts per tenant
- **Platform Credentials**: Encrypted per tenant

### Billing Isolation
- **Plans**: Each tenant has own subscription
- **Limits**: Messages/products limits enforced
- **Trial**: Independent trial period

## API Authentication

### Public Widget API
```
widget_key: wk_xxxxxxxxxxxx
```
- Used in JavaScript widget on customer sites
- Read-only access to chat functionality
- Rate limited per key

### Private API
```
api_key: ak_xxxxxxxxxxxx
```
- Used for server-to-server integrations
- Full CRUD access to tenant data
- Used in sync jobs

### Admin API
```
Authorization: Bearer {user_token}
```
- For dashboard/admin access
- Scoped to user's tenants

## Security Considerations

1. **Query Scoping**: TenantScope prevents cross-tenant data access
2. **Key Validation**: All API requests validate tenant credentials
3. **Widget Blocking**: `canUseWidget()` checks subscription status
4. **Rate Limiting**: Per-tenant rate limits on API
5. **Data Encryption**: Platform credentials encrypted at rest

## Testing

```php
// Test tenant isolation
public function test_products_are_tenant_scoped()
{
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();
    
    Product::factory()->for($tenant1)->create(['title' => 'Product 1']);
    Product::factory()->for($tenant2)->create(['title' => 'Product 2']);
    
    // Set tenant context
    app()->instance('tenant.id', $tenant1->id);
    
    // Should only see tenant1's products
    $products = Product::all();
    $this->assertCount(1, $products);
    $this->assertEquals('Product 1', $products->first()->title);
}
```

## Adding New Tenant-Scoped Models

1. Add `tenant_id` column to migration:
```php
$table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
```

2. Use `BelongsToTenant` trait:
```php
use App\Models\Traits\BelongsToTenant;

class YourModel extends Model
{
    use BelongsToTenant;
}
```

3. Model automatically:
   - Scopes queries to current tenant
   - Sets `tenant_id` on create
   - Defines `tenant()` relationship

## File Structure

```
app/
├── Models/
│   ├── Tenant.php              # Core tenant model
│   └── Traits/
│       └── BelongsToTenant.php # Trait for tenant-scoped models
├── Scopes/
│   └── TenantScope.php         # Global query scope
├── Http/
│   └── Middleware/
│       ├── ResolveTenantMiddleware.php    # Request middleware
│       └── CheckTenantLimitsMiddleware.php # Usage limits check
└── Providers/
    └── AppServiceProvider.php  # Binds tenant to container
```

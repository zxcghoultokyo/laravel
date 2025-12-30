# A/B Testing System for AI Models

## Overview

This system allows comparing different AI configurations (models, prompts, parameters) to find the best performing variant for your users.

## Database Schema

```
ab_experiments     - Experiment definitions
ab_assignments     - Which user got which variant
ab_conversions     - Success events (clicks, purchases)
ab_metrics         - Performance metrics (response time, tokens)
```

## How It Works

```
┌─────────────┐     ┌──────────────┐     ┌─────────────┐
│   User      │────▶│  Experiment  │────▶│   Variant   │
│ (session)   │     │   Router     │     │   (A or B)  │
└─────────────┘     └──────────────┘     └─────────────┘
                           │
                           ▼
                    ┌──────────────┐
                    │   Metrics    │
                    │   Tracking   │
                    └──────────────┘
```

1. User sends chat message
2. System checks if active experiment exists
3. Assigns user to variant based on session_id hash (deterministic)
4. Applies variant config (different model, temperature, etc.)
5. Records metrics (response time, success)
6. Tracks conversions (product clicks, purchases)

## Creating an Experiment

```php
use App\Models\AbExperiment;

$experiment = AbExperiment::create([
    'name' => 'gpt4_vs_gpt4mini',
    'description' => 'Compare GPT-4.1 vs GPT-4.1-mini for product search',
    'is_active' => true,
    'traffic_percent' => 50, // Only 50% of users in experiment
    'variants' => [
        'control' => [
            'model' => 'gpt-4.1',
            'temperature' => 0.3,
        ],
        'treatment' => [
            'model' => 'gpt-4.1-mini',
            'temperature' => 0.5,
        ],
    ],
    'started_at' => now(),
]);
```

## Variant Assignment Logic

```php
// Deterministic assignment based on session_id
// Same user always gets same variant

function getVariant(string $sessionId, array $variants): string
{
    $hash = crc32($sessionId);
    $variantKeys = array_keys($variants);
    $index = $hash % count($variantKeys);
    return $variantKeys[$index];
}
```

## Integration Points

### 1. In AiRouter (Future)

```php
public function classify(string $message, ?string $sessionId = null): array
{
    // Get experiment variant
    $variant = $this->abService->getVariant($sessionId, 'ai_model_experiment');
    
    // Apply variant config
    $model = $variant['model'] ?? $this->model;
    $temperature = $variant['temperature'] ?? 0.2;
    
    // ... use config in API call
}
```

### 2. In ChatController (Future)

```php
// Track response time
$startTime = microtime(true);
$response = $this->chatService->handleMessage($message, $sessionId);
$duration = (microtime(true) - $startTime) * 1000;

// Record metric
$this->abService->recordMetric($sessionId, $requestId, [
    'response_time_ms' => $duration,
    'tokens_used' => $response['meta']['tokens'] ?? null,
]);
```

### 3. Conversion Tracking (Future)

```php
// When user clicks product
$this->abService->trackConversion($sessionId, 'product_click', [
    'product_id' => $productId,
]);

// When user adds to cart
$this->abService->trackConversion($sessionId, 'add_to_cart', [
    'product_id' => $productId,
    'price' => $price,
]);
```

## Analysis Queries

### Conversion Rate by Variant

```sql
SELECT 
    e.name as experiment,
    c.variant,
    COUNT(DISTINCT c.session_id) as conversions,
    COUNT(DISTINCT a.session_id) as total_users,
    ROUND(COUNT(DISTINCT c.session_id) * 100.0 / COUNT(DISTINCT a.session_id), 2) as conversion_rate
FROM ab_experiments e
JOIN ab_assignments a ON a.experiment_id = e.id
LEFT JOIN ab_conversions c ON c.experiment_id = e.id AND c.session_id = a.session_id
WHERE e.name = 'gpt4_vs_gpt4mini'
GROUP BY e.name, c.variant;
```

### Average Response Time by Variant

```sql
SELECT 
    variant,
    COUNT(*) as requests,
    AVG(response_time_ms) as avg_response_ms,
    PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY response_time_ms) as p95_response_ms
FROM ab_metrics
WHERE experiment_id = 1
GROUP BY variant;
```

### Statistical Significance (Chi-Square)

```php
// To determine if results are statistically significant:
// - Need at least 100 samples per variant
// - Use chi-square test for conversion rates
// - p-value < 0.05 = statistically significant

// Example with 2 variants:
$controlConversions = 45;
$controlTotal = 500;
$treatmentConversions = 62;
$treatmentTotal = 500;

// Calculate chi-square (simplified)
// Use library like php-stats for proper calculation
```

## Experiment Lifecycle

```
1. DRAFT     - Experiment created, not active
2. RUNNING   - is_active=true, collecting data
3. PAUSED    - is_active=false, can resume
4. COMPLETED - ended_at set, winner declared
5. ARCHIVED  - Data retained for historical analysis
```

## Best Practices

### 1. Run One Experiment at a Time
Multiple experiments on same users can interfere with each other.

### 2. Minimum Sample Size
Wait for at least 1000 sessions per variant before making decisions.

### 3. Duration
Run experiment for at least 7 days to account for weekly patterns.

### 4. Document Hypotheses
Before starting, write down:
- What you're testing
- Why you expect treatment to be better
- Primary metric (conversion rate, response time, etc.)

### 5. Don't Peek
Decide sample size upfront. Don't stop early just because results look good.

## Future Admin Panel Features

- [ ] Create/edit experiments
- [ ] Real-time dashboard with metrics
- [ ] Statistical significance calculator
- [ ] Auto-stop when significance reached
- [ ] Winner deployment (make treatment the new default)

## Config Reference

```php
// config/ab_testing.php (future)
return [
    'enabled' => env('AB_TESTING_ENABLED', false),
    'default_traffic_percent' => 100,
    'min_sample_size' => 1000,
    'significance_level' => 0.05,
];
```

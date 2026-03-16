<?php

namespace App\Console\Commands;

use App\Services\Ai\TenantPromptGenerator;
use Illuminate\Console\Command;

class GenerateTenantPrompt extends Command
{
    protected $signature = 'tenant:generate-prompt
        {tenant_id : ID тенанта}
        {--dry-run : Тільки показати промпт, не зберігати}
        {--all : Згенерувати для всіх активних тенантів}';

    protected $description = 'Генерувати системний промпт для тенанта на основі його каталогу';

    public function handle(TenantPromptGenerator $generator): int
    {
        if ($this->option('all')) {
            return $this->generateAll($generator);
        }

        $tenantId = (int) $this->argument('tenant_id');
        $dryRun = $this->option('dry-run');

        $this->info("Аналізую тенант #{$tenantId}...");

        $result = $generator->generate($tenantId, $dryRun);
        $analysis = $result['analysis'];

        $this->table(['Параметр', 'Значення'], [
            ['Магазин', $analysis['store_name']],
            ['Домен', $analysis['domain']],
            ['Товарів', $analysis['total_products']],
            ['В наявності', $analysis['in_stock_count']],
            ['Ціни', "{$analysis['price_min']} – {$analysis['price_max']} грн"],
            ['Дитячий магазин', $analysis['has_age_categories'] ? 'ТАК' : 'НІ'],
            ['Довжина промпту', "{$result['prompt_length']} символів"],
        ]);

        $this->newLine();
        $this->line('📋 Категорії: '.implode(', ', array_keys($analysis['top_level_categories'])));
        $this->line('🏷️  Бренди: '.implode(', ', array_keys($analysis['brands'])));

        if ($dryRun) {
            $this->newLine();
            $this->warn('=== DRY RUN — промпт НЕ збережено ===');
            $this->newLine();
            $this->line($result['prompt']);
        } else {
            $this->newLine();
            $this->info("✅ Збережено як пресет #{$result['preset_id']}");
        }

        return self::SUCCESS;
    }

    private function generateAll(TenantPromptGenerator $generator): int
    {
        $tenants = \App\Models\Tenant::where('status', 'active')->get();

        foreach ($tenants as $tenant) {
            $this->info("Генерую для #{$tenant->id} ({$tenant->name})...");

            try {
                $result = $generator->generate($tenant->id);
                $this->line("  ✅ Пресет #{$result['preset_id']} ({$result['prompt_length']} символів)");
            } catch (\Throwable $e) {
                $this->error("  ❌ {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\IndexProductsToMeiliJob;
use App\Models\PromptPreset;
use App\Scopes\TenantScope;
use Illuminate\Console\Command;

class FixBavkatoysPrompts extends Command
{
    protected $signature = 'prompts:fix-bavkatoys
        {--tenant=20 : Tenant ID}
        {--skip-reindex : Не запускати переіндексацію Meili}
        {--dry-run : Показати зміни без збереження}';

    protected $description = 'Фікс пресетів bavkatoys: прибрати хардкод у подарунках, додати exclude PDF/аксесуарів, глобалізувати overlay комплектів + reindex Meili';

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');
        $dry = (bool) $this->option('dry-run');

        $base = PromptPreset::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->first();

        $scripts = PromptPreset::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('slug', 'skripti-castix-pitan')
            ->first();

        $bundle = PromptPreset::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('slug', 'bavka-razom-vigidnise-komplekti')
            ->first();

        if (! $base || ! $scripts || ! $bundle) {
            $this->error('Не знайдено один з пресетів (base/scripts/bundle). Перевір адмінку.');
            $this->line('base: '.($base ? 'OK' : 'MISSING'));
            $this->line('scripts: '.($scripts ? 'OK' : 'MISSING'));
            $this->line('bundle: '.($bundle ? 'OK' : 'MISSING'));

            return self::FAILURE;
        }

        $baseAddition = <<<'TXT'

ПОДАРУНОК-БЕЗПЕКА (доповнення до блоку ПОДАРУНОК):
- НІКОЛИ не включай у добірку подарунків цифрові товари (PDF, електронні зошити, картки для друку, завантажувані матеріали), якщо клієнт ПРЯМО не попросив PDF/цифровий подарунок.
- НІКОЛИ не пропонуй як самостійний подарунок ці типи товарів (це аксесуари/складові, не подарунки):
  фартух, дошка, ніж, качалка, ложки окремо, совок, віник, швабра, пакет/пакування/коробка/листівка, таця для триступеневих карток окремо.
  Їх можна пропонувати ТІЛЬКИ як доповнення після вибору основного подарунка.
- Якщо в каталозі є готовий КОМПЛЕКТ/НАБІР/"Разом вигідніше" що підходить по віку — пропонуй його ПЕРШИМ, окремі товари потім.
TXT;

        $baseSysPrompt = (string) $base->system_prompt;
        $baseChanged = false;
        if (! str_contains($baseSysPrompt, 'ПОДАРУНОК-БЕЗПЕКА')) {
            $baseSysPrompt = rtrim($baseSysPrompt)."\n".$baseAddition."\n";
            $baseChanged = true;
            $this->line('[base] додаю блок ПОДАРУНОК-БЕЗПЕКА');
        } else {
            $this->line('[base] блок ПОДАРУНОК-БЕЗПЕКА вже присутній');
        }

        $scriptsSys = (string) $scripts->system_prompt;
        $scriptsOld = 'Найкращі рекомендовані товари для цього запиту: набір для новонароджених, Такане, Контрастний набір, Тренажер перекладина, комфортер, топпочніно.';
        $scriptsNew = 'Добирай 2-4 основні ідеї через search_products СТРОГО за віком дитини (DljaDtok/Вік). Не використовуй фіксований список — каталог міняється, і набір для 0-1 не підходить на рік чи 3 роки. Для "популярне" — сортуй по popularity/orders_count.';
        $scriptsChanged = false;

        if (str_contains($scriptsSys, $scriptsOld)) {
            $scriptsSys = str_replace($scriptsOld, $scriptsNew, $scriptsSys);
            $scriptsChanged = true;
            $this->line('[scripts] замінено хардкоднутий список товарів');
        } elseif (! str_contains($scriptsSys, 'Не використовуй фіксований список')) {
            $this->warn('[scripts] оригінальний рядок не знайдено, додаю примітку в кінець');
            $scriptsSys = rtrim($scriptsSys)."\n\nВАЖЛИВО ДЛЯ ПОДАРУНКІВ: Не використовуй фіксовані списки товарів — завжди через search_products за віком + popularity.\n";
            $scriptsChanged = true;
        } else {
            $this->line('[scripts] вже виправлено');
        }

        $bundleCats = $bundle->categories;
        $bundleChangedCats = false;
        if (! empty($bundleCats)) {
            $bundleChangedCats = true;
            $this->line('[bundle] знімаю прив\'язку до категорій (було: '.count((array) $bundleCats).')');
        } else {
            $this->line('[bundle] вже глобальний');
        }

        if ($dry) {
            $this->warn('DRY-RUN: зміни не збережено');
        } else {
            if ($baseChanged) {
                $base->system_prompt = $baseSysPrompt;
                $base->save();
            }
            if ($scriptsChanged) {
                $scripts->system_prompt = $scriptsSys;
                $scripts->save();
            }
            if ($bundleChangedCats) {
                $bundle->categories = null;
                $bundle->save();
            }
            $this->info('Пресети оновлено.');
        }

        if (! $this->option('skip-reindex') && ! $dry) {
            IndexProductsToMeiliJob::dispatch($tenantId, 500)->onQueue('meili');
            $this->info("Queue: dispatched IndexProductsToMeiliJob для tenant {$tenantId}");
        }

        return self::SUCCESS;
    }
}

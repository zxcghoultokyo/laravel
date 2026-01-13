<?php

namespace Database\Seeders;

use App\Models\WidgetSettings;
use Illuminate\Database\Seeder;

/**
 * Seeds example store context for Contractor (tactical gear store).
 * 
 * This demonstrates how to configure store-specific AI behavior
 * without hardcoding it in the codebase.
 */
class StoreContextSeeder extends Seeder
{
    public function run(): void
    {
        // Update existing or create new widget settings with AI context
        WidgetSettings::updateOrCreate(
            ['domain' => 'atk.ua'], // Contractor's domain
            [
                // Store identity
                'store_name' => 'Contractor',
                'store_context' => ' (тактичне військове спорядження)',
                'store_description' => 'Професійний магазин тактичного військового спорядження. Продаємо: бронежилети, шоломи, плитоноски, бронеплити, тактичний одяг, взуття, рюкзаки, підсумки, рукавиці, окуляри.',
                
                // Customer types (for AI context)
                'customer_types' => [
                    'військові ЗСУ',
                    'правоохоронці', 
                    'добровольці',
                    'цивільні патріоти',
                ],
                
                // Product categories (for AI understanding)
                'product_categories' => [
                    'плитоноски',
                    'шоломи',
                    'бронеплити',
                    'берці',
                    'рюкзаки',
                    'форма',
                    'підсумки',
                    'аксесуари',
                ],
                
                // Accessory keywords (for filtering)
                'accessory_keywords' => [
                    'ремінь',
                    'кріплення',
                    'чохол',
                    'кавер',
                    'панель',
                    'камбербанд',
                    'адаптер',
                    'патч',
                    'шеврон',
                    'підсумок', // can be main or accessory depending on context
                ],
                
                // Main product keywords
                'main_product_keywords' => [
                    'плитоноска',
                    'шолом',
                    'каска',
                    'бронеплита',
                    'берці',
                    'рюкзак',
                    'куртка',
                    'штани',
                    'жилет',
                ],
                
                // Brand transliterations (cyrillic -> latin)
                'brand_transliterations' => [
                    'опс коре' => 'Ops-Core',
                    'опскор' => 'Ops-Core',
                    'салзмон' => 'Salomon',
                    'саломон' => 'Salomon',
                    'фірст спір' => 'FirstSpear',
                    'генте' => 'Gentex',
                    'гентекс' => 'Gentex',
                    'край' => 'Crye Precision',
                    'край пресижн' => 'Crye Precision',
                    '3м пелтор' => '3M Peltor',
                    'пелтор' => '3M Peltor',
                    'есапі' => 'ESAPI',
                    'есапай' => 'ESAPI',
                    'сестан буш' => 'SESTAN BUSCH',
                    'сестан' => 'SESTAN BUSCH',
                    'хайлікс' => 'Hailex',
                    'хайлекс' => 'Hailex',
                    'арморком' => 'ArmorCom',
                    'уар' => 'UaR',
                    'юар' => 'UaR',
                    'темпларс гір' => 'Templars Gear',
                    'пецл' => 'Petzl',
                    'петцль' => 'Petzl',
                    'смт' => 'SMT',
                ],
                
                // Store hours
                'store_hours' => 'Пн-Пт: 9:00-18:00, Сб: 10:00-15:00',
                
                // AI behavior settings
                'ai_use_dynamic_prompts' => true,
                'ai_strict_category_filter' => false,
            ]
        );
        
        $this->command->info('Store context seeded for Contractor (atk.ua)');
    }
}

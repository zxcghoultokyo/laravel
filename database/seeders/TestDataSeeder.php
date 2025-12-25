<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\ColorSynonym;
use App\Models\Product;
use App\Models\ProductSynonym;
use Illuminate\Database\Seeder;

/**
 * Seeder for test data based on real production data.
 * Use: php artisan db:seed --class=TestDataSeeder
 */
class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedBrands();
        $this->seedColorSynonyms();
        $this->seedProductSynonyms();
        $this->seedTestProducts();
    }

    private function seedBrands(): void
    {
        $brands = [
            ['name' => 'KOMBAT UK', 'slug' => 'kombat-uk', 'product_count' => 530],
            ['name' => 'АТАКА', 'slug' => 'ataka', 'product_count' => 441],
            ['name' => 'HOFFMANN', 'slug' => 'hoffmann', 'product_count' => 134],
            ['name' => 'EastGear', 'slug' => 'eastgear', 'product_count' => 96],
            ['name' => 'USA Army', 'slug' => 'usa-army', 'product_count' => 66],
            ['name' => 'RAGNAROK', 'slug' => 'ragnarok', 'product_count' => 26],
            ['name' => 'Carinthia', 'slug' => 'carinthia', 'product_count' => 22],
            ['name' => 'U.S. Army', 'slug' => 'us-army', 'product_count' => 22],
            ['name' => 'Salomon', 'slug' => 'salomon', 'product_count' => 15],
            ['name' => 'Mechanix', 'slug' => 'mechanix', 'product_count' => 13],
            ['name' => 'ESDY', 'slug' => 'esdy', 'product_count' => 10],
            ['name' => 'Garmin', 'slug' => 'garmin', 'product_count' => 8],
            ['name' => 'FMA', 'slug' => 'fma', 'product_count' => 8],
            ['name' => 'Earmor', 'slug' => 'earmor', 'product_count' => 5],
            ['name' => 'Peltor', 'slug' => 'peltor', 'product_count' => 3],
            ['name' => 'Crye Precision', 'slug' => 'crye-precision', 'product_count' => 4],
            ['name' => 'OPS-CORE', 'slug' => 'ops-core', 'product_count' => 2],
            ['name' => 'Agilite', 'slug' => 'agilite', 'product_count' => 1],
            ['name' => 'NAR', 'slug' => 'nar', 'product_count' => 1],
            ['name' => 'CAT', 'slug' => 'cat', 'product_count' => 1],
        ];

        foreach ($brands as $brand) {
            Brand::updateOrCreate(
                ['slug' => $brand['slug']],
                array_merge($brand, ['is_active' => true])
            );
        }

        $this->command->info('✓ Seeded ' . count($brands) . ' brands');
    }

    private function seedColorSynonyms(): void
    {
        $colors = [
            'black' => ['чорний', 'чорна', 'чорне', 'черный', 'black', 'blk', 'Чорний'],
            'multicam' => ['мультикам', 'мультікам', 'multicam', 'mc', 'мульт', 'Мультикам', 'мультикам чорний', 'мультікам чорний', 'Black Multicam'],
            'olive' => ['олива', 'оливковий', 'оліва', 'olive', 'od', 'ranger green', 'Оливковий', 'Олива', 'рейнджер грін'],
            'pixel' => ['піксель', 'пиксель', 'mm14', 'мм14', 'укрпіксель', 'pixel', 'Піксель', 'ММ14', 'український піксель'],
            'coyote' => ['койот', 'coyote', 'Койот', 'coyote brown', 'cb'],
            'tan' => ['тан', 'tan', 'fde', 'пісочний', 'flat dark earth', 'Пісочний'],
            'khaki' => ['хакі', 'хаки', 'khaki', 'Хаки', 'Хакі'],
            'green' => ['зелений', 'зелена', 'green', 'Зелений', 'зелений хакі'],
            'brown' => ['коричневий', 'коричнева', 'brown', 'Коричневий'],
            'camo' => ['камуфляж', 'камо', 'camo', 'Камуфляж', 'camouflage'],
            'white' => ['білий', 'біла', 'white', 'Білий'],
            'red' => ['червоний', 'червона', 'red', 'Червоний'],
            'blue' => ['синій', 'синя', 'blue', 'Синій'],
            'alpine_mc' => ['alpine mc', 'Alpine MC', 'альпійський мультикам'],
            'tropical_mc' => ['тропічний мультикам', 'Тропічний мультикам', 'tropical multicam', 'tropic mc'],
        ];

        $count = 0;
        foreach ($colors as $group => $synonyms) {
            foreach ($synonyms as $i => $synonym) {
                ColorSynonym::updateOrCreate(
                    ['color_group' => $group, 'synonym' => $synonym],
                    [
                        'language' => $this->detectLanguage($synonym),
                        'is_primary' => $i === 0,
                        'is_active' => true,
                    ]
                );
                $count++;
            }
        }

        $this->command->info("✓ Seeded {$count} color synonyms in " . count($colors) . " groups");
    }

    private function seedProductSynonyms(): void
    {
        $types = [
            'плитоноски' => ['плитоноска', 'бронік', 'plate carrier', 'pc', 'бронежилет', 'жилет', 'носій плит', 'броник', 'плитник', 'тактичний жилет'],
            'турнікети' => ['турнікет', 'cat', 'tq', 'джгут', 'кровоспин', 'турник', 'жгут', 'турнікет cat', 'кровоспинний джгут'],
            'шоломи' => ['шолом', 'каска', 'helmet', 'балістичний шолом', 'кевлар', 'шлем', 'балістика', 'тактичний шолом', 'бойовий шолом'],
            'бронеплити' => ['бронеплита', 'плита', 'plate', 'sapi', 'esapi', 'броня', 'керамічна плита', 'бронепластина', 'балістична плита'],
            'підсумки' => ['підсумок', 'pouch', 'mag pouch', 'кишеня', 'сумка', 'подсумок', 'підсумок для магазинів', 'адмін підсумок'],
            'рюкзаки' => ['рюкзак', 'backpack', 'ранець', 'pack', 'тактичний рюкзак', 'штурмовий рюкзак', 'assault pack'],
            'берці' => ['берці', 'берцы', 'boots', 'черевики', 'ботинки', 'тактичні черевики', 'військові черевики', 'combat boots'],
            'рукавиці' => ['рукавиці', 'рукавички', 'gloves', 'перчатки', 'тактичні рукавиці', 'бойові рукавиці'],
            'окуляри' => ['окуляри', 'очки', 'glasses', 'балістичні окуляри', 'goggles', 'тактичні окуляри', 'захисні окуляри'],
            'навушники' => ['навушники', 'наушники', 'earmor', 'peltor', 'активні навушники', 'тактичні навушники', 'headset'],
            'ліхтарі' => ['ліхтар', 'ліхтарик', 'flashlight', 'фонарь', 'фонарик', 'torch', 'тактичний ліхтар', 'weapon light'],
            'ножі' => ['ніж', 'нож', 'knife', 'клинок', 'тактичний ніж', 'бойовий ніж', 'складний ніж'],
            'аптечки' => ['аптечка', 'ifak', 'медичний підсумок', 'мед кіт', 'first aid', 'медична сумка', 'тактична аптечка'],
            'футболки' => ['футболка', 'tshirt', 't-shirt', 'майка', 'тактична футболка', 'бойова футболка'],
            'штани' => ['штани', 'брюки', 'pants', 'тактичні штани', 'бойові штани', 'combat pants', 'карго'],
            'куртки' => ['куртка', 'jacket', 'тактична куртка', 'софтшел', 'softshell', 'бойова куртка'],
            'камербанди' => ['камербанд', 'cummerbund', 'боковий захист', 'бокова панель'],
            'ремені' => ['ремінь', 'пояс', 'belt', 'тактичний ремінь', 'бойовий пояс', 'war belt'],
        ];

        $count = 0;
        foreach ($types as $type => $synonyms) {
            foreach ($synonyms as $synonym) {
                ProductSynonym::updateOrCreate(
                    ['product_type' => $type, 'synonym' => mb_strtolower($synonym)],
                    [
                        'language' => $this->detectLanguage($synonym),
                        'weight' => 1,
                        'is_active' => true,
                    ]
                );
                $count++;
            }
        }

        $this->command->info("✓ Seeded {$count} product synonyms in " . count($types) . " types");
    }

    private function seedTestProducts(): void
    {
        $products = [
            [
                'article' => 'TEST-PC-001',
                'title' => 'Плитоноска АТАКА Архангел EVO Мультикам',
                'price' => 12000,
                'category_path' => 'Тактичне спорядження/Плитоноски',
                'brand' => 'АТАКА',
                'color' => 'Мультикам',
                'in_stock' => true,
                'quantity' => 5,
                'popularity' => 100,
            ],
            [
                'article' => 'TEST-PC-002',
                'title' => 'Плитоноска НАТО Classic Піксель',
                'price' => 4695,
                'category_path' => 'Тактичне спорядження/Плитоноски',
                'brand' => 'KOMBAT UK',
                'color' => 'Піксель',
                'in_stock' => true,
                'quantity' => 10,
                'popularity' => 80,
            ],
            [
                'article' => 'TEST-PC-003',
                'title' => 'Плитоноска "Схід 24" Піксель',
                'price' => 6895,
                'category_path' => 'Тактичне спорядження/Плитоноски',
                'brand' => 'EastGear',
                'color' => 'Піксель',
                'in_stock' => true,
                'quantity' => 3,
                'popularity' => 70,
            ],
            [
                'article' => 'TEST-PC-004',
                'title' => 'Плитоноска Kiborg GU gen.2 Мультикам',
                'price' => 4950,
                'category_path' => 'Тактичне спорядження/Плитоноски',
                'brand' => 'Kiborg',
                'color' => 'мультікам',
                'in_stock' => true,
                'quantity' => 2,
                'popularity' => 90,
            ],
            [
                'article' => 'TEST-HELMET-001',
                'title' => 'Шолом балістичний FAST NIJ IIIA Олива',
                'price' => 15000,
                'category_path' => 'Тактичне спорядження/Шоломи',
                'brand' => 'HOFFMANN',
                'color' => 'Олива',
                'in_stock' => true,
                'quantity' => 4,
                'popularity' => 85,
            ],
            [
                'article' => 'TEST-PLATE-001',
                'title' => 'Бронеплита SAPI керамічна 25x30 NIJ IV',
                'price' => 18000,
                'category_path' => 'Тактичне спорядження/Бронеплити',
                'brand' => 'УКРБРОНЯ',
                'color' => null,
                'in_stock' => true,
                'quantity' => 8,
                'popularity' => 95,
            ],
            [
                'article' => 'TEST-SLING-001',
                'title' => 'Одноточковий ремінь до плитоноски RAGNAROK Мультикам',
                'price' => 1760,
                'category_path' => 'Тактичне спорядження/Аксесуари та комплектуючі для зброї/Збройові ремені/2-точкові',
                'brand' => 'RAGNAROK',
                'color' => 'Мультикам',
                'in_stock' => true,
                'quantity' => 15,
                'popularity' => 40,
            ],
            [
                'article' => 'TEST-PANEL-001',
                'title' => 'Панель штурмова KOMBAT UK Guardian Assault Panel',
                'price' => 1530,
                'category_path' => 'Тактичне спорядження/Плитоноски',
                'brand' => 'KOMBAT UK',
                'color' => 'мультікам',
                'in_stock' => true,
                'quantity' => 1,
                'popularity' => 30,
            ],
        ];

        foreach ($products as $data) {
            Product::updateOrCreate(
                ['article' => $data['article']],
                array_merge($data, [
                    'search_index' => mb_strtolower($data['title'] . ' ' . ($data['brand'] ?? '') . ' ' . ($data['color'] ?? '')),
                ])
            );
        }

        $this->command->info('✓ Seeded ' . count($products) . ' test products');
    }

    private function detectLanguage(string $text): string
    {
        if (preg_match('/[а-яіїєґ]/ui', $text)) {
            return preg_match('/[іїєґ]/ui', $text) ? 'uk' : 'ru';
        }
        return 'en';
    }
}

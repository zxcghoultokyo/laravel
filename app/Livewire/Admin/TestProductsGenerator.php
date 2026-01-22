<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TestProductsGenerator extends Component
{
    public int $productCount = 1000;
    public string $shopType = 'plumbing';

    protected array $plumbingCategories = [
        'Змішувачі/Для кухні' => [
            'prefix' => 'ZMK',
            'products' => ['Змішувач кухонний', 'Кран кухонний', 'Змішувач з висувним виливом', 'Змішувач з фільтром'],
            'brands' => ['Grohe', 'Hansgrohe', 'Kludi', 'Ideal Standard', 'Ravak', 'Imprese', 'Cersanit'],
            'price_range' => [800, 12000],
        ],
        'Змішувачі/Для ванни' => [
            'prefix' => 'ZMV',
            'products' => ['Змішувач для ванни', 'Змішувач з душем', 'Термостатичний змішувач', 'Каскадний змішувач'],
            'brands' => ['Grohe', 'Hansgrohe', 'Kludi', 'Ideal Standard', 'Ravak', 'Imprese'],
            'price_range' => [1200, 18000],
        ],
        'Змішувачі/Для умивальника' => [
            'prefix' => 'ZMU',
            'products' => ['Змішувач для умивальника', 'Кран для раковини', 'Сенсорний змішувач', 'Високий змішувач'],
            'brands' => ['Grohe', 'Hansgrohe', 'Kludi', 'Ideal Standard', 'Ravak', 'Imprese', 'Cersanit'],
            'price_range' => [600, 8000],
        ],
        'Унітази/Підлогові' => [
            'prefix' => 'UNP',
            'products' => ['Унітаз підлоговий', 'Унітаз-компакт', 'Унітаз з бачком', 'Унітаз безободковий'],
            'brands' => ['Cersanit', 'Roca', 'Villeroy & Boch', 'Duravit', 'Geberit', 'Kolo'],
            'price_range' => [2500, 25000],
        ],
        'Унітази/Підвісні' => [
            'prefix' => 'UNV',
            'products' => ['Унітаз підвісний', 'Унітаз інсталяція', 'Унітаз безободковий підвісний', 'Унітаз з біде'],
            'brands' => ['Cersanit', 'Roca', 'Villeroy & Boch', 'Duravit', 'Geberit', 'Grohe'],
            'price_range' => [3500, 35000],
        ],
        'Раковини/Накладні' => [
            'prefix' => 'RKN',
            'products' => ['Раковина накладна', 'Умивальник на стільницю', 'Раковина-чаша', 'Умивальник овальний'],
            'brands' => ['Cersanit', 'Roca', 'Villeroy & Boch', 'Duravit', 'Ideal Standard'],
            'price_range' => [1500, 15000],
        ],
        'Раковини/Вбудовані' => [
            'prefix' => 'RKV',
            'products' => ['Раковина вбудована', 'Умивальник врізний', 'Раковина під стільницю', 'Умивальник меблевий'],
            'brands' => ['Cersanit', 'Roca', 'Villeroy & Boch', 'Duravit', 'Kolo'],
            'price_range' => [1200, 12000],
        ],
        'Ванни/Акрилові' => [
            'prefix' => 'VNA',
            'products' => ['Ванна акрилова', 'Ванна кутова', 'Ванна прямокутна', 'Ванна асиметрична', 'Ванна з гідромасажем'],
            'brands' => ['Ravak', 'Cersanit', 'Roca', 'Kolo', 'Riho', 'Excellent'],
            'price_range' => [4000, 45000],
        ],
        'Ванни/Чавунні' => [
            'prefix' => 'VNC',
            'products' => ['Ванна чавунна', 'Ванна чавунна ретро', 'Ванна чавунна на ніжках', 'Ванна класична'],
            'brands' => ['Roca', 'Jacob Delafon', 'Универсал', 'Goldman'],
            'price_range' => [8000, 60000],
        ],
        'Душові кабіни/Квадратні' => [
            'prefix' => 'DKK',
            'products' => ['Душова кабіна квадратна', 'Душовий бокс', 'Кабіна з піддоном', 'Душова кабіна скляна'],
            'brands' => ['Ravak', 'Cersanit', 'Radaway', 'Sanplast', 'Appollo'],
            'price_range' => [8000, 55000],
        ],
        'Душові кабіни/Кутові' => [
            'prefix' => 'DKU',
            'products' => ['Душова кабіна кутова', 'Душовий куток', 'Напівкругла кабіна', 'Душова перегородка'],
            'brands' => ['Ravak', 'Cersanit', 'Radaway', 'Sanplast', 'Huppe'],
            'price_range' => [6000, 40000],
        ],
        'Меблі для ванної/Тумби' => [
            'prefix' => 'MVT',
            'products' => ['Тумба під раковину', 'Тумба підвісна', 'Тумба напольна', 'Комплект меблів'],
            'brands' => ['Cersanit', 'Roca', 'Ravak', 'Аквародос', 'Ювента'],
            'price_range' => [2500, 25000],
        ],
        'Меблі для ванної/Дзеркала' => [
            'prefix' => 'MVD',
            'products' => ['Дзеркало з підсвіткою', 'Дзеркальна шафа', 'Дзеркало для ванної', 'Дзеркало з полицею'],
            'brands' => ['Cersanit', 'Ravak', 'Аквародос', 'Qtap', 'Sanwerk'],
            'price_range' => [1200, 12000],
        ],
        'Труби та фітинги/Поліпропілен' => [
            'prefix' => 'TPP',
            'products' => ['Труба ППР', 'Муфта ППР', 'Кутик ППР', 'Трійник ППР', 'Кран ППР', 'Перехід ППР'],
            'brands' => ['Wavin', 'Valtec', 'TEBO', 'Blue Ocean', 'Vesbo'],
            'price_range' => [15, 350],
        ],
        'Труби та фітинги/Металопластик' => [
            'prefix' => 'TMP',
            'products' => ['Труба металопластик', 'Фітинг прес', 'Муфта металопластик', 'Кутик металопластик'],
            'brands' => ['Valtec', 'Henco', 'Prandelli', 'Rehau', 'Uponor'],
            'price_range' => [25, 450],
        ],
        'Водонагрівачі/Бойлери' => [
            'prefix' => 'VNB',
            'products' => ['Бойлер електричний', 'Водонагрівач 50л', 'Водонагрівач 80л', 'Водонагрівач 100л', 'Бойлер плоский'],
            'brands' => ['Atlantic', 'Gorenje', 'Ariston', 'Electrolux', 'Bosch', 'Tesy'],
            'price_range' => [3500, 18000],
        ],
        'Водонагрівачі/Проточні' => [
            'prefix' => 'VNP',
            'products' => ['Проточний водонагрівач', 'Колонка газова', 'Проточник електричний', 'Водонагрівач миттєвий'],
            'brands' => ['Electrolux', 'Zanussi', 'Thermex', 'Bosch', 'Ariston'],
            'price_range' => [2000, 12000],
        ],
        'Радіатори/Сталеві' => [
            'prefix' => 'RDS',
            'products' => ['Радіатор сталевий', 'Панельний радіатор', 'Радіатор 22 тип', 'Радіатор 11 тип'],
            'brands' => ['Kermi', 'Purmo', 'Korado', 'Buderus', 'Vogel&Noot'],
            'price_range' => [1200, 8000],
        ],
        'Радіатори/Біметалеві' => [
            'prefix' => 'RDB',
            'products' => ['Радіатор біметалевий', 'Секційний радіатор', 'Радіатор опалення', 'Батарея біметал'],
            'brands' => ['Global', 'Rifar', 'Royal Thermo', 'Fondital', 'Алтерпласт'],
            'price_range' => [1500, 6000],
        ],
        'Насоси/Циркуляційні' => [
            'prefix' => 'NSC',
            'products' => ['Насос циркуляційний', 'Насос для опалення', 'Насос Wilo', 'Насос Grundfos'],
            'brands' => ['Grundfos', 'Wilo', 'DAB', 'Pedrollo', 'Sprut'],
            'price_range' => [2500, 15000],
        ],
        'Насоси/Дренажні' => [
            'prefix' => 'NSD',
            'products' => ['Насос дренажний', 'Насос для брудної води', 'Фекальний насос', 'Занурювальний насос'],
            'brands' => ['Grundfos', 'Pedrollo', 'DAB', 'Sprut', 'Насосы+'],
            'price_range' => [1800, 12000],
        ],
        'Фільтри для води/Проточні' => [
            'prefix' => 'FVP',
            'products' => ['Фільтр проточний', 'Система очистки води', 'Фільтр під мийку', 'Триступінчастий фільтр'],
            'brands' => ['Ecosoft', 'Барьер', 'Аквафор', 'Бриз', 'Filter1'],
            'price_range' => [800, 5000],
        ],
        'Фільтри для води/Зворотній осмос' => [
            'prefix' => 'FVO',
            'products' => ['Зворотній осмос', 'Система очистки RO', 'Осмос 5 ступенів', 'Осмос з помпою'],
            'brands' => ['Ecosoft', 'Барьер', 'Аквафор', 'Atoll', 'Filter1'],
            'price_range' => [3500, 15000],
        ],
        'Сифони та обв\'язка/Для раковини' => [
            'prefix' => 'SFR',
            'products' => ['Сифон для раковини', 'Сифон пляшковий', 'Сифон хромований', 'Сифон з переливом'],
            'brands' => ['Viega', 'Geberit', 'Alcaplast', 'McAlpine', 'Ani Plast'],
            'price_range' => [150, 1500],
        ],
        'Сифони та обв\'язка/Для ванни' => [
            'prefix' => 'SFV',
            'products' => ['Сифон для ванни', 'Обв\'язка автомат', 'Злив-перелив', 'Сифон напівавтомат'],
            'brands' => ['Viega', 'Geberit', 'Alcaplast', 'Kaiser', 'Ravak'],
            'price_range' => [350, 3500],
        ],
        'Аксесуари для ванної/Тримачі' => [
            'prefix' => 'AKT',
            'products' => ['Тримач для рушників', 'Гачок для ванної', 'Полиця для ванної', 'Тримач для паперу'],
            'brands' => ['Grohe', 'Hansgrohe', 'Bemeta', 'Ravak', 'FBS'],
            'price_range' => [200, 3000],
        ],
        'Аксесуари для ванної/Душові системи' => [
            'prefix' => 'AKD',
            'products' => ['Душова стійка', 'Душовий гарнітур', 'Лійка душова', 'Шланг душовий', 'Тропічний душ'],
            'brands' => ['Grohe', 'Hansgrohe', 'Kludi', 'Ravak', 'Imprese'],
            'price_range' => [400, 12000],
        ],
        'Інсталяції/Для унітаза' => [
            'prefix' => 'INU',
            'products' => ['Інсталяція для унітаза', 'Рама для унітаза', 'Інсталяція з кнопкою', 'Комплект інсталяції'],
            'brands' => ['Geberit', 'Grohe', 'Cersanit', 'Viega', 'Tece'],
            'price_range' => [4500, 25000],
        ],
        'Інсталяції/Для біде' => [
            'prefix' => 'INB',
            'products' => ['Інсталяція для біде', 'Рама для біде', 'Комплект біде з інсталяцією'],
            'brands' => ['Geberit', 'Grohe', 'Viega', 'Tece'],
            'price_range' => [3500, 15000],
        ],
    ];

    protected array $colors = ['Хром', 'Білий', 'Чорний', 'Нікель', 'Бронза', 'Золото', 'Графіт', 'Нержавіюча сталь'];

    public function render()
    {
        return view('livewire.admin.test-products-generator')
            ->layout('admin.layout');
    }

    /**
     * Генерація та пряме завантаження CSV
     */
    public function downloadCsv()
    {
        $filename = 'plumbing_products_' . date('Y-m-d_His') . '.csv';
        $productCount = $this->productCount;
        $categories = $this->plumbingCategories;
        $colors = $this->colors;
        
        return response()->streamDownload(function () use ($productCount, $categories, $colors) {
            $handle = fopen('php://output', 'w');
            
            // UTF-8 BOM for Excel
            fwrite($handle, "\xEF\xBB\xBF");
            
            // Horoshop CSV header
            $headers = [
                'article',
                'title',
                'price',
                'price_old',
                'category',
                'brand',
                'description',
                'quantity',
                'in_stock',
                'color',
                'size',
                'weight',
                'images',
                'meta_title',
                'meta_description',
            ];
            
            fputcsv($handle, $headers, ';');
            
            $productsPerCategory = ceil($productCount / count($categories));
            $totalGenerated = 0;
            
            foreach ($categories as $category => $config) {
                if ($totalGenerated >= $productCount) {
                    break;
                }
                
                $toGenerate = min($productsPerCategory, $productCount - $totalGenerated);
                
                for ($i = 0; $i < $toGenerate; $i++) {
                    $product = $this->generateProductStatic($category, $config, $i, $colors);
                    fputcsv($handle, $product, ';');
                    $totalGenerated++;
                }
            }
            
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    protected function generateProductStatic(string $category, array $config, int $index, array $colors): array
    {
        $productName = $config['products'][array_rand($config['products'])];
        $brand = $config['brands'][array_rand($config['brands'])];
        $color = $colors[array_rand($colors)];
        
        // Generate article
        $article = sprintf('%s-%05d', $config['prefix'], $index + 1);
        
        // Generate title with variations
        $titleVariations = [
            "{$productName} {$brand}",
            "{$productName} {$brand} {$color}",
            "{$brand} {$productName}",
            "{$productName} {$brand} серія Premium",
            "{$productName} {$brand} серія Eco",
        ];
        $title = $titleVariations[array_rand($titleVariations)];
        
        // Generate price
        $price = rand($config['price_range'][0], $config['price_range'][1]);
        $priceOld = rand(0, 1) ? round($price * (1 + rand(10, 30) / 100)) : 0;
        
        // Generate description
        $description = $this->generateDescriptionStatic($productName, $brand, $category, $color);
        
        // Generate quantity
        $quantity = rand(1, 50);
        $inStock = rand(0, 10) > 1 ? 1 : 0;
        
        // Generate weight (in kg)
        $weight = round(rand(1, 300) / 10, 1);
        
        // Use placeholder image
        $imageUrl = $this->getPlaceholderImageStatic($category);
        
        return [
            $article,
            $title,
            $price,
            $priceOld ?: '',
            $category,
            $brand,
            $description,
            $quantity,
            $inStock,
            $color,
            '',
            $weight,
            $imageUrl,
            "Купити {$productName} {$brand} за найкращою ціною",
            "Замовити {$productName} {$brand} в інтернет-магазині. {$color}. Доставка по Україні.",
        ];
    }

    protected function generateDescriptionStatic(string $product, string $brand, string $category, string $color): string
    {
        $features = [
            'Гарантія від виробника',
            'Європейська якість',
            'Сучасний дизайн',
            'Простий монтаж',
            'Довговічність',
            'Енергоефективність',
            'Екологічно чистий матеріал',
            'Захист від корозії',
            'Безшумна робота',
            'Компактні розміри',
        ];
        
        shuffle($features);
        $selectedFeatures = array_slice($features, 0, rand(3, 5));
        
        $categoryParts = explode('/', $category);
        $mainCategory = $categoryParts[0];
        
        $description = "{$product} від бренду {$brand}. Колір: {$color}. ";
        $description .= "Категорія: {$mainCategory}. ";
        $description .= "Особливості: " . implode(', ', $selectedFeatures) . ". ";
        $description .= "Доставка по всій Україні. Гарантія якості.";
        
        return $description;
    }

    protected function getPlaceholderImageStatic(string $category): string
    {
        $categoryColors = [
            'Змішувачі' => '4A90D9',
            'Унітази' => 'FFFFFF',
            'Раковини' => 'E8E8E8',
            'Ванни' => 'B8D4E8',
            'Душові кабіни' => '87CEEB',
            'Меблі для ванної' => 'DEB887',
            'Труби та фітинги' => 'A0A0A0',
            'Водонагрівачі' => 'FF6B6B',
            'Радіатори' => 'FFB347',
            'Насоси' => '77DD77',
            'Фільтри для води' => '89CFF0',
            'Сифони та обв\'язка' => 'C0C0C0',
            'Аксесуари для ванної' => 'DDA0DD',
            'Інсталяції' => 'B0B0B0',
        ];
        
        $mainCategory = explode('/', $category)[0];
        $color = $categoryColors[$mainCategory] ?? '808080';
        $text = urlencode(mb_substr($mainCategory, 0, 15));
        
        return "https://placehold.co/500x500/{$color}/FFFFFF?text={$text}";
    }
}

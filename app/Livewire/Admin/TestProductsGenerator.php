<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TestProductsGenerator extends Component
{
    public int $productCount = 1000;
    public string $shopType = 'cosmetics'; // Default to cosmetics for tenant 8

    // Categories for Cosmetics & Fashion shop (tenant 8 - test8.horoshop.ua)
    protected array $cosmeticsCategories = [
        'Косметика/Догляд за волоссям/Шампунь' => [
            'prefix' => 'SHP',
            'products' => ['Шампунь для жирного волосся', 'Шампунь для сухого волосся', 'Шампунь проти лупи', 'Шампунь для фарбованого волосся', 'Шампунь зміцнюючий', 'Шампунь відновлюючий', 'Шампунь для об\'єму'],
            'brands' => ['L\'Oréal', 'Schwarzkopf', 'Wella', 'Matrix', 'Redken', 'Kerastase', 'Moroccanoil', 'Olaplex'],
            'price_range' => [150, 1200],
        ],
        'Косметика/Догляд за волоссям/Лак для волосся' => [
            'prefix' => 'LAK',
            'products' => ['Лак для волосся сильної фіксації', 'Лак для волосся середньої фіксації', 'Лак для об\'єму', 'Лак з блиском', 'Лак термозахисний'],
            'brands' => ['Schwarzkopf', 'L\'Oréal', 'Taft', 'Wella', 'Got2b', 'Syoss'],
            'price_range' => [100, 450],
        ],
        'Косметика/Догляд за волоссям/Бальзам' => [
            'prefix' => 'BAL',
            'products' => ['Бальзам для волосся', 'Бальзам-кондиціонер', 'Бальзам відновлюючий', 'Бальзам для фарбованого волосся', 'Незмивний бальзам'],
            'brands' => ['L\'Oréal', 'Schwarzkopf', 'Wella', 'Matrix', 'Pantene', 'Garnier'],
            'price_range' => [120, 800],
        ],
        'Косметика/Догляд за волоссям/Маска' => [
            'prefix' => 'MSK',
            'products' => ['Маска для волосся', 'Маска відновлююча', 'Маска зволожуюча', 'Маска для пошкодженого волосся', 'Маска кератинова'],
            'brands' => ['Kerastase', 'Moroccanoil', 'Olaplex', 'Matrix', 'Redken', 'L\'Oréal'],
            'price_range' => [200, 1500],
        ],
        'Косметика/Догляд за обличчям/Тональний крем' => [
            'prefix' => 'TNL',
            'products' => ['Тональний крем матуючий', 'Тональний крем зволожуючий', 'BB-крем', 'CC-крем', 'Тональний флюїд', 'Тональний мус', 'Кушон'],
            'brands' => ['MAC', 'Maybelline', 'L\'Oréal', 'Estée Lauder', 'Clinique', 'NARS', 'Fenty Beauty', 'Charlotte Tilbury'],
            'price_range' => [200, 2500],
        ],
        'Косметика/Догляд за обличчям/Молочко для обличчя' => [
            'prefix' => 'MLK',
            'products' => ['Молочко для зняття макіяжу', 'Молочко очищуюче', 'Молочко зволожуюче', 'Молочко живильне', 'Молочко для чутливої шкіри'],
            'brands' => ['Clinique', 'La Roche-Posay', 'Vichy', 'Bioderma', 'Avene', 'Uriage'],
            'price_range' => [180, 900],
        ],
        'Косметика/Догляд за обличчям/Крем для обличчя' => [
            'prefix' => 'CRM',
            'products' => ['Крем денний', 'Крем нічний', 'Крем зволожуючий', 'Крем anti-age', 'Крем живильний', 'Крем для проблемної шкіри', 'Крем з SPF'],
            'brands' => ['La Roche-Posay', 'Vichy', 'Bioderma', 'CeraVe', 'The Ordinary', 'Clinique', 'Estée Lauder'],
            'price_range' => [250, 2000],
        ],
        'Косметика/Догляд за обличчям/Сироватка' => [
            'prefix' => 'SRV',
            'products' => ['Сироватка з вітаміном С', 'Сироватка з гіалуроновою кислотою', 'Сироватка з ретинолом', 'Сироватка з ніацинамідом', 'Сироватка anti-age'],
            'brands' => ['The Ordinary', 'La Roche-Posay', 'Vichy', 'SkinCeuticals', 'Drunk Elephant', 'Paula\'s Choice'],
            'price_range' => [300, 2500],
        ],
        'Косметика/Догляд за тілом/Крем для рук' => [
            'prefix' => 'HND',
            'products' => ['Крем для рук зволожуючий', 'Крем для рук живильний', 'Крем для рук захисний', 'Крем для рук відновлюючий', 'Крем для рук з SPF'],
            'brands' => ['Neutrogena', 'L\'Occitane', 'Nivea', 'Eucerin', 'CeraVe', 'Dove'],
            'price_range' => [80, 450],
        ],
        'Косметика/Догляд за тілом/Молочко для тіла' => [
            'prefix' => 'BDY',
            'products' => ['Молочко для тіла зволожуюче', 'Молочко для тіла живильне', 'Молочко для тіла з блиском', 'Лосьйон для тіла', 'Олія для тіла'],
            'brands' => ['Nivea', 'Dove', 'Vaseline', 'The Body Shop', 'Bioderma', 'Eucerin'],
            'price_range' => [120, 600],
        ],
        'Косметика/Догляд за тілом/Сіль для ванної' => [
            'prefix' => 'BTH',
            'products' => ['Сіль для ванної морська', 'Сіль для ванної з лавандою', 'Сіль для ванної розслаблююча', 'Бомбочка для ванної', 'Піна для ванної'],
            'brands' => ['Dr. Teal\'s', 'Lush', 'The Body Shop', 'Kneipp', 'Epsom', 'Westlab'],
            'price_range' => [80, 350],
        ],
        'Косметика/Догляд за тілом/Гель для душу' => [
            'prefix' => 'SHW',
            'products' => ['Гель для душу зволожуючий', 'Гель для душу тонізуючий', 'Крем-гель для душу', 'Гель для душу чоловічий', 'Гель для душу дитячий'],
            'brands' => ['Dove', 'Nivea', 'Palmolive', 'The Body Shop', 'L\'Occitane', 'Rituals'],
            'price_range' => [60, 400],
        ],
        'Одяг та взуття/Жіночий одяг' => [
            'prefix' => 'WMN',
            'products' => ['Сукня', 'Блузка', 'Спідниця', 'Штани жіночі', 'Джинси жіночі', 'Светр жіночий', 'Кардиган', 'Футболка жіноча', 'Топ', 'Костюм жіночий'],
            'brands' => ['Zara', 'H&M', 'Mango', 'Reserved', 'Massimo Dutti', 'COS', 'Arket', 'Other Stories'],
            'price_range' => [300, 5000],
        ],
        'Одяг та взуття/Чоловічий одяг' => [
            'prefix' => 'MEN',
            'products' => ['Сорочка чоловіча', 'Футболка чоловіча', 'Джинси чоловічі', 'Штани чоловічі', 'Светр чоловічий', 'Піджак', 'Куртка чоловіча', 'Пальто чоловіче'],
            'brands' => ['Zara', 'H&M', 'Reserved', 'Massimo Dutti', 'COS', 'Uniqlo', 'Pull&Bear'],
            'price_range' => [350, 6000],
        ],
        'Одяг та взуття/Взуття жіноче' => [
            'prefix' => 'SHW',
            'products' => ['Туфлі жіночі', 'Черевики жіночі', 'Кросівки жіночі', 'Босоніжки', 'Балетки', 'Мокасини жіночі', 'Чоботи жіночі'],
            'brands' => ['Zara', 'H&M', 'Steve Madden', 'Aldo', 'Geox', 'Ecco', 'Clarks'],
            'price_range' => [800, 8000],
        ],
        'Одяг та взуття/Взуття чоловіче' => [
            'prefix' => 'SHM',
            'products' => ['Туфлі чоловічі', 'Черевики чоловічі', 'Кросівки чоловічі', 'Мокасини чоловічі', 'Кеди', 'Лофери'],
            'brands' => ['Ecco', 'Geox', 'Clarks', 'Timberland', 'Dr. Martens', 'Vans', 'Converse'],
            'price_range' => [1000, 10000],
        ],
        'Електроніка/Смартфони/iPhone' => [
            'prefix' => 'IPH',
            'products' => ['iPhone 15 Pro Max', 'iPhone 15 Pro', 'iPhone 15', 'iPhone 15 Plus', 'iPhone 14', 'iPhone SE'],
            'brands' => ['Apple'],
            'price_range' => [25000, 65000],
        ],
        'Електроніка/Смартфони/Samsung' => [
            'prefix' => 'SAM',
            'products' => ['Samsung Galaxy S24 Ultra', 'Samsung Galaxy S24+', 'Samsung Galaxy S24', 'Samsung Galaxy A54', 'Samsung Galaxy A34'],
            'brands' => ['Samsung'],
            'price_range' => [12000, 55000],
        ],
        'Електроніка/Аксесуари/Чохли' => [
            'prefix' => 'CSE',
            'products' => ['Чохол для iPhone', 'Чохол для Samsung', 'Чохол силіконовий', 'Чохол шкіряний', 'Чохол прозорий', 'Чохол протиударний'],
            'brands' => ['Spigen', 'UAG', 'OtterBox', 'Apple', 'Samsung', 'Ringke'],
            'price_range' => [200, 2000],
        ],
        'Електроніка/Аксесуари/Зарядні пристрої' => [
            'prefix' => 'CHR',
            'products' => ['Зарядний пристрій USB-C', 'Бездротова зарядка', 'Повербанк', 'Автомобільний зарядний', 'Кабель Lightning', 'Кабель USB-C'],
            'brands' => ['Apple', 'Samsung', 'Anker', 'Belkin', 'Baseus', 'Ugreen'],
            'price_range' => [150, 3000],
        ],
    ];

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
    
    protected array $cosmeticsColors = ['Білий', 'Бежевий', 'Рожевий', 'Коричневий', 'Чорний', 'Прозорий', 'Червоний', 'Nude'];
    
    protected array $fashionColors = ['Чорний', 'Білий', 'Синій', 'Сірий', 'Бежевий', 'Коричневий', 'Зелений', 'Бордовий', 'Рожевий', 'Navy'];
    
    protected array $fashionSizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '36', '37', '38', '39', '40', '41', '42', '43', '44', '45'];

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
        $shopType = $this->shopType;
        $filename = "{$shopType}_products_" . date('Y-m-d_His') . '.csv';
        $productCount = $this->productCount;
        
        // Select categories based on shop type
        $categories = $shopType === 'cosmetics' ? $this->cosmeticsCategories : $this->plumbingCategories;
        $colors = $shopType === 'cosmetics' ? array_merge($this->cosmeticsColors, $this->fashionColors) : $this->colors;
        
        return response()->streamDownload(function () use ($productCount, $categories, $colors, $shopType) {
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
                    $product = $this->generateProductStatic($category, $config, $i, $colors, $shopType);
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

    protected function generateProductStatic(string $category, array $config, int $index, array $colors, string $shopType = 'plumbing'): array
    {
        $productName = $config['products'][array_rand($config['products'])];
        $brand = $config['brands'][array_rand($config['brands'])];
        $color = $colors[array_rand($colors)];
        
        // Generate article
        $article = sprintf('%s-%05d', $config['prefix'], $index + 1);
        
        // Generate title with variations based on shop type
        if ($shopType === 'cosmetics') {
            $titleVariations = [
                "{$productName} {$brand}",
                "{$productName} {$brand} {$color}",
                "{$brand} {$productName}",
                "{$productName} {$brand} Professional",
                "{$productName} {$brand} для професійного використання",
            ];
        } else {
            $titleVariations = [
                "{$productName} {$brand}",
                "{$productName} {$brand} {$color}",
                "{$brand} {$productName}",
                "{$productName} {$brand} серія Premium",
                "{$productName} {$brand} серія Eco",
            ];
        }
        $title = $titleVariations[array_rand($titleVariations)];
        
        // Generate price
        $price = rand($config['price_range'][0], $config['price_range'][1]);
        $priceOld = rand(0, 1) ? round($price * (1 + rand(10, 30) / 100)) : 0;
        
        // Generate description
        $description = $this->generateDescriptionStatic($productName, $brand, $category, $color, $shopType);
        
        // Generate quantity
        $quantity = rand(1, 50);
        $inStock = rand(0, 10) > 1 ? 1 : 0;
        
        // Generate weight (in kg) - lighter for cosmetics
        if ($shopType === 'cosmetics') {
            $weight = round(rand(1, 50) / 100, 2); // 0.01 - 0.5 kg
        } else {
            $weight = round(rand(1, 300) / 10, 1); // 0.1 - 30 kg
        }
        
        // Generate size for fashion items
        $size = '';
        if ($shopType === 'cosmetics' && strpos($category, 'Одяг') !== false) {
            $size = $this->fashionSizes[array_rand($this->fashionSizes)];
        } elseif ($shopType === 'cosmetics' && strpos($category, 'Взуття') !== false) {
            $size = (string)rand(36, 45);
        }
        
        // Use placeholder image
        $imageUrl = $this->getPlaceholderImageStatic($category, $shopType);
        
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
            $size,
            $weight,
            $imageUrl,
            "Купити {$productName} {$brand} за найкращою ціною",
            "Замовити {$productName} {$brand} в інтернет-магазині. {$color}. Доставка по Україні.",
        ];
    }

    protected function generateDescriptionStatic(string $product, string $brand, string $category, string $color, string $shopType = 'plumbing'): string
    {
        if ($shopType === 'cosmetics') {
            $features = [
                'Сертифікована продукція',
                'Дерматологічно протестовано',
                'Не тестується на тваринах',
                'Оригінал від виробника',
                'Екологічне пакування',
                'Підходить для чутливої шкіри',
                'Містить натуральні компоненти',
            ];
            
            $templates = [
                "{$product} від {$brand} - якісна продукція для вашого догляду. {$color}. " . $features[array_rand($features)] . ". " . $features[array_rand($features)] . ".",
                "Оригінальний {$product} {$brand}. Категорія: {$category}. " . $features[array_rand($features)] . ". Швидка доставка по Україні.",
                "{$brand} {$product} - професійна якість. {$color}. " . $features[array_rand($features)] . ". " . $features[array_rand($features)] . ". Доставка 1-3 дні.",
            ];
        } else {
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
            
            $templates = [
                "{$product} від бренду {$brand}. Колір: {$color}. Категорія: {$mainCategory}. Особливості: " . implode(', ', $selectedFeatures) . ". Доставка по всій Україні. Гарантія якості.",
            ];
        }
        
        return $templates[array_rand($templates)];
    }

    protected function getPlaceholderImageStatic(string $category, string $shopType = 'plumbing'): string
    {
        if ($shopType === 'cosmetics') {
            $categoryColors = [
                'Косметика' => 'FFB6C1', // Light pink
                'Одяг та взуття' => '4169E1', // Royal blue
                'Електроніка' => '2F4F4F', // Dark slate gray
            ];
        } else {
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
        }
        
        $mainCategory = explode('/', $category)[0];
        $color = $categoryColors[$mainCategory] ?? '808080';
        $text = urlencode(mb_substr($mainCategory, 0, 15));
        
        return "https://placehold.co/500x500/{$color}/FFFFFF?text={$text}";
    }
}

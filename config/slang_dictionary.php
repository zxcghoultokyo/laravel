<?php

/**
 * Slang Dictionary for Product Search
 * 
 * Maps product types/categories to common slang terms, synonyms, and alternate spellings.
 * Used to augment AI-generated slang and ensure consistent search results.
 * 
 * Format:
 * 'product_type' => [
 *     'slang' => ['сленг1', 'сленг2'],           // Жаргонні назви
 *     'synonyms' => ['синонім1', 'синонім2'],    // Офіційні синоніми
 *     'typos' => ['помилка1', 'помилка2'],       // Часті помилки в написанні
 *     'en' => ['english1', 'english2'],          // Англійські варіанти
 * ]
 */

return [
    // === БРОНЕЗАХИСТ ===
    'plate_carrier' => [
        'slang' => ['плитка', 'бронік', 'брон', 'жилет', 'pc', 'пц'],
        'synonyms' => ['плитоноска', 'плитоносій', 'бронежилет', 'тактичний жилет'],
        'typos' => ['плитноска', 'плитаноска', 'плейткеріер', 'плейт керієр'],
        'en' => ['plate carrier', 'platecarrier', 'body armor', 'vest'],
    ],
    
    'armor_plate' => [
        'slang' => ['плита', 'бронеплита', 'керамічка', 'керамика', 'сталь'],
        'synonyms' => ['бронеплита', 'балістична плита', 'захисна плита'],
        'typos' => ['бронеплітка', 'бронеплата'],
        'en' => ['armor plate', 'ballistic plate', 'steel plate', 'ceramic plate'],
    ],
    
    'side_plate' => [
        'slang' => ['бокова', 'сайдплейт', 'боковушка'],
        'synonyms' => ['бокова плита', 'бокова бронеплита'],
        'en' => ['side plate', 'side armor'],
    ],
    
    'soft_armor' => [
        'slang' => ['мяка балістика', 'мягка балістика', 'кевлар', 'арамід'],
        'synonyms' => ['мяка балістична вставка', 'балістичний пакет'],
        'typos' => ['мягка баллістика'],
        'en' => ['soft armor', 'kevlar', 'aramid panel'],
    ],
    
    // === ШОЛОМИ ===
    'helmet' => [
        'slang' => ['шлем', 'каска', 'череп', 'горшок', 'кастрюля'],
        'synonyms' => ['шолом', 'бойовий шолом', 'балістичний шолом', 'тактичний шолом'],
        'typos' => ['шелом', 'шлєм'],
        'en' => ['helmet', 'combat helmet', 'ballistic helmet', 'tactical helmet', 'fast helmet', 'mich helmet'],
    ],
    
    'helmet_cover' => [
        'slang' => ['кавер', 'чохол', 'накидка'],
        'synonyms' => ['чохол на шолом', 'кавер на шолом'],
        'typos' => ['ковер', 'cover'],
        'en' => ['helmet cover', 'helmet skin'],
    ],
    
    // === ВЗУТТЯ ===
    'boots' => [
        'slang' => ['берци', 'боти', 'ботинки', 'тактикалки', 'берцюки'],
        'synonyms' => ['берці', 'тактичні черевики', 'бойові черевики', 'армійські черевики'],
        'typos' => ['берціі', 'берцi', 'ботінки'],
        'en' => ['boots', 'tactical boots', 'combat boots', 'military boots'],
    ],
    
    'sneakers' => [
        'slang' => ['кроси', 'кросси', 'кросівки'],
        'synonyms' => ['кросівки', 'тактичні кросівки', 'спортивне взуття'],
        'typos' => ['кросовки', 'кроссовки'],
        'en' => ['sneakers', 'tactical sneakers', 'trainers'],
    ],
    
    'socks' => [
        'slang' => ['шкарпи', 'носки', 'термошкарпетки'],
        'synonyms' => ['шкарпетки', 'тактичні шкарпетки'],
        'typos' => ['шкарпеткі'],
        'en' => ['socks', 'tactical socks', 'boot socks'],
    ],
    
    // === ФОРМА ===
    'uniform_pants' => [
        'slang' => ['штани', 'штаніки', 'бойовки', 'комбат штани'],
        'synonyms' => ['тактичні штани', 'бойові штани', 'військові штани'],
        'typos' => ['штаны', 'штаній'],
        'en' => ['pants', 'tactical pants', 'combat pants', 'trousers'],
    ],
    
    'uniform_shirt' => [
        'slang' => ['сорочка', 'убакс', 'бойовка', 'кітель'],
        'synonyms' => ['тактична сорочка', 'бойова сорочка', 'військова сорочка'],
        'typos' => ['сорочька', 'убакс'],
        'en' => ['shirt', 'tactical shirt', 'combat shirt', 'ubacs'],
    ],
    
    'tshirt' => [
        'slang' => ['футболка', 'футба', 'майка'],
        'synonyms' => ['тактична футболка', 'військова футболка'],
        'en' => ['tshirt', 't-shirt', 'tactical shirt'],
    ],
    
    // === ПІДСУМКИ ТА СПОРЯДЖЕННЯ ===
    'pouch' => [
        'slang' => ['підсумок', 'подсумок', 'кишеня', 'карман'],
        'synonyms' => ['тактичний підсумок', 'підсумок для магазинів'],
        'typos' => ['подсумок', 'пiдсумок'],
        'en' => ['pouch', 'mag pouch', 'utility pouch'],
    ],
    
    'backpack' => [
        'slang' => ['рюкзак', 'рюк', 'ранець', 'баул'],
        'synonyms' => ['тактичний рюкзак', 'штурмовий рюкзак', 'військовий рюкзак'],
        'typos' => ['рюкзаг', 'рюгзак'],
        'en' => ['backpack', 'tactical backpack', 'assault pack', 'rucksack'],
    ],
    
    'belt' => [
        'slang' => ['рпс', 'ремінь', 'пояс', 'war belt'],
        'synonyms' => ['розвантажувальний пояс', 'тактичний ремінь', 'бойовий пояс'],
        'typos' => ['рпс'],
        'en' => ['belt', 'war belt', 'battle belt', 'tactical belt'],
    ],
    
    // === РУКАВИЦІ ===
    'gloves' => [
        'slang' => ['рукавиці', 'рукавички', 'перчатки', 'тактикалки'],
        'synonyms' => ['тактичні рукавиці', 'бойові рукавиці', 'стрілецькі рукавиці'],
        'typos' => ['рукавіці', 'рукавичкі'],
        'en' => ['gloves', 'tactical gloves', 'shooting gloves', 'mechanix'],
    ],
    
    // === МЕДИЦИНА ===
    'tourniquet' => [
        'slang' => ['турнікет', 'джгут', 'турник', 'кат', 'соф', 'тк'],
        'synonyms' => ['кровоспинний джгут', 'турнікет', 'джгут кровоспинний'],
        'typos' => ['турнекет', 'турнікєт', 'тк кат', 'тк cat', 'ткат'],
        'en' => ['tourniquet', 'cat', 'sof-t', 'tq', 'cat gen7', 'cat tourniquet'],
    ],
    
    'ifak' => [
        'slang' => ['аптечка', 'іфак', 'такмед', 'медкіт'],
        'synonyms' => ['індивідуальна аптечка', 'тактична аптечка', 'бойова аптечка'],
        'typos' => ['афтечка', 'апточка'],
        'en' => ['ifak', 'first aid kit', 'med kit', 'trauma kit'],
    ],
    
    'bandage' => [
        'slang' => ['бинт', 'ізраїль', 'ізраїльський', 'компресійка'],
        'synonyms' => ['компресійний бандаж', 'ізраїльський бандаж', 'тиснучий бандаж'],
        'en' => ['bandage', 'israeli bandage', 'compression bandage', 'emergency bandage'],
    ],
    
    'chest_seal' => [
        'slang' => ['окклюзійка', 'оклюзійка', 'окклюзійний', 'хайфін'],
        'synonyms' => ['оклюзійний пластир', 'оклюзійна наклейка'],
        'typos' => ['оклюзийка', 'оклюзійна'],
        'en' => ['chest seal', 'occlusive dressing', 'hyfin', 'asherman'],
    ],
    
    // === ОПТИКА ТА АКСЕСУАРИ ===
    'optics' => [
        'slang' => ['оптика', 'прицілка', 'приціл', 'колліматор', 'коллік'],
        'synonyms' => ['оптичний приціл', 'коліматорний приціл', 'тактична оптика'],
        'typos' => ['опкита', 'прицел'],
        'en' => ['optics', 'scope', 'red dot', 'holographic', 'eotech', 'aimpoint'],
    ],
    
    'flashlight' => [
        'slang' => ['ліхтар', 'фонарик', 'фонар', 'ліхтарик'],
        'synonyms' => ['тактичний ліхтар', 'ліхтар підствольний'],
        'typos' => ['ліхтарь', 'фонарь'],
        'en' => ['flashlight', 'tactical light', 'weapon light', 'surefire'],
    ],
    
    'sling' => [
        'slang' => ['ремінь', 'слінг', 'підвіс'],
        'synonyms' => ['збройовий ремінь', 'тактичний ремінь для зброї'],
        'en' => ['sling', 'weapon sling', 'rifle sling'],
    ],
    
    // === КОМУНІКАЦІЯ ===
    'radio' => [
        'slang' => ['рація', 'радіо', 'baofeng', 'бавофенг', 'моторола'],
        'synonyms' => ['радіостанція', 'портативна рація', 'тактична рація'],
        'typos' => ['рацыя', 'радиостанция'],
        'en' => ['radio', 'walkie talkie', 'comms'],
    ],
    
    'headset' => [
        'slang' => ['гарнітура', 'навушники', 'активки', 'пелтори', 'комтаки', 'еармори', 'навішники', 'наушники'],
        'synonyms' => ['тактична гарнітура', 'активні навушники', 'шумоподавляючі навушники', 'захист слуху'],
        'typos' => ['гарнитура', 'навушнікі', 'навішніки', 'наушнікі', 'навішникі'],
        'en' => ['headset', 'comtac', 'peltor', 'earmor', 'active hearing protection', 'ear protection'],
    ],
    
    // === МАСКУВАННЯ ===
    'camo_net' => [
        'slang' => ['маскосітка', 'масксітка', 'сітка', 'кікімора'],
        'synonyms' => ['маскувальна сітка', 'камуфляжна сітка'],
        'typos' => ['маскосітка', 'масксетка'],
        'en' => ['camo net', 'camouflage net', 'ghillie'],
    ],
    
    'face_paint' => [
        'slang' => ['грим', 'фарба', 'камуфляж'],
        'synonyms' => ['маскувальний грим', 'камуфляжна фарба', 'грим для обличчя'],
        'en' => ['face paint', 'camo paint', 'camouflage cream'],
    ],
    
    // === ТЕРМОБІЛИЗНА ===
    'thermal_underwear' => [
        'slang' => ['термобілизна', 'термуха', 'подштанники', 'кальсони'],
        'synonyms' => ['термобілизна', 'базовий шар', 'функціональна білизна'],
        'typos' => ['термобілизна', 'термобілізна'],
        'en' => ['thermal underwear', 'base layer', 'long johns'],
    ],
    
    // === ІНШЕ ===
    'knife' => [
        'slang' => ['ніж', 'клинок', 'мультитул', 'складень'],
        'synonyms' => ['тактичний ніж', 'бойовий ніж', 'багатофункціональний ніж'],
        'typos' => ['ножик', 'ньож'],
        'en' => ['knife', 'tactical knife', 'multitool', 'blade'],
    ],
    
    'watch' => [
        'slang' => ['годинник', 'часи', 'тактичні годинник'],
        'synonyms' => ['тактичний годинник', 'військовий годинник'],
        'en' => ['watch', 'tactical watch', 'military watch'],
    ],
    
    'patch' => [
        'slang' => ['патч', 'нашивка', 'шеврон', 'наліпка'],
        'synonyms' => ['тактичний патч', 'ідентифікаційна нашивка'],
        'typos' => ['патчь', 'нашівка'],
        'en' => ['patch', 'morale patch', 'velcro patch'],
    ],
];

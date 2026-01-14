<?php

namespace Database\Seeders;

use App\Models\Greeting;
use Illuminate\Database\Seeder;

class GreetingsSeeder extends Seeder
{
    public function run(): void
    {
        // Default greeting (fallback)
        Greeting::firstOrCreate(
            ['is_default' => true],
            [
                'name' => 'Стандартне привітання',
                'message' => 'Вітаю! 👋 Я ваш персональний консультант. Чим можу допомогти?',
                'quick_actions' => [
                    ['label' => '🎽 Плитоноски', 'query' => 'Покажи плитоноски'],
                    ['label' => '📦 Підсумки', 'query' => 'Покажи підсумки'],
                    ['label' => '🛡️ Броні', 'query' => 'Покажи бронеплити'],
                ],
                'is_active' => true,
                'priority' => 0,
            ]
        );

        // Example: Greeting for Facebook campaign
        Greeting::firstOrCreate(
            ['utm_source' => 'facebook'],
            [
                'name' => 'Привітання з Facebook',
                'message' => '👋 Привіт! Бачу, ви прийшли з Facebook. Що вас цікавить?',
                'quick_actions' => [
                    ['label' => '🔥 Акції', 'query' => 'Які зараз акції?'],
                    ['label' => '🆕 Новинки', 'query' => 'Покажи новинки'],
                ],
                'utm_source' => 'facebook',
                'is_active' => true,
                'priority' => 10,
            ]
        );

        // Example: Greeting for returning visitors
        Greeting::firstOrCreate(
            ['visitor_type' => 'returning'],
            [
                'name' => 'Привітання для постійних',
                'message' => '👋 Радий бачити знову! Потрібна допомога з вибором?',
                'quick_actions' => [
                    ['label' => '📋 Мої замовлення', 'query' => 'Статус замовлення'],
                    ['label' => '🆕 Новинки', 'query' => 'Що нового?'],
                ],
                'visitor_type' => 'returning',
                'is_active' => true,
                'priority' => 5,
            ]
        );

        // Example: Mobile-specific greeting
        Greeting::firstOrCreate(
            ['device' => 'mobile', 'is_default' => false],
            [
                'name' => 'Мобільне привітання',
                'message' => '👋 Вітаю! Напишіть що шукаєте — підберу найкращі варіанти 🎯',
                'quick_actions' => [
                    ['label' => '🎽 Плитоноски', 'query' => 'Плитоноски'],
                    ['label' => '📞 Зателефонувати', 'query' => 'Телефон магазину'],
                ],
                'device' => 'mobile',
                'is_active' => true,
                'priority' => 3,
            ]
        );
    }
}

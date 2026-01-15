<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Тут викликаємо всі сідери, які нам потрібні
        $this->call([
            ScenarioSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}

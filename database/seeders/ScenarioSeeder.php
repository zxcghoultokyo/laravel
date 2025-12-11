<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Scenario;
use App\Services\Ai\Scenarios\TacticalMedicineScenarioHandler;

class ScenarioSeeder extends Seeder
{
    public function run(): void
    {
        Scenario::updateOrCreate(
            ['code' => 'TACTICAL_MEDICINE'],
            [
                'name'          => 'Тактична медицина',
                'description'   => 'Сценарій коли користувач питає про аптечки, медуху, турнікети та тактичну медицину.',
                'handler_class' => TacticalMedicineScenarioHandler::class,
                'is_active'     => true,
                'config'        => [
                    'product_source' => [
                        'type'  => 'category_path_contains',
                        'value' => 'Тактична медицина',
                        'limit' => 100,
                    ],
                ],
            ]
        );
    }
}

<?php

use App\Models\Scenario;

Scenario::updateOrCreate(
    ['code' => 'TACTICAL_MEDICINE'],
    [
        'name'          => 'Тактична медицина',
        'description'   => 'Сценарій коли користувач питає про аптечки, медуху, турнікети.',
        'handler_class' => \App\Services\Ai\Scenarios\TacticalMedicineScenarioHandler::class,
        'is_active'     => true,
        'config'        => [
            'product_source' => [
                'type'  => 'category_path_contains',
                'value' => 'Тактична медицина',
                'limit' => 100,
            ],
            'persona' => null,
        ],
    ]
);

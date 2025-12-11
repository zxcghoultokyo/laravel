<?php

namespace App\Services\Ai\Scenarios;

use App\Models\Scenario;

interface ScenarioHandlerInterface
{
    /**
     * @param string  $message         оригінальне повідомлення користувача
     * @param Scenario $scenario       сценарій з БД (вся конфігурація тут)
     * @param array   $routerPayload   те, що повернув ЛЛМ-роутер (LLM детектор):
     *                                 [
     *                                     'scenario_code'   => string,
     *                                     'semantic_query'  => string|null,
     *                                     'order_number'    => string|null,
     *                                     'parameters'      => array,
     *                                 ]
     *
     * @return array Структура для фронта (JSON-відповідь /api/chat)
     */
    public function handle(string $message, Scenario $scenario, array $routerPayload): array;
}

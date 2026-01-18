<?php

namespace App\Livewire\Admin;

use App\Models\ProactiveTriggerRule;
use Livewire\Component;
use Livewire\WithPagination;

class ProactiveTriggersManager extends Component
{
    use WithPagination;

    // Form fields
    public $rule_id = null;
    public $name = '';
    public $trigger_type = 'exit_intent';
    public $is_enabled = true;
    public $priority = 10;
    public $conditions = [];
    public $message = '';
    public $button_text = 'Почати чат';
    public $icon = '💬';
    public $action_type = 'open_chat';
    public $action_config = [];
    public $max_per_session = 1;
    public $max_per_day = 3;
    public $cooldown_minutes = 30;

    // Condition fields for different trigger types
    public $condition_page_type = '';
    public $condition_page_contains = '';
    public $condition_min_time = 5;
    public $condition_time_seconds = 45;
    public $condition_utm_source = '';
    public $condition_utm_medium = '';
    public $condition_utm_campaign = '';
    public $condition_delay_seconds = 10;
    public $condition_variant_timeout = 15;
    public $condition_min_visits = 2;
    public $condition_category = '';

    // Action config fields
    public $action_context = '';
    public $action_product_type = '';

    // UI state
    public $showModal = false;
    public $editMode = false;
    public $showDeleteConfirm = false;
    public $deleteId = null;

    // Filters
    public $filterType = '';
    public $filterStatus = '';

    protected $rules = [
        'name' => 'required|string|max:100',
        'trigger_type' => 'required|string|in:exit_intent,time_on_page,utm_campaign,returning_visitor,pdp_no_variant',
        'is_enabled' => 'boolean',
        'priority' => 'integer|min:0|max:100',
        'message' => 'required|string|max:500',
        'button_text' => 'required|string|max:50',
        'icon' => 'nullable|string|max:10',
        'action_type' => 'required|string|in:open_chat,open_chat_with_context,show_products',
        'max_per_session' => 'integer|min:1|max:10',
        'max_per_day' => 'integer|min:1|max:20',
        'cooldown_minutes' => 'integer|min:0|max:1440',
    ];

    public function mount()
    {
        // Initialize empty conditions
    }

    public function render()
    {
        $query = ProactiveTriggerRule::query();

        if ($this->filterType) {
            $query->where('trigger_type', $this->filterType);
        }

        if ($this->filterStatus !== '') {
            $query->where('is_enabled', $this->filterStatus === '1');
        }

        $rules = $query->orderBy('priority')->orderBy('name')->paginate(10);

        return view('livewire.admin.proactive-triggers-manager', [
            'rules' => $rules,
            'triggerTypes' => $this->getTriggerTypes(),
            'actionTypes' => $this->getActionTypes(),
            'pageTypes' => $this->getPageTypes(),
        ])->layout('admin.layout');
    }

    protected function getTriggerTypes(): array
    {
        return [
            'exit_intent' => [
                'label' => 'Exit Intent',
                'icon' => '🚪',
                'description' => 'Спрацьовує при спробі покинути сторінку',
            ],
            'time_on_page' => [
                'label' => 'Time on Page',
                'icon' => '⏱️',
                'description' => 'Спрацьовує після певного часу на сторінці',
            ],
            'utm_campaign' => [
                'label' => 'UTM Campaign',
                'icon' => '🎯',
                'description' => 'Спрацьовує для відвідувачів з певних UTM міток',
            ],
            'returning_visitor' => [
                'label' => 'Returning Visitor',
                'icon' => '🔄',
                'description' => 'Спрацьовує для повторних відвідувачів',
            ],
            'pdp_no_variant' => [
                'label' => 'PDP No Variant',
                'icon' => '🛍️',
                'description' => 'Спрацьовує коли покупець не вибрав варіант товару',
            ],
        ];
    }

    protected function getActionTypes(): array
    {
        return [
            'open_chat' => [
                'label' => 'Відкрити чат',
                'description' => 'Просто відкриває чат віджет',
            ],
            'open_chat_with_context' => [
                'label' => 'Відкрити чат з контекстом',
                'description' => 'Відкриває чат з попереднім повідомленням',
            ],
            'show_products' => [
                'label' => 'Показати товари',
                'description' => 'Показує рекомендовані товари',
            ],
        ];
    }

    protected function getPageTypes(): array
    {
        return [
            '' => 'Будь-яка сторінка',
            'product' => 'Сторінка товару',
            'category' => 'Сторінка категорії',
            'cart' => 'Кошик',
            'checkout' => 'Оформлення замовлення',
            'home' => 'Головна сторінка',
        ];
    }

    public function create()
    {
        $this->resetForm();
        $this->editMode = false;
        $this->showModal = true;
    }

    public function edit($id)
    {
        $rule = ProactiveTriggerRule::findOrFail($id);
        
        $this->rule_id = $rule->id;
        $this->name = $rule->name;
        $this->trigger_type = $rule->trigger_type;
        $this->is_enabled = $rule->is_enabled;
        $this->priority = $rule->priority;
        $this->message = $rule->message;
        $this->button_text = $rule->button_text;
        $this->icon = $rule->icon;
        $this->action_type = $rule->action_type;
        $this->max_per_session = $rule->max_per_session;
        $this->max_per_day = $rule->max_per_day;
        $this->cooldown_minutes = $rule->cooldown_minutes;

        // Load conditions based on trigger type
        $conditions = $rule->conditions ?? [];
        $this->condition_page_type = $conditions['page_type'] ?? '';
        $this->condition_page_contains = $conditions['page_contains'] ?? '';
        $this->condition_min_time = $conditions['min_time_on_site'] ?? 5;
        $this->condition_time_seconds = $conditions['time_seconds'] ?? 45;
        $this->condition_utm_source = $conditions['utm_source'] ?? '';
        $this->condition_utm_medium = $conditions['utm_medium'] ?? '';
        $this->condition_utm_campaign = $conditions['utm_campaign'] ?? '';
        $this->condition_delay_seconds = $conditions['delay_seconds'] ?? 10;
        $this->condition_variant_timeout = $conditions['variant_selection_timeout'] ?? 15;
        $this->condition_min_visits = $conditions['min_visits'] ?? 2;
        $this->condition_category = $conditions['category'] ?? '';

        // Load action config
        $actionConfig = $rule->action_config ?? [];
        $this->action_context = $actionConfig['context'] ?? '';
        $this->action_product_type = $actionConfig['product_type'] ?? '';

        $this->editMode = true;
        $this->showModal = true;
    }

    public function save()
    {
        $this->validate();

        // Build conditions array based on trigger type
        $conditions = $this->buildConditions();

        // Build action config
        $actionConfig = $this->buildActionConfig();

        $data = [
            'name' => $this->name,
            'trigger_type' => $this->trigger_type,
            'is_enabled' => $this->is_enabled,
            'priority' => $this->priority,
            'conditions' => $conditions,
            'message' => $this->message,
            'button_text' => $this->button_text,
            'icon' => $this->icon,
            'action_type' => $this->action_type,
            'action_config' => $actionConfig,
            'max_per_session' => $this->max_per_session,
            'max_per_day' => $this->max_per_day,
            'cooldown_minutes' => $this->cooldown_minutes,
        ];

        if ($this->editMode && $this->rule_id) {
            $rule = ProactiveTriggerRule::findOrFail($this->rule_id);
            $rule->update($data);
            $this->dispatch('toast', message: 'Тригер оновлено', type: 'success');
        } else {
            ProactiveTriggerRule::create($data);
            $this->dispatch('toast', message: 'Тригер створено', type: 'success');
        }

        $this->showModal = false;
        $this->resetForm();
    }

    protected function buildConditions(): array
    {
        $conditions = [];

        switch ($this->trigger_type) {
            case 'exit_intent':
                if ($this->condition_page_type) {
                    $conditions['page_type'] = $this->condition_page_type;
                }
                if ($this->condition_page_contains) {
                    $conditions['page_contains'] = $this->condition_page_contains;
                }
                $conditions['min_time_on_site'] = (int) $this->condition_min_time;
                break;

            case 'time_on_page':
                if ($this->condition_page_type) {
                    $conditions['page_type'] = $this->condition_page_type;
                }
                $conditions['time_seconds'] = (int) $this->condition_time_seconds;
                break;

            case 'utm_campaign':
                if ($this->condition_utm_source) {
                    $conditions['utm_source'] = $this->condition_utm_source;
                }
                if ($this->condition_utm_medium) {
                    $conditions['utm_medium'] = $this->condition_utm_medium;
                }
                if ($this->condition_utm_campaign) {
                    $conditions['utm_campaign'] = $this->condition_utm_campaign;
                }
                $conditions['delay_seconds'] = (int) $this->condition_delay_seconds;
                break;

            case 'returning_visitor':
                $conditions['min_visits'] = (int) $this->condition_min_visits;
                $conditions['delay_seconds'] = (int) $this->condition_delay_seconds;
                break;

            case 'pdp_no_variant':
                $conditions['variant_selection_timeout'] = (int) $this->condition_variant_timeout;
                if ($this->condition_category) {
                    $conditions['category'] = $this->condition_category;
                }
                break;
        }

        return $conditions;
    }

    protected function buildActionConfig(): array
    {
        $config = [];

        if ($this->action_type === 'open_chat_with_context' && $this->action_context) {
            $config['context'] = $this->action_context;
        }

        if ($this->action_type === 'show_products' && $this->action_product_type) {
            $config['product_type'] = $this->action_product_type;
        }

        return $config;
    }

    public function confirmDelete($id)
    {
        $this->deleteId = $id;
        $this->showDeleteConfirm = true;
    }

    public function delete()
    {
        if ($this->deleteId) {
            ProactiveTriggerRule::findOrFail($this->deleteId)->delete();
            $this->dispatch('toast', message: 'Тригер видалено', type: 'success');
        }
        $this->showDeleteConfirm = false;
        $this->deleteId = null;
    }

    public function toggleEnabled($id)
    {
        $rule = ProactiveTriggerRule::findOrFail($id);
        $rule->update(['is_enabled' => !$rule->is_enabled]);
        
        $status = $rule->is_enabled ? 'увімкнено' : 'вимкнено';
        $this->dispatch('toast', message: "Тригер {$status}", type: 'success');
    }

    public function duplicate($id)
    {
        $rule = ProactiveTriggerRule::findOrFail($id);
        
        $newRule = $rule->replicate();
        $newRule->name = $rule->name . ' (копія)';
        $newRule->is_enabled = false;
        $newRule->shown_count = 0;
        $newRule->clicked_count = 0;
        $newRule->converted_count = 0;
        $newRule->purchased_count = 0;
        $newRule->save();

        $this->dispatch('toast', message: 'Тригер скопійовано', type: 'success');
    }

    public function resetStats($id)
    {
        $rule = ProactiveTriggerRule::findOrFail($id);
        $rule->update([
            'shown_count' => 0,
            'clicked_count' => 0,
            'converted_count' => 0,
            'purchased_count' => 0,
        ]);

        $this->dispatch('toast', message: 'Статистику скинуто', type: 'success');
    }

    protected function resetForm()
    {
        $this->rule_id = null;
        $this->name = '';
        $this->trigger_type = 'exit_intent';
        $this->is_enabled = true;
        $this->priority = 10;
        $this->message = '';
        $this->button_text = 'Почати чат';
        $this->icon = '💬';
        $this->action_type = 'open_chat';
        $this->max_per_session = 1;
        $this->max_per_day = 3;
        $this->cooldown_minutes = 30;

        // Reset condition fields
        $this->condition_page_type = '';
        $this->condition_page_contains = '';
        $this->condition_min_time = 5;
        $this->condition_time_seconds = 45;
        $this->condition_utm_source = '';
        $this->condition_utm_medium = '';
        $this->condition_utm_campaign = '';
        $this->condition_delay_seconds = 10;
        $this->condition_variant_timeout = 15;
        $this->condition_min_visits = 2;
        $this->condition_category = '';

        // Reset action config
        $this->action_context = '';
        $this->action_product_type = '';

        $this->resetValidation();
    }

    public function updatedTriggerType()
    {
        // Reset condition-specific fields when trigger type changes
        $this->condition_page_type = '';
        $this->condition_page_contains = '';
        $this->condition_min_time = 5;
        $this->condition_time_seconds = 45;
        $this->condition_utm_source = '';
        $this->condition_utm_medium = '';
        $this->condition_utm_campaign = '';
        $this->condition_delay_seconds = 10;
        $this->condition_variant_timeout = 15;
        $this->condition_min_visits = 2;
        $this->condition_category = '';

        // Set sensible defaults based on type
        switch ($this->trigger_type) {
            case 'exit_intent':
                $this->message = 'Маєте питання щодо товарів? Наші консультанти готові допомогти!';
                $this->condition_min_time = 5;
                break;
            case 'time_on_page':
                $this->message = 'Бачу, ви переглядаєте товари. Можу допомогти з вибором?';
                $this->condition_time_seconds = 45;
                break;
            case 'utm_campaign':
                $this->message = 'Вітаємо! У нас зараз діють спеціальні пропозиції';
                $this->condition_delay_seconds = 10;
                break;
            case 'returning_visitor':
                $this->message = 'З поверненням! Можу допомогти знайти те, що шукали минулого разу?';
                $this->condition_min_visits = 2;
                break;
            case 'pdp_no_variant':
                $this->message = 'Потрібна допомога з вибором розміру або кольору?';
                $this->condition_variant_timeout = 15;
                break;
        }
    }
}

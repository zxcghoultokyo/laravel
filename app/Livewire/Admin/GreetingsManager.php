<?php

namespace App\Livewire\Admin;

use App\Models\Greeting;
use Livewire\Component;
use Livewire\WithPagination;

class GreetingsManager extends Component
{
    use WithPagination;

    // Form fields
    public $greeting_id = null;
    public $name = '';
    public $message = '';
    public $quick_actions = [];
    public $utm_campaign = '';
    public $utm_source = '';
    public $utm_medium = '';
    public $url_contains = '';
    public $category_path = '';
    public $device = 'any';
    public $visitor_type = 'any';
    public $language = '';
    public $time_start = '';
    public $time_end = '';
    public $priority = 0;
    public $is_active = true;
    public $is_default = false;

    // UI state
    public $showModal = false;
    public $editMode = false;
    
    // Quick action editor
    public $newActionLabel = '';
    public $newActionQuery = '';

    protected $rules = [
        'name' => 'required|string|max:100',
        'message' => 'required|string|max:1000',
        'quick_actions' => 'array',
        'utm_campaign' => 'nullable|string|max:100',
        'utm_source' => 'nullable|string|max:100',
        'utm_medium' => 'nullable|string|max:100',
        'url_contains' => 'nullable|string|max:255',
        'category_path' => 'nullable|string|max:255',
        'device' => 'in:any,mobile,desktop',
        'visitor_type' => 'in:any,new,returning',
        'language' => 'nullable|string|max:10',
        'time_start' => 'nullable|date_format:H:i',
        'time_end' => 'nullable|date_format:H:i',
        'priority' => 'integer|min:0|max:1000',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public bool $embedded = false;

    public function render()
    {
        $greetings = Greeting::orderByDesc('priority')
            ->orderByDesc('is_default')
            ->paginate(10);

        $view = view('livewire.admin.greetings-manager', [
            'greetings' => $greetings,
        ]);

        if ($this->embedded) {
            return $view;
        }

        return $view->layout('admin.layout');
    }

    public function create()
    {
        $this->resetForm();
        $this->editMode = false;
        $this->showModal = true;
    }

    public function edit($id)
    {
        $greeting = Greeting::findOrFail($id);
        
        $this->greeting_id = $greeting->id;
        $this->name = $greeting->name;
        $this->message = $greeting->message;
        $this->quick_actions = $greeting->quick_actions ?? [];
        $this->utm_campaign = $greeting->utm_campaign ?? '';
        $this->utm_source = $greeting->utm_source ?? '';
        $this->utm_medium = $greeting->utm_medium ?? '';
        $this->url_contains = $greeting->url_contains ?? '';
        $this->category_path = $greeting->category_path ?? '';
        $this->device = $greeting->device ?? 'any';
        $this->visitor_type = $greeting->visitor_type ?? 'any';
        $this->language = $greeting->language ?? '';
        $this->time_start = $greeting->time_range['start'] ?? '';
        $this->time_end = $greeting->time_range['end'] ?? '';
        $this->priority = $greeting->priority;
        $this->is_active = $greeting->is_active;
        $this->is_default = $greeting->is_default;

        $this->editMode = true;
        $this->showModal = true;
    }

    public function save()
    {
        $this->validate();

        $timeRange = null;
        if ($this->time_start && $this->time_end) {
            $timeRange = ['start' => $this->time_start, 'end' => $this->time_end];
        }

        $data = [
            'name' => $this->name,
            'message' => $this->message,
            'quick_actions' => $this->quick_actions,
            'utm_campaign' => $this->utm_campaign ?: null,
            'utm_source' => $this->utm_source ?: null,
            'utm_medium' => $this->utm_medium ?: null,
            'url_contains' => $this->url_contains ?: null,
            'category_path' => $this->category_path ?: null,
            'device' => $this->device,
            'visitor_type' => $this->visitor_type,
            'language' => $this->language ?: null,
            'time_range' => $timeRange,
            'priority' => $this->priority,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
        ];

        // If setting as default, unset other defaults
        if ($this->is_default) {
            Greeting::where('is_default', true)
                ->when($this->greeting_id, fn($q) => $q->where('id', '!=', $this->greeting_id))
                ->update(['is_default' => false]);
        }

        if ($this->editMode && $this->greeting_id) {
            Greeting::findOrFail($this->greeting_id)->update($data);
            $this->dispatch('toast', message: 'Привітання оновлено', type: 'success');
        } else {
            Greeting::create($data);
            $this->dispatch('toast', message: 'Привітання створено', type: 'success');
        }

        $this->showModal = false;
        $this->resetForm();
    }

    public function delete($id)
    {
        Greeting::findOrFail($id)->delete();
        $this->dispatch('toast', message: 'Привітання видалено', type: 'success');
    }

    public function duplicate($id)
    {
        $original = Greeting::findOrFail($id);
        $copy = $original->replicate();
        $copy->name = $original->name . ' (копія)';
        $copy->is_default = false;
        $copy->save();
        
        $this->dispatch('toast', message: 'Привітання скопійовано', type: 'success');
    }

    public function toggleActive($id)
    {
        $greeting = Greeting::findOrFail($id);
        $greeting->update(['is_active' => !$greeting->is_active]);
    }

    public function addQuickAction()
    {
        if ($this->newActionLabel && $this->newActionQuery) {
            $this->quick_actions[] = [
                'label' => $this->newActionLabel,
                'query' => $this->newActionQuery,
            ];
            $this->newActionLabel = '';
            $this->newActionQuery = '';
        }
    }

    public function removeQuickAction($index)
    {
        unset($this->quick_actions[$index]);
        $this->quick_actions = array_values($this->quick_actions);
    }

    private function resetForm()
    {
        $this->greeting_id = null;
        $this->name = '';
        $this->message = '';
        $this->quick_actions = [];
        $this->utm_campaign = '';
        $this->utm_source = '';
        $this->utm_medium = '';
        $this->url_contains = '';
        $this->category_path = '';
        $this->device = 'any';
        $this->visitor_type = 'any';
        $this->language = '';
        $this->time_start = '';
        $this->time_end = '';
        $this->priority = 0;
        $this->is_active = true;
        $this->is_default = false;
        $this->newActionLabel = '';
        $this->newActionQuery = '';
    }
}

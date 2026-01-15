<?php

namespace App\Livewire\Admin;

use App\Models\CannedResponse;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Canned Responses Manager - CRUD for operator quick responses.
 */
class CannedResponsesManager extends Component
{
    use WithPagination;

    public string $search = '';
    public string $categoryFilter = '';
    public bool $showInactive = false;

    public ?int $editingId = null;
    public array $form = [];
    public bool $showModal = false;

    protected $rules = [
        'form.title' => 'required|string|max:255',
        'form.content' => 'required|string|max:5000',
        'form.shortcut' => 'nullable|string|max:50|regex:/^[a-z0-9_-]*$/i',
        'form.category' => 'nullable|string|max:50',
        'form.is_active' => 'boolean',
    ];

    public function mount()
    {
        $this->resetForm();
    }

    public function updatedSearch()
    {
        $this->resetPage();
    }

    public function openModal(?int $id = null)
    {
        if ($id) {
            $response = CannedResponse::findOrFail($id);
            $this->editingId = $id;
            $this->form = [
                'title' => $response->title,
                'content' => $response->content,
                'shortcut' => $response->shortcut,
                'category' => $response->category,
                'is_active' => $response->is_active,
            ];
        } else {
            $this->resetForm();
        }
        $this->showModal = true;
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function save()
    {
        $this->validate();

        $tenantId = $this->getTenantId();

        // Check shortcut uniqueness within tenant
        if ($this->form['shortcut']) {
            $exists = CannedResponse::where('tenant_id', $tenantId)
                ->where('shortcut', $this->form['shortcut'])
                ->when($this->editingId, fn($q) => $q->where('id', '!=', $this->editingId))
                ->exists();

            if ($exists) {
                $this->addError('form.shortcut', 'Цей шорткат вже використовується');
                return;
            }
        }

        $data = [
            'title' => $this->form['title'],
            'content' => $this->form['content'],
            'shortcut' => $this->form['shortcut'] ?: null,
            'category' => $this->form['category'] ?: CannedResponse::CATEGORY_OTHER,
            'is_active' => $this->form['is_active'],
        ];

        if ($this->editingId) {
            CannedResponse::where('id', $this->editingId)->update($data);
            session()->flash('success', 'Шаблон оновлено!');
        } else {
            CannedResponse::create(array_merge($data, ['tenant_id' => $tenantId]));
            session()->flash('success', 'Шаблон створено!');
        }

        $this->closeModal();
    }

    public function delete(int $id)
    {
        CannedResponse::where('id', $id)
            ->where('tenant_id', $this->getTenantId())
            ->delete();
        
        session()->flash('success', 'Шаблон видалено!');
    }

    public function toggleActive(int $id)
    {
        $response = CannedResponse::findOrFail($id);
        $response->update(['is_active' => !$response->is_active]);
    }

    public function seedDefaults()
    {
        $tenantId = $this->getTenantId();
        
        // Check if already has responses
        if (CannedResponse::where('tenant_id', $tenantId)->count() > 0) {
            session()->flash('error', 'Шаблони вже існують!');
            return;
        }

        // Get defaults from controller
        $controller = new \App\Http\Controllers\Api\CannedResponseController();
        $reflection = new \ReflectionMethod($controller, 'getDefaultResponses');
        $reflection->setAccessible(true);
        $defaults = $reflection->invoke($controller);

        foreach ($defaults as $default) {
            CannedResponse::create([
                'tenant_id' => $tenantId,
                'title' => $default['title'],
                'content' => $default['content'],
                'shortcut' => $default['shortcut'],
                'category' => $default['category'],
                'is_active' => true,
            ]);
        }

        session()->flash('success', 'Створено ' . count($defaults) . ' базових шаблонів!');
    }

    private function resetForm()
    {
        $this->editingId = null;
        $this->form = [
            'title' => '',
            'content' => '',
            'shortcut' => '',
            'category' => '',
            'is_active' => true,
        ];
    }

    private function getTenantId(): ?int
    {
        $user = auth()->user();
        
        // Super admin can select tenant (for now use first one or implement selector)
        if ($user->isSuperAdmin()) {
            // Use first tenant for demo, or implement tenant selector
            return \App\Models\Tenant::first()?->id;
        }

        return $user->tenant_id;
    }

    public function render()
    {
        $tenantId = $this->getTenantId();
        
        $query = CannedResponse::where('tenant_id', $tenantId);

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('title', 'like', "%{$this->search}%")
                  ->orWhere('shortcut', 'like', "%{$this->search}%")
                  ->orWhere('content', 'like', "%{$this->search}%");
            });
        }

        if ($this->categoryFilter) {
            $query->where('category', $this->categoryFilter);
        }

        if (!$this->showInactive) {
            $query->where('is_active', true);
        }

        $responses = $query->orderByDesc('usage_count')->paginate(15);

        $categories = [
            ['key' => CannedResponse::CATEGORY_GREETING, 'label' => 'Привітання', 'icon' => '👋'],
            ['key' => CannedResponse::CATEGORY_FAREWELL, 'label' => 'Прощання', 'icon' => '👋'],
            ['key' => CannedResponse::CATEGORY_DELIVERY, 'label' => 'Доставка', 'icon' => '📦'],
            ['key' => CannedResponse::CATEGORY_PAYMENT, 'label' => 'Оплата', 'icon' => '💳'],
            ['key' => CannedResponse::CATEGORY_RETURNS, 'label' => 'Повернення', 'icon' => '↩️'],
            ['key' => CannedResponse::CATEGORY_PRODUCT, 'label' => 'Товари', 'icon' => '🛍️'],
            ['key' => CannedResponse::CATEGORY_OTHER, 'label' => 'Інше', 'icon' => '📝'],
        ];

        $stats = [
            'total' => CannedResponse::where('tenant_id', $tenantId)->count(),
            'active' => CannedResponse::where('tenant_id', $tenantId)->where('is_active', true)->count(),
            'total_usage' => CannedResponse::where('tenant_id', $tenantId)->sum('usage_count'),
        ];

        return view('livewire.admin.canned-responses-manager', [
            'responses' => $responses,
            'categories' => $categories,
            'stats' => $stats,
        ])->layout('admin.layout');
    }
}

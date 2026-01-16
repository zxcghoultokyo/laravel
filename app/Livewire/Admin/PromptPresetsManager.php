<?php

namespace App\Livewire\Admin;

use App\Models\PromptPreset;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Str;

class PromptPresetsManager extends Component
{
    use WithPagination;

    // Form fields
    public $preset_id = null;
    public $name = '';
    public $description = '';
    public $system_prompt = '';
    public $categories = [];
    public $language = '';
    public $tone = '';
    public $storeType = '';
    public $campaign = '';
    public $variables = [];
    public $is_active = true;
    public $is_default = false;
    public $priority = 0;

    // UI state
    public $showModal = false;
    public $editMode = false;
    public $showTestModal = false;
    public $testMessage = '';
    public $testResponse = '';
    public $testLoading = false;

    // Variable editor
    public $newVarName = '';
    public $newVarDefault = '';
    public $newCategory = '';
    public $customCategory = '';

    protected $rules = [
        'name' => 'required|string|max:100',
        'description' => 'nullable|string|max:500',
        'system_prompt' => 'required|string|min:50',
        'categories' => 'array',
        'language' => 'nullable|string|max:10',
        'tone' => 'nullable|string|in:official,spartan,friendly',
        'campaign' => 'nullable|string|max:100',
        'variables' => 'array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'priority' => 'integer|min:0|max:1000',
    ];

    public function render()
    {
        $presets = PromptPreset::orderByDesc('priority')
            ->orderByDesc('is_default')
            ->paginate(10);

        // Load available categories from products
        $availableCategories = \App\Models\Product::where('in_stock', true)
            ->whereNotNull('category_path')
            ->where('category_path', '!=', '')
            ->select('category_path')
            ->distinct()
            ->orderBy('category_path')
            ->pluck('category_path')
            ->map(fn($path) => collect(explode(' > ', $path))->last())
            ->unique()
            ->sort()
            ->values()
            ->toArray();
        
        // Load store types from StoreContext
        $storeTypes = [
            '' => 'Будь-який',
            \App\Models\StoreContext::TYPE_TACTICAL => 'Тактичний / Військовий',
            \App\Models\StoreContext::TYPE_FASHION => 'Мода / Одяг',
            \App\Models\StoreContext::TYPE_ELECTRONICS => 'Електроніка',
            \App\Models\StoreContext::TYPE_SPORTS => 'Спорт / Фітнес',
            \App\Models\StoreContext::TYPE_HOME_DECOR => 'Дім / Декор',
            \App\Models\StoreContext::TYPE_BEAUTY => 'Краса / Косметика',
            \App\Models\StoreContext::TYPE_GENERAL => 'Загальний',
        ];

        return view('livewire.admin.prompt-presets-manager', [
            'presets' => $presets,
            'availableCategories' => $availableCategories,
            'storeTypes' => $storeTypes,
            'tones' => [
                '' => 'Будь-який',
                'official' => 'Офіційний',
                'spartan' => 'Спартанський',
                'friendly' => 'Дружній',
            ],
            'languages' => [
                '' => 'Будь-яка',
                'uk' => 'Українська',
                'en' => 'English',
                'ru' => 'Русский',
            ],
        ])->layout('admin.layout');
    }

    public function create()
    {
        $this->resetForm();
        $this->system_prompt = $this->getDefaultPromptTemplate();
        $this->editMode = false;
        $this->showModal = true;
    }

    public function edit($id)
    {
        $preset = PromptPreset::findOrFail($id);
        
        $this->preset_id = $preset->id;
        $this->name = $preset->name;
        $this->description = $preset->description ?? '';
        $this->system_prompt = $preset->system_prompt;
        $this->categories = $preset->categories ?? [];
        $this->language = $preset->language ?? '';
        $this->tone = $preset->tone ?? '';
        $this->storeType = $preset->store_type ?? '';
        $this->campaign = $preset->campaign ?? '';
        
        // Normalize variables to array of ['name' => ..., 'default' => ...] format
        $rawVars = $preset->variables ?? [];
        $this->variables = [];
        foreach ($rawVars as $key => $value) {
            if (is_array($value) && isset($value['name'])) {
                // Already in correct format
                $this->variables[] = $value;
            } else {
                // Old format: key => default_value
                $this->variables[] = [
                    'name' => is_string($key) ? $key : (string)$key,
                    'default' => is_string($value) ? $value : '',
                ];
            }
        }
        
        $this->is_active = $preset->is_active;
        $this->is_default = $preset->is_default;
        $this->priority = $preset->priority;

        $this->editMode = true;
        $this->showModal = true;
    }

    public function save()
    {
        $this->validate();

        $data = [
            'name' => $this->name,
            'slug' => Str::slug($this->name),
            'description' => $this->description ?: null,
            'system_prompt' => $this->system_prompt,
            'categories' => $this->categories ?: null,
            'language' => $this->language ?: null,
            'tone' => $this->tone ?: null,
            'store_type' => $this->storeType ?: null,
            'campaign' => $this->campaign ?: null,
            'variables' => $this->variables ?: null,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'priority' => $this->priority,
        ];

        if ($this->editMode && $this->preset_id) {
            $preset = PromptPreset::findOrFail($this->preset_id);
            
            // Check for duplicate slug
            if (PromptPreset::where('slug', $data['slug'])->where('id', '!=', $this->preset_id)->exists()) {
                $data['slug'] = $data['slug'] . '-' . time();
            }
            
            $preset->update($data);
            $this->dispatch('toast', message: 'Пресет оновлено', type: 'success');
        } else {
            // Check for duplicate slug
            if (PromptPreset::where('slug', $data['slug'])->exists()) {
                $data['slug'] = $data['slug'] . '-' . time();
            }
            
            PromptPreset::create($data);
            $this->dispatch('toast', message: 'Пресет створено', type: 'success');
        }

        $this->showModal = false;
        $this->resetForm();
    }

    public function delete($id)
    {
        $preset = PromptPreset::findOrFail($id);
        
        if ($preset->is_default) {
            $this->dispatch('toast', message: 'Не можна видалити дефолтний пресет', type: 'error');
            return;
        }

        $preset->delete();
        $this->dispatch('toast', message: 'Пресет видалено', type: 'success');
    }

    public function duplicate($id)
    {
        $preset = PromptPreset::findOrFail($id);
        
        $newPreset = $preset->replicate();
        $newPreset->name = $preset->name . ' (копія)';
        $newPreset->slug = Str::slug($newPreset->name) . '-' . time();
        $newPreset->is_default = false;
        $newPreset->save();

        $this->dispatch('toast', message: 'Пресет скопійовано', type: 'success');
    }

    public function toggleActive($id)
    {
        $preset = PromptPreset::findOrFail($id);
        $preset->update(['is_active' => !$preset->is_active]);
        
        $status = $preset->is_active ? 'активовано' : 'деактивовано';
        $this->dispatch('toast', message: "Пресет {$status}", type: 'success');
    }

    // Variable management
    public function addVariable()
    {
        if (empty($this->newVarName)) {
            return;
        }

        $this->variables[] = [
            'name' => Str::snake($this->newVarName),
            'default' => $this->newVarDefault,
        ];

        $this->newVarName = '';
        $this->newVarDefault = '';
    }

    public function removeVariable($index)
    {
        unset($this->variables[$index]);
        $this->variables = array_values($this->variables);
    }

    // Category management
    public function addCategory()
    {
        if (empty($this->newCategory)) {
            return;
        }

        if (!in_array($this->newCategory, $this->categories)) {
            $this->categories[] = $this->newCategory;
        }

        $this->newCategory = '';
    }

    public function addCustomCategory()
    {
        if (empty($this->customCategory)) {
            return;
        }

        $cat = trim($this->customCategory);
        if (!in_array($cat, $this->categories)) {
            $this->categories[] = $cat;
        }

        $this->customCategory = '';
    }

    public function removeCategory($index)
    {
        unset($this->categories[$index]);
        $this->categories = array_values($this->categories);
    }

    // Extract variables from prompt
    public function extractVariablesFromPrompt()
    {
        preg_match_all('/\{\{(\w+)\}\}/', $this->system_prompt, $matches);
        $found = array_unique($matches[1] ?? []);
        
        $existing = array_column($this->variables, 'name');
        
        foreach ($found as $varName) {
            if (!in_array($varName, $existing)) {
                $this->variables[] = [
                    'name' => $varName,
                    'default' => '',
                ];
            }
        }

        $this->dispatch('toast', message: 'Знайдено ' . count($found) . ' змінних', type: 'info');
    }

    // Test chat
    public function openTestModal($id)
    {
        $this->preset_id = $id;
        $this->testMessage = '';
        $this->testResponse = '';
        $this->showTestModal = true;
    }

    public function testPreset()
    {
        if (empty($this->testMessage)) {
            return;
        }

        $this->testLoading = true;
        
        try {
            $preset = PromptPreset::findOrFail($this->preset_id);
            
            // Build variable values from preset defaults
            $values = [];
            foreach ($preset->variables ?? [] as $var) {
                $values[$var['name']] = $var['default'] ?? '';
            }

            $renderedPrompt = $preset->render($values);

            // Call OpenAI for test
            $apiKey = config('services.openai.key');
            if (empty($apiKey)) {
                $this->testResponse = '⚠️ OpenAI API ключ не налаштований. Перевірте OPENAI_API_KEY в .env';
                $this->testLoading = false;
                return;
            }
            
            $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
                ->timeout(30)
                ->post(config('services.openai.base_url', 'https://api.openai.com/v1') . '/chat/completions', [
                    'model' => config('services.openai.model', 'gpt-4.1-mini'),
                    'messages' => [
                        ['role' => 'system', 'content' => $renderedPrompt],
                        ['role' => 'user', 'content' => $this->testMessage],
                    ],
                    'max_completion_tokens' => 500,
                    'temperature' => 0.7,
                ]);

            $data = $response->json();
            
            if (isset($data['error'])) {
                $this->testResponse = '❌ OpenAI помилка: ' . ($data['error']['message'] ?? json_encode($data['error']));
            } elseif (!$response->successful()) {
                $this->testResponse = '❌ HTTP помилка ' . $response->status() . ': ' . $response->body();
            } else {
                $this->testResponse = $data['choices'][0]['message']['content'] ?? '⚠️ Порожня відповідь від API';
            }
            
        } catch (\Throwable $e) {
            $this->testResponse = 'Помилка: ' . $e->getMessage();
        }

        $this->testLoading = false;
    }

    // Import/Export
    public function exportPreset($id)
    {
        $preset = PromptPreset::findOrFail($id);
        
        $export = [
            'name' => $preset->name,
            'description' => $preset->description,
            'system_prompt' => $preset->system_prompt,
            'categories' => $preset->categories,
            'language' => $preset->language,
            'tone' => $preset->tone,
            'campaign' => $preset->campaign,
            'variables' => $preset->variables,
            'priority' => $preset->priority,
        ];

        $this->dispatch('download', 
            filename: Str::slug($preset->name) . '.json',
            content: json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Generate prompt automatically based on store data.
     */
    public function generateForStore()
    {
        try {
            $generator = app(\App\Services\Ai\PromptGeneratorService::class);
            
            // Analyze store first
            $context = $generator->analyzeStore(null);
            
            // Generate prompt (use AI if OpenAI is configured)
            $useAi = !empty(config('services.openai.key'));
            $prompt = $generator->generatePrompt($context, $useAi);
            
            // Create PromptPreset from generated content
            $storeName = \App\Models\WidgetSettings::first()?->bot_name ?? 'Магазин';
            $presetName = "Auto: {$storeName} (" . now()->format('d.m.Y') . ")";
            
            $preset = PromptPreset::create([
                'name' => $presetName,
                'slug' => Str::slug($presetName) . '-' . time(),
                'description' => "Автоматично згенерований промпт для {$storeName}. Тип магазину: {$context->store_type}",
                'system_prompt' => $prompt,
                'categories' => $context->primary_categories ? array_slice($context->primary_categories, 0, 5) : null,
                'language' => 'uk',
                'tone' => null,
                'campaign' => null,
                'variables' => [
                    ['name' => 'shop_name', 'default' => $storeName],
                    ['name' => 'shop_phone', 'default' => \App\Models\WidgetSettings::first()?->shop_phone ?? ''],
                ],
                'is_active' => true,
                'is_default' => false,
                'priority' => 50,
            ]);
            
            $this->dispatch('toast', message: "Промпт згенеровано! Тип: {$context->store_type}, Категорій: " . count($context->primary_categories ?? []), type: 'success');
            
        } catch (\Throwable $e) {
            \Log::error('[PromptPresetsManager] Generate failed', ['error' => $e->getMessage()]);
            $this->dispatch('toast', message: 'Помилка: ' . $e->getMessage(), type: 'error');
        }
    }

    protected function resetForm()
    {
        $this->preset_id = null;
        $this->name = '';
        $this->description = '';
        $this->system_prompt = '';
        $this->categories = [];
        $this->language = '';
        $this->tone = '';
        $this->storeType = '';
        $this->campaign = '';
        $this->variables = [];
        $this->is_active = true;
        $this->is_default = false;
        $this->priority = 0;
        $this->newVarName = '';
        $this->newVarDefault = '';
        $this->newCategory = '';
        $this->customCategory = '';
    }

    protected function getDefaultPromptTemplate(): string
    {
        return <<<PROMPT
Ти — AI-продавець магазину "{{brand_name}}". Твоя мета — допомогти клієнту знайти та купити товар.

ПРАВИЛА:
- Відповідай {{language}}, коротко і по суті
- Показуй тільки товари з каталогу
- Якщо товару немає — запропонуй альтернативу

ІНФОРМАЦІЯ ПРО МАГАЗИН:
- Назва: {{brand_name}}
- Повернення: {{return_policy}}
- Доставка: {{delivery_info}}

При пошуку товарів використовуй функцію search_products().
PROMPT;
    }
}

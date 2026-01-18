<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Prompt Preset Model
 * 
 * Custom system prompts with variables for advanced users.
 * Variables use {{variable_name}} syntax.
 */
class PromptPreset extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'system_prompt',
        'categories',
        'language',
        'tone',
        'campaign',
        'store_type',
        'variables',
        'is_active',
        'is_default',
        'priority',
    ];

    protected $casts = [
        'categories' => 'array',
        'variables' => 'array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    /**
     * Boot: auto-generate slug.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($preset) {
            if (empty($preset->slug)) {
                $preset->slug = Str::slug($preset->name);
            }
        });

        // Only one default preset allowed
        static::saving(function ($preset) {
            if ($preset->is_default) {
                static::where('id', '!=', $preset->id ?? 0)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    /**
     * Scope: active presets ordered by priority.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->orderByDesc('priority');
    }

    /**
     * Find matching preset for context.
     */
    public static function findForContext(array $context): ?self
    {
        $presets = static::active()->get();

        foreach ($presets as $preset) {
            if ($preset->matchesContext($context)) {
                return $preset;
            }
        }

        // Fallback to default
        return static::where('is_default', true)->first();
    }

    /**
     * Check if preset matches given context.
     */
    public function matchesContext(array $context): bool
    {
        // Check language
        if ($this->language && isset($context['language'])) {
            if ($this->language !== $context['language']) {
                return false;
            }
        }

        // Check tone
        if ($this->tone && isset($context['tone'])) {
            if ($this->tone !== $context['tone']) {
                return false;
            }
        }

        // Check campaign (UTM)
        if ($this->campaign && isset($context['campaign'])) {
            if (!Str::contains($context['campaign'], $this->campaign, true)) {
                return false;
            }
        }

        // Check categories
        if (!empty($this->categories) && isset($context['category'])) {
            $categoryMatched = false;
            foreach ($this->categories as $cat) {
                if (Str::contains($context['category'], $cat, true)) {
                    $categoryMatched = true;
                    break;
                }
            }
            if (!$categoryMatched) {
                return false;
            }
        }

        return true;
    }

    /**
     * Render prompt with variables replaced.
     */
    public function render(array $values = []): string
    {
        $prompt = $this->system_prompt;

        // Get variable defaults
        $defaults = [];
        foreach ($this->variables ?? [] as $var) {
            $defaults[$var['name']] = $var['default'] ?? '';
        }

        // Merge with provided values
        $values = array_merge($defaults, $values);

        // Replace {{variable}} patterns
        foreach ($values as $key => $value) {
            $prompt = str_replace('{{' . $key . '}}', $value, $prompt);
        }

        return $prompt;
    }

    /**
     * Extract variables from prompt.
     */
    public function extractVariables(): array
    {
        preg_match_all('/\{\{(\w+)\}\}/', $this->system_prompt, $matches);
        return array_unique($matches[1] ?? []);
    }

    /**
     * Get variable names from definition.
     */
    public function getVariableNames(): array
    {
        return array_column($this->variables ?? [], 'name');
    }

    /**
     * Validate that all variables in prompt are defined.
     */
    public function validateVariables(): array
    {
        $inPrompt = $this->extractVariables();
        $defined = $this->getVariableNames();

        return [
            'undefined' => array_diff($inPrompt, $defined),
            'unused' => array_diff($defined, $inPrompt),
        ];
    }
}

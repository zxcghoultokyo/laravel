<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Greeting extends Model
{
    protected $fillable = [
        'name',
        'message',
        'quick_actions',
        'utm_campaign',
        'utm_source',
        'utm_medium',
        'url_contains',
        'category_path',
        'device',
        'visitor_type',
        'language',
        'time_range',
        'priority',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'quick_actions' => 'array',
        'time_range' => 'array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'priority' => 'integer',
    ];

    /**
     * Get all active greetings ordered by priority
     */
    public static function getActive()
    {
        return static::where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * Get the default greeting
     */
    public static function getDefault()
    {
        return static::where('is_default', true)->first();
    }

    /**
     * Match greeting based on context
     */
    public static function matchContext(array $context): ?self
    {
        $greetings = static::getActive();
        
        foreach ($greetings as $greeting) {
            if ($greeting->is_default) {
                continue; // Skip default, check it last
            }
            
            if ($greeting->matchesContext($context)) {
                return $greeting;
            }
        }
        
        // Return default greeting as fallback
        return static::getDefault();
    }

    /**
     * Check if this greeting matches the given context
     */
    public function matchesContext(array $context): bool
    {
        // UTM campaign
        if ($this->utm_campaign && !empty($context['utm_campaign'])) {
            if (strtolower($this->utm_campaign) !== strtolower($context['utm_campaign'])) {
                return false;
            }
        } elseif ($this->utm_campaign) {
            return false;
        }

        // UTM source
        if ($this->utm_source && !empty($context['utm_source'])) {
            if (strtolower($this->utm_source) !== strtolower($context['utm_source'])) {
                return false;
            }
        } elseif ($this->utm_source) {
            return false;
        }

        // UTM medium
        if ($this->utm_medium && !empty($context['utm_medium'])) {
            if (strtolower($this->utm_medium) !== strtolower($context['utm_medium'])) {
                return false;
            }
        } elseif ($this->utm_medium) {
            return false;
        }

        // URL contains
        if ($this->url_contains && !empty($context['url'])) {
            if (stripos($context['url'], $this->url_contains) === false) {
                return false;
            }
        } elseif ($this->url_contains) {
            return false;
        }

        // Category path
        if ($this->category_path && !empty($context['category'])) {
            if (stripos($context['category'], $this->category_path) === false) {
                return false;
            }
        } elseif ($this->category_path) {
            return false;
        }

        // Device
        if ($this->device !== 'any' && !empty($context['device'])) {
            if ($this->device !== $context['device']) {
                return false;
            }
        }

        // Visitor type
        if ($this->visitor_type !== 'any' && isset($context['is_returning'])) {
            $isReturning = $context['is_returning'];
            if ($this->visitor_type === 'new' && $isReturning) {
                return false;
            }
            if ($this->visitor_type === 'returning' && !$isReturning) {
                return false;
            }
        }

        // Language
        if ($this->language && !empty($context['language'])) {
            $browserLang = substr($context['language'], 0, 2);
            if (strtolower($this->language) !== strtolower($browserLang)) {
                return false;
            }
        } elseif ($this->language) {
            return false;
        }

        // Time range
        if ($this->time_range && isset($this->time_range['start'], $this->time_range['end'])) {
            $now = now()->format('H:i');
            if ($now < $this->time_range['start'] || $now > $this->time_range['end']) {
                return false;
            }
        }

        return true;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    protected $fillable = [
        'sync_type',
        'status',
        'started_at',
        'finished_at',
        'duration_seconds',
        'total_processed',
        'created',
        'updated',
        'skipped',
        'failed',
        'metrics',
        'error_message',
        'notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'metrics' => 'array',
    ];

    // Sync types
    public const TYPE_HOROSHOP_PRODUCTS = 'horoshop_products';
    public const TYPE_ORDERS = 'orders';
    public const TYPE_AI_ENRICHMENT = 'ai_enrichment';
    public const TYPE_MEILISEARCH = 'meilisearch';
    public const TYPE_CATEGORIES = 'categories';
    public const TYPE_EMBEDDINGS = 'embeddings';
    public const TYPE_STATS = 'stats';
    public const TYPE_BRANDS = 'brands';

    // Statuses
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public static array $typeLabels = [
        self::TYPE_HOROSHOP_PRODUCTS => '🛒 Товари (Horoshop)',
        self::TYPE_ORDERS => '📦 Замовлення',
        self::TYPE_AI_ENRICHMENT => '🤖 AI Збагачення',
        self::TYPE_MEILISEARCH => '🔍 Meilisearch',
        self::TYPE_CATEGORIES => '📁 Категорії',
        self::TYPE_EMBEDDINGS => '🧬 Embeddings',
        self::TYPE_STATS => '📊 Статистика',
        self::TYPE_BRANDS => '🏷️ Бренди',
    ];

    public static array $statusLabels = [
        self::STATUS_RUNNING => '⏳ Виконується',
        self::STATUS_COMPLETED => '✅ Завершено',
        self::STATUS_FAILED => '❌ Помилка',
    ];

    /**
     * Start a new sync log
     */
    public static function start(string $type, ?string $notes = null): self
    {
        return self::create([
            'sync_type' => $type,
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
            'notes' => $notes,
        ]);
    }

    /**
     * Complete the sync with stats
     */
    public function complete(array $stats = [], ?array $metrics = null): self
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'finished_at' => now(),
            'duration_seconds' => now()->diffInSeconds($this->started_at),
            'total_processed' => $stats['total_processed'] ?? 0,
            'created' => $stats['created'] ?? 0,
            'updated' => $stats['updated'] ?? 0,
            'skipped' => $stats['skipped'] ?? 0,
            'failed' => $stats['failed'] ?? 0,
            'metrics' => $metrics,
        ]);

        return $this;
    }

    /**
     * Mark as failed
     */
    public function fail(string $errorMessage): self
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'finished_at' => now(),
            'duration_seconds' => now()->diffInSeconds($this->started_at),
            'error_message' => $errorMessage,
        ]);

        return $this;
    }

    /**
     * Get type label
     */
    public function getTypeLabelAttribute(): string
    {
        return self::$typeLabels[$this->sync_type] ?? $this->sync_type;
    }

    /**
     * Get status label
     */
    public function getStatusLabelAttribute(): string
    {
        return self::$statusLabels[$this->status] ?? $this->status;
    }

    /**
     * Get formatted duration
     */
    public function getFormattedDurationAttribute(): string
    {
        if (!$this->duration_seconds) {
            return '-';
        }

        if ($this->duration_seconds < 60) {
            return $this->duration_seconds . ' сек';
        }

        $minutes = floor($this->duration_seconds / 60);
        $seconds = $this->duration_seconds % 60;

        return "{$minutes} хв {$seconds} сек";
    }

    /**
     * Scope for type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('sync_type', $type);
    }

    /**
     * Scope for recent
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('started_at', '>=', now()->subDays($days));
    }

    /**
     * Get last sync for type
     */
    public static function lastForType(string $type): ?self
    {
        return self::where('sync_type', $type)
            ->orderByDesc('started_at')
            ->first();
    }

    /**
     * Get last successful sync for type
     */
    public static function lastSuccessfulForType(string $type): ?self
    {
        return self::where('sync_type', $type)
            ->where('status', self::STATUS_COMPLETED)
            ->orderByDesc('started_at')
            ->first();
    }
}

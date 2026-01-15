<?php

namespace App\Livewire\Admin;

use App\Services\Analytics\AnalyticsExportService;
use Livewire\Component;

/**
 * Exports Manager - Download analytics data as CSV/JSON.
 */
class ExportsManager extends Component
{
    public string $exportType = 'daily_summary';
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $format = 'csv';

    public bool $isExporting = false;
    public ?array $previewData = null;

    public function mount()
    {
        $this->dateFrom = now()->subDays(30)->toDateString();
        $this->dateTo = now()->toDateString();
    }

    public function preview()
    {
        $this->isExporting = true;
        
        try {
            $service = app(AnalyticsExportService::class);
            $tenantId = $this->getTenantId();

            $result = match($this->exportType) {
                'chat_sessions' => $service->exportChatSessions($tenantId, $this->dateFrom, $this->dateTo, 'json'),
                'chat_messages' => $service->exportChatMessages($tenantId, $this->dateFrom, $this->dateTo, null, 'json'),
                'events' => $service->exportEvents($tenantId, $this->dateFrom, $this->dateTo, null, 'json'),
                'payments' => $service->exportPayments($tenantId, $this->dateFrom, $this->dateTo, 'json'),
                'conversion_funnel' => $service->exportConversionFunnel($tenantId, $this->dateFrom, $this->dateTo, 'json'),
                'daily_summary' => $service->exportDailySummary($tenantId, $this->dateFrom, $this->dateTo, 'json'),
                'product_mentions' => $service->exportProductMentions($tenantId, $this->dateFrom, $this->dateTo, 'json'),
                default => ['content' => '[]', 'rows_count' => 0],
            };

            $this->previewData = [
                'rows_count' => $result['rows_count'],
                'data' => array_slice(json_decode($result['content'], true) ?? [], 0, 10),
            ];
        } catch (\Throwable $e) {
            session()->flash('error', 'Помилка: ' . $e->getMessage());
        }

        $this->isExporting = false;
    }

    public function download()
    {
        $tenantId = $this->getTenantId();
        $service = app(AnalyticsExportService::class);

        $result = match($this->exportType) {
            'chat_sessions' => $service->exportChatSessions($tenantId, $this->dateFrom, $this->dateTo, $this->format),
            'chat_messages' => $service->exportChatMessages($tenantId, $this->dateFrom, $this->dateTo, null, $this->format),
            'events' => $service->exportEvents($tenantId, $this->dateFrom, $this->dateTo, null, $this->format),
            'payments' => $service->exportPayments($tenantId, $this->dateFrom, $this->dateTo, $this->format),
            'conversion_funnel' => $service->exportConversionFunnel($tenantId, $this->dateFrom, $this->dateTo, $this->format),
            'daily_summary' => $service->exportDailySummary($tenantId, $this->dateFrom, $this->dateTo, $this->format),
            'product_mentions' => $service->exportProductMentions($tenantId, $this->dateFrom, $this->dateTo, $this->format),
            default => ['content' => '', 'filename' => 'export.csv', 'mime' => 'text/csv'],
        };

        $content = $result['content'];
        
        // Add BOM for CSV
        if ($this->format === 'csv' && $content) {
            $content = "\xEF\xBB\xBF" . $content;
        }

        return response()->streamDownload(
            fn() => print($content),
            $result['filename'],
            ['Content-Type' => $result['mime']]
        );
    }

    private function getTenantId(): ?int
    {
        $user = auth()->user();
        
        if ($user->isSuperAdmin()) {
            return \App\Models\Tenant::first()?->id;
        }

        return $user->tenant_id;
    }

    public function render()
    {
        $exportTypes = [
            ['key' => 'daily_summary', 'label' => 'Денна статистика', 'icon' => '📊', 'description' => 'Агреговані дані по днях'],
            ['key' => 'chat_sessions', 'label' => 'Чат-сесії', 'icon' => '💬', 'description' => 'Всі діалоги з метаданими'],
            ['key' => 'chat_messages', 'label' => 'Повідомлення', 'icon' => '📝', 'description' => 'Детальний лог всіх повідомлень'],
            ['key' => 'events', 'label' => 'Події аналітики', 'icon' => '📈', 'description' => 'Кліки, перегляди, конверсії'],
            ['key' => 'conversion_funnel', 'label' => 'Воронка конверсій', 'icon' => '🎯', 'description' => 'Дані по етапах воронки'],
            ['key' => 'product_mentions', 'label' => 'Згадування товарів', 'icon' => '🛍️', 'description' => 'Топ товарів з чатів'],
            ['key' => 'payments', 'label' => 'Платежі', 'icon' => '💳', 'description' => 'Історія оплат'],
        ];

        return view('livewire.admin.exports-manager', [
            'exportTypes' => $exportTypes,
        ])->layout('admin.layout');
    }
}

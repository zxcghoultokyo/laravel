<?php

namespace App\Http\Controllers\Api;

use App\Services\Analytics\AnalyticsExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Analytics Export Controller - download CSV/JSON exports.
 */
class AnalyticsExportController extends Controller
{
    public function __construct(
        private AnalyticsExportService $exportService
    ) {}

    /**
     * Export chat sessions.
     */
    public function chatSessions(Request $request): Response|JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $result = $this->exportService->exportChatSessions(
            $tenantId,
            $request->input('date_from'),
            $request->input('date_to'),
            $request->input('format', 'csv')
        );

        return $this->createDownloadResponse($result, $request->boolean('download', true));
    }

    /**
     * Export chat messages.
     */
    public function chatMessages(Request $request): Response|JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $result = $this->exportService->exportChatMessages(
            $tenantId,
            $request->input('date_from'),
            $request->input('date_to'),
            $request->input('session_id'),
            $request->input('format', 'csv')
        );

        return $this->createDownloadResponse($result, $request->boolean('download', true));
    }

    /**
     * Export analytics events.
     */
    public function events(Request $request): Response|JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $result = $this->exportService->exportEvents(
            $tenantId,
            $request->input('date_from'),
            $request->input('date_to'),
            $request->input('event_type'),
            $request->input('format', 'csv')
        );

        return $this->createDownloadResponse($result, $request->boolean('download', true));
    }

    /**
     * Export payment history.
     */
    public function payments(Request $request): Response|JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $result = $this->exportService->exportPayments(
            $tenantId,
            $request->input('date_from'),
            $request->input('date_to'),
            $request->input('format', 'csv')
        );

        return $this->createDownloadResponse($result, $request->boolean('download', true));
    }

    /**
     * Export conversion funnel data.
     */
    public function conversionFunnel(Request $request): Response|JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $result = $this->exportService->exportConversionFunnel(
            $tenantId,
            $request->input('date_from'),
            $request->input('date_to'),
            $request->input('format', 'csv')
        );

        return $this->createDownloadResponse($result, $request->boolean('download', true));
    }

    /**
     * Export daily summary.
     */
    public function dailySummary(Request $request): Response|JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $result = $this->exportService->exportDailySummary(
            $tenantId,
            $request->input('date_from'),
            $request->input('date_to'),
            $request->input('format', 'csv')
        );

        return $this->createDownloadResponse($result, $request->boolean('download', true));
    }

    /**
     * Export product mentions.
     */
    public function productMentions(Request $request): Response|JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $result = $this->exportService->exportProductMentions(
            $tenantId,
            $request->input('date_from'),
            $request->input('date_to'),
            $request->input('format', 'csv')
        );

        return $this->createDownloadResponse($result, $request->boolean('download', true));
    }

    /**
     * Create download response.
     */
    private function createDownloadResponse(array $result, bool $download): Response|JsonResponse
    {
        if (!$download) {
            return response()->json([
                'format' => $result['format'],
                'filename' => $result['filename'],
                'rows_count' => $result['rows_count'],
                'preview' => $result['format'] === 'json' 
                    ? json_decode($result['content'], true)
                    : array_slice(explode("\n", $result['content']), 0, 10),
            ]);
        }

        $headers = [
            'Content-Type' => $result['mime'],
            'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
        ];

        // Add BOM for UTF-8 CSV for Excel compatibility
        $content = $result['content'];
        if ($result['format'] === 'csv' && $content) {
            $content = "\xEF\xBB\xBF" . $content;
        }

        return response($content, 200, $headers);
    }

    /**
     * Get tenant ID from request.
     */
    private function getTenantId(Request $request): ?int
    {
        // From auth
        if ($request->user() && $request->user()->tenant_id) {
            return $request->user()->tenant_id;
        }

        // From header
        if ($tenantId = $request->header('X-Tenant-Id')) {
            return (int) $tenantId;
        }

        // From query (for API key auth)
        if ($apiKey = $request->input('api_key') ?? $request->header('X-API-Key')) {
            $tenant = \App\Models\Tenant::where('api_key', $apiKey)->first();
            return $tenant?->id;
        }

        return null;
    }
}

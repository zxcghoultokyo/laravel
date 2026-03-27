<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Agent\StreamingFunctionCallingAgent;
use App\Services\Chat\PipelineTracer;
use App\Services\Metrics\MetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streaming chat endpoint using Server-Sent Events (SSE).
 *
 * Usage from frontend:
 * ```javascript
 * const eventSource = new EventSource('/api/chat/stream?message=плитоноска&session_id=xxx');
 * eventSource.onmessage = (e) => {
 *   const data = JSON.parse(e.data);
 *   if (data.type === 'chunk') {
 *     appendText(data.text);
 *   } else if (data.type === 'products') {
 *     showProducts(data.products);
 *   } else if (data.type === 'done') {
 *     eventSource.close();
 *   }
 * };
 * ```
 *
 * Test with curl:
 * curl -N "http://localhost:8000/api/chat/stream?message=плитоноска&session_id=test123"
 */
class StreamingChatController extends Controller
{
    private const MAX_MESSAGE_LENGTH = 2000;

    private const MIN_SESSION_ID_LENGTH = 8;

    public function __construct(
        protected StreamingFunctionCallingAgent $streamingAgent,
        protected MetricsService $metricsService,
    ) {}

    /**
     * Stream chat response using SSE.
     */
    public function stream(Request $request): StreamedResponse
    {
        $message = $this->sanitizeMessage($request->input('message', ''));
        $sessionId = $this->validateSessionId($request->input('session_id'));
        $requestId = (string) Str::uuid();
        $debugMode = $request->input('debug_key') === config('app.diagnostic_key', 'diagnostic_secret_key_2025');

        Log::info('StreamingChatController: starting stream', [
            'request_id' => $requestId,
            'message' => mb_substr($message, 0, 100),
            'session_id' => $sessionId,
        ]);

        return new StreamedResponse(function () use ($message, $sessionId, $requestId, $debugMode) {
            // Disable output buffering
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            // Start pipeline tracing
            $tracer = PipelineTracer::start($sessionId, $message);
            $tracer->step('controller.entry', [
                'request_id' => $requestId,
                'debug_mode' => $debugMode,
            ]);

            // Send initial keepalive to establish connection
            $this->sendEvent('status', [
                'text' => 'Обробляю...',
                'session_id' => $sessionId,
                'request_id' => $requestId,
            ]);

            // Validate input
            if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
                $this->sendEvent('error', [
                    'text' => 'Повідомлення занадто довге. Скоротіть до '.self::MAX_MESSAGE_LENGTH.' символів.',
                    'session_id' => $sessionId,
                    'request_id' => $requestId,
                ]);
                $this->sendEvent('done', ['session_id' => $sessionId]);

                return;
            }

            if (trim($message) === '') {
                $this->sendEvent('error', [
                    'text' => 'Напишіть, будь ласка, запит 🙂',
                    'session_id' => $sessionId,
                    'request_id' => $requestId,
                ]);
                $this->sendEvent('done', ['session_id' => $sessionId]);

                return;
            }

            // Check if operator has taken over this session
            $session = $this->metricsService->getSession($sessionId);
            if ($session && $session->status === 'operator') {
                Log::info('StreamingChatController: operator mode active, skipping AI', [
                    'request_id' => $requestId,
                    'session_id' => $sessionId,
                ]);

                // Still save the user message to DB for operator to see
                $this->saveUserMessage($sessionId, $message);

                $this->sendEvent('chunk', [
                    'text' => 'Ваше повідомлення передано оператору. Очікуйте відповіді.',
                ]);
                $this->sendEvent('done', ['session_id' => $sessionId]);

                return;
            }

            // Check message limit for tenant
            $tenantContext = app(\App\Services\Tenant\TenantContext::class);
            $tenant = $tenantContext->getTenant();
            if ($tenant && ! $tenant->canSendMessage()) {
                Log::warning('StreamingChatController: message limit exceeded', [
                    'request_id' => $requestId,
                    'tenant_id' => $tenant->id,
                    'messages_used' => $tenant->messages_used,
                    'messages_limit' => $tenant->messages_limit,
                ]);

                $this->sendEvent('chunk', [
                    'text' => 'На жаль, вичерпано ліміт повідомлень на цей місяць. Зверніться до адміністратора магазину.',
                ]);
                $this->sendEvent('done', ['session_id' => $sessionId]);

                return;
            }

            try {
                // Set context for prompt preset matching
                $presetContext = $this->buildPresetContext($sessionId, $message);
                $this->streamingAgent->setContext($presetContext);
                $tracer->step('controller.preset_context', ['keys' => array_keys($presetContext)]);

                // Stream response from agent
                $responseGenerated = false;
                foreach ($this->streamingAgent->stream($message, $sessionId) as $event) {
                    $type = $event['type'] ?? 'chunk';
                    $data = $event['data'] ?? [];
                    $data['session_id'] = $sessionId;
                    $data['request_id'] = $requestId;

                    // Attach trace to done event when debug mode
                    if ($type === 'done') {
                        $trace = $tracer->finish();
                        if ($debugMode) {
                            $data['trace'] = $trace;
                        }
                        $data['trace_summary'] = $tracer->getSummary();
                    }

                    $this->sendEvent($type, $data);

                    // Mark that we got a real response (not just status)
                    if ($type === 'chunk' || $type === 'products' || $type === 'text') {
                        $responseGenerated = true;
                    }
                }

                // Increment message usage ONLY if AI generated a response
                if ($tenant && $responseGenerated) {
                    $tenant->incrementMessageUsage();
                }

            } catch (\Throwable $e) {
                Log::error('StreamingChatController: error', [
                    'request_id' => $requestId,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile().':'.$e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Save error as assistant message so chat history is not orphaned
                $this->logErrorAsAssistantMessage($sessionId, $e);

                $errorData = [
                    'text' => 'Сталася помилка. Спробуйте ще раз 🙏',
                    'session_id' => $sessionId,
                    'request_id' => $requestId,
                ];

                if ($debugMode) {
                    $errorData['debug_error'] = $e->getMessage();
                    $errorData['debug_file'] = $e->getFile().':'.$e->getLine();
                    $errorData['debug_class'] = get_class($e);
                }

                $tracer->step('controller.error', ['error' => $e->getMessage()]);
                $trace = $tracer->finish();

                $this->sendEvent('error', $errorData);
                $doneData = ['session_id' => $sessionId];
                if ($debugMode) {
                    $doneData['trace'] = $trace;
                }
                $doneData['trace_summary'] = $tracer->getSummary();
                $this->sendEvent('done', $doneData);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Widget-Token',
        ]);
    }

    /**
     * Send SSE event.
     */
    private function sendEvent(string $type, array $data): void
    {
        $data['type'] = $type;
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * Sanitize user message.
     */
    private function sanitizeMessage(mixed $input): string
    {
        if (! is_string($input)) {
            return '';
        }

        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $input);
        $message = preg_replace('/\s+/', ' ', $message);

        return trim($message);
    }

    /**
     * Validate session ID.
     */
    private function validateSessionId(mixed $input): string
    {
        if (! is_string($input) || mb_strlen(trim($input)) < self::MIN_SESSION_ID_LENGTH) {
            return (string) Str::uuid();
        }

        $normalized = preg_replace('/[^a-zA-Z0-9_-]/', '', $input);

        if (mb_strlen($normalized) < self::MIN_SESSION_ID_LENGTH) {
            return (string) Str::uuid();
        }

        return substr($normalized, 0, 64);
    }

    /**
     * Save user message to DB (for operator mode).
     */
    private function saveUserMessage(string $sessionId, string $content): void
    {
        try {
            // Get tenant_id from TenantContext
            $tenantId = app(\App\Services\Tenant\TenantContext::class)->getTenantId();

            $chatSession = \App\Models\ChatSession::firstOrCreate(
                ['session_id' => $sessionId],
                [
                    'tenant_id' => $tenantId,
                    'status' => 'open',
                    'started_at' => now(),
                ]
            );

            // Update tenant_id if session exists but has null tenant_id
            if ($chatSession->tenant_id === null && $tenantId !== null) {
                $chatSession->update(['tenant_id' => $tenantId]);
            }

            \App\Models\ChatMessage::create([
                'chat_session_id' => $chatSession->id,
                'role' => 'user',
                'content' => $content,
                'meta' => ['operator_mode' => true],
            ]);

            $chatSession->update([
                'last_message_at' => now(),
                'messages_count' => $chatSession->messages_count + 1,
            ]);

            Log::info('StreamingChatController: user message saved in operator mode', [
                'session_id' => $sessionId,
            ]);
        } catch (\Throwable $e) {
            Log::error('StreamingChatController: failed to save user message', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log error as assistant message to prevent orphaned user messages.
     */
    private function logErrorAsAssistantMessage(string $sessionId, \Throwable $exception): void
    {
        try {
            $session = \App\Models\ChatSession::where('session_id', $sessionId)->first();
            if (! $session) {
                return;
            }

            \App\Models\ChatMessage::create([
                'chat_session_id' => $session->id,
                'role' => 'assistant',
                'content' => 'Сталася помилка. Спробуйте ще раз 🙏',
                'meta' => [
                    'intent' => 'error',
                    'error' => mb_substr($exception->getMessage(), 0, 500),
                ],
            ]);

            $session->update([
                'last_message_at' => now(),
                'messages_count' => ($session->messages_count ?? 0) + 1,
            ]);
        } catch (\Throwable $e) {
            Log::warning('StreamingChatController: failed to log error as assistant message', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build context for prompt preset matching.
     */
    private function buildPresetContext(string $sessionId, string $message): array
    {
        $context = [];

        // CRITICAL: tenant_id for multi-tenant preset isolation
        $tenantId = app(\App\Services\Tenant\TenantContext::class)->getTenantId();
        if ($tenantId) {
            $context['tenant_id'] = $tenantId;
        }

        // Detect language from message
        $context['language'] = $this->detectLanguage($message);

        // Load session data for UTM and other context
        $session = \App\Models\ChatSession::where('session_id', $sessionId)->first();

        if ($session && is_array($session->meta)) {
            // Get UTM campaign if stored
            if (! empty($session->meta['utm_campaign'])) {
                $context['campaign'] = $session->meta['utm_campaign'];
            }

            // Get tone preference if stored
            if (! empty($session->meta['tone'])) {
                $context['tone'] = $session->meta['tone'];
            }

            // Get categories from last context
            if (! empty($session->meta['last_categories'])) {
                $context['categories'] = $session->meta['last_categories'];
            }
        }

        return array_filter($context);
    }

    /**
     * Simple language detection from message.
     */
    private function detectLanguage(string $message): string
    {
        // Check for Cyrillic (Ukrainian/Russian)
        if (preg_match('/[а-яА-ЯіїєґІЇЄҐ]/u', $message)) {
            // Check for Ukrainian-specific letters
            if (preg_match('/[іїєґІЇЄҐ]/u', $message)) {
                return 'uk';
            }

            return 'uk';
        }

        // Latin characters - English
        if (preg_match('/[a-zA-Z]/u', $message)) {
            return 'en';
        }

        return 'uk';
    }
}

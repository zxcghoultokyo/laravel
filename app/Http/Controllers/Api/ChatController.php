<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Chat\ChatService;
use App\Services\Metrics\MetricsService;
use App\Services\Escalation\EscalationService;
use App\Events\NewChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function __construct(
        protected ChatService $chatService,
        protected MetricsService $metricsService,
        protected EscalationService $escalationService,
    ) {}

    private const MAX_MESSAGE_LENGTH = 2000;
    private const MIN_SESSION_ID_LENGTH = 8;

    public function handle(Request $request)
    {
        $payload = $request->all();
        $requestId = (string) Str::uuid();

        Log::info('ChatController::handle incoming', [
            'request_id' => $requestId,
            'payload' => $payload,
            'ip' => $request->ip(),
        ]);

        try {
            // Sanitize and validate message
            $message = $this->sanitizeMessage($payload['message'] ?? '');
            $sessionId = $this->validateSessionId($payload['session_id'] ?? null);

            // Check message length
            if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
                return response()->json([
                    'type'       => 'text',
                    'text'       => 'Повідомлення занадто довге. Скоротіть до ' . self::MAX_MESSAGE_LENGTH . ' символів.',
                    'data'       => null,
                    'session_id' => $sessionId,
                    'meta'       => ['request_id' => $requestId],
                ], 400);
            }

            // Check for empty message
            if (trim($message) === '') {
                return response()->json([
                    'type'       => 'text',
                    'text'       => 'Напишіть, будь ласка, запит 🙂',
                    'data'       => null,
                    'session_id' => $sessionId,
                    'meta'       => ['request_id' => $requestId],
                ]);
            }

            // Check if session is in operator mode
            $session = $this->metricsService->getSession($sessionId);
            if ($session && $session->status === 'operator') {
                // Broadcast user message to operator, don't process with AI
                try {
                    event(new NewChatMessage($sessionId, $message, 'user', [
                        'request_id' => $requestId,
                    ]));
                } catch (\Throwable $e) {
                    Log::warning('ChatController: failed to broadcast to operator', ['error' => $e->getMessage()]);
                }

                return response()->json([
                    'type' => 'text',
                    'text' => 'Ваше повідомлення передано оператору. Очікуйте відповідь...',
                    'session_id' => $sessionId,
                    'meta' => ['request_id' => $requestId, 'operator_mode' => true],
                ]);
            }

            $startTime = microtime(true);
            $response = $this->chatService->handleMessage($message, $sessionId);
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);

            // Check if session needs escalation to human operator
            if (config('services.escalation.enabled', true)) {
                $this->escalationService->checkAndEscalate($sessionId, $response, $message);
            }

            // Record metrics
            $this->metricsService->recordRequest([
                'request_id' => $requestId,
                'session_id' => $sessionId,
                'intent' => $response['meta']['intent'] ?? null,
                'response_time_ms' => $responseTime,
                'products_count' => count($response['products'] ?? []),
                'cache_hit' => $response['meta']['cache_hit'] ?? false,
                'is_fallback' => $response['meta']['is_fallback'] ?? false,
            ]);

            // Update active session
            $this->metricsService->updateActiveSession($sessionId, [
                'status' => 'ai',
                'message_count' => DB::raw('COALESCE(message_count, 0) + 1'),
                'last_query' => mb_substr($message, 0, 255),
                'context' => json_encode([
                    'shown_products' => array_slice($response['products'] ?? [], 0, 5),
                    'intent' => $response['meta']['intent'] ?? null,
                ]),
            ]);

            // Broadcast for admin dashboard
            try {
                event(new NewChatMessage($sessionId, $message, 'user', ['request_id' => $requestId]));
                event(new NewChatMessage($sessionId, $response['text'] ?? '', 'ai', [
                    'request_id' => $requestId,
                    'products_count' => count($response['products'] ?? []),
                ]));
            } catch (\Throwable $e) {
                // Don't fail if broadcast fails
                Log::debug('ChatController: broadcast failed', ['error' => $e->getMessage()]);
            }

            // Повертаємо session_id та request_id завжди
            $response['session_id'] = $sessionId;
            $response['meta'] = array_merge($response['meta'] ?? [], [
                'request_id' => $requestId,
            ]);

            Log::info('ChatController::handle response', [
                'request_id' => $requestId,
                'type' => $response['type'] ?? 'unknown',
                'products_count' => count($response['products'] ?? []),
            ]);

            return response()->json($response);
        } catch (\Throwable $e) {
            Log::error('ChatController::handle exception', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'type'       => 'text',
                'text'       => 'Сталася помилка. Спробуйте ще раз 🙏',
                'data'       => null,
                'session_id' => $sessionId ?? $payload['session_id'] ?? null,
                'meta'       => ['request_id' => $requestId, 'error' => true],
            ], 500);
        }
    }

    /**
     * Sanitize user message.
     * Removes control characters, normalizes whitespace.
     */
    private function sanitizeMessage(mixed $input): string
    {
        if (!is_string($input)) {
            return '';
        }

        // Remove control characters except newlines
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $input);
        
        // Normalize whitespace
        $message = preg_replace('/\s+/', ' ', $message);
        
        return trim($message);
    }

    /**
     * Validate and normalize session ID.
     */
    private function validateSessionId(mixed $input): string
    {
        if (!is_string($input) || mb_strlen(trim($input)) < self::MIN_SESSION_ID_LENGTH) {
            return (string) Str::uuid();
        }

        // Remove invalid characters
        $normalized = preg_replace('/[^a-zA-Z0-9_-]/', '', $input);
        
        if (mb_strlen($normalized) < self::MIN_SESSION_ID_LENGTH) {
            return (string) Str::uuid();
        }

        return substr($normalized, 0, 64);
    }

    /**
     * Clear/delete a chat session from DB and cache.
     */
    public function clearSession(string $sessionId)
    {
        Log::info('ChatController::clearSession', ['session_id' => $sessionId]);

        try {
            // Clear from ChatService cache
            $this->chatService->clearSession($sessionId);

            // Delete from database
            $chatSession = \App\Models\ChatSession::where('session_id', $sessionId)->first();
            if ($chatSession) {
                \App\Models\ChatMessage::where('chat_session_id', $chatSession->id)->delete();
                $chatSession->delete();
            }

            return response()->json([
                'success' => true,
                'message' => 'Сесію видалено',
            ]);
        } catch (\Throwable $e) {
            Log::error('ChatController::clearSession failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Не вдалося видалити сесію',
            ], 500);
        }
    }

    /**
     * Poll for new operator messages and operator mode status.
     * Called periodically by widget to receive operator responses.
     */
    public function poll(Request $request, string $sessionId)
    {
        $lastMessageId = (int) $request->input('last_message_id', 0);
        
        // Check operator mode status
        $activeSession = $this->metricsService->getSession($sessionId);
        $isOperatorMode = $activeSession && $activeSession->status === 'operator';
        
        // Get new messages if in operator mode
        $newMessages = [];
        if ($isOperatorMode) {
            $chatSession = \App\Models\ChatSession::where('session_id', $sessionId)->first();
            if ($chatSession) {
                $query = \App\Models\ChatMessage::where('chat_session_id', $chatSession->id)
                    ->where('role', 'operator')
                    ->orderBy('id', 'asc');
                    
                if ($lastMessageId > 0) {
                    $query->where('id', '>', $lastMessageId);
                }
                
                $messages = $query->get();
                
                foreach ($messages as $msg) {
                    $newMessages[] = [
                        'id' => $msg->id,
                        'content' => $msg->content,
                        'role' => 'operator',
                        'created_at' => $msg->created_at->toIso8601String(),
                    ];
                }
            }
        }
        
        return response()->json([
            'operator_mode' => $isOperatorMode,
            'messages' => $newMessages,
            'session_id' => $sessionId,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Chat\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function __construct(
        protected ChatService $chatService
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

            $response = $this->chatService->handleMessage($message, $sessionId);

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
}

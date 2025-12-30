<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Chat\ChatService;
use App\Services\Ai\AiRouter;
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
 */
class StreamingChatController extends Controller
{
    private const MAX_MESSAGE_LENGTH = 2000;
    private const MIN_SESSION_ID_LENGTH = 8;

    public function __construct(
        protected ChatService $chatService,
        protected AiRouter $aiRouter,
    ) {}

    /**
     * Stream chat response using SSE.
     */
    public function stream(Request $request): StreamedResponse
    {
        $message = $this->sanitizeMessage($request->input('message', ''));
        $sessionId = $this->validateSessionId($request->input('session_id'));
        $requestId = (string) Str::uuid();

        Log::info('StreamingChatController: starting stream', [
            'request_id' => $requestId,
            'message' => mb_substr($message, 0, 100),
            'session_id' => $sessionId,
        ]);

        return new StreamedResponse(function () use ($message, $sessionId, $requestId) {
            // Set headers for SSE
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // Nginx

            // Validate input
            if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
                $this->sendEvent('error', [
                    'text' => 'Повідомлення занадто довге. Скоротіть до ' . self::MAX_MESSAGE_LENGTH . ' символів.',
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

            try {
                // Step 1: Send "thinking" status
                $this->sendEvent('status', [
                    'text' => 'Аналізую запит...',
                    'phase' => 'classify',
                ]);
                flush();

                // Step 2: Get full response (for now, we'll stream the text part)
                $response = $this->chatService->handleMessage($message, $sessionId);

                // Step 3: If we have products, send status then products
                if (!empty($response['products'])) {
                    $this->sendEvent('status', [
                        'text' => 'Знайшов ' . count($response['products']) . ' товарів...',
                        'phase' => 'search',
                    ]);
                    flush();
                    
                    // Stream the narrative text in chunks
                    $text = $response['message'] ?? $response['text'] ?? '';
                    if (!empty($text)) {
                        $this->streamText($text);
                    }

                    // Send products
                    $this->sendEvent('products', [
                        'products' => $response['products'],
                        'count' => count($response['products']),
                    ]);
                } else {
                    // Text-only response - stream it
                    $text = $response['text'] ?? $response['message'] ?? '';
                    if (!empty($text)) {
                        $this->streamText($text);
                    }
                }

                // Send completion
                $this->sendEvent('done', [
                    'session_id' => $sessionId,
                    'request_id' => $requestId,
                    'meta' => $response['meta'] ?? [],
                ]);

            } catch (\Throwable $e) {
                Log::error('StreamingChatController: error', [
                    'request_id' => $requestId,
                    'error' => $e->getMessage(),
                ]);

                $this->sendEvent('error', [
                    'text' => 'Сталася помилка. Спробуйте ще раз 🙏',
                    'session_id' => $sessionId,
                    'request_id' => $requestId,
                ]);
                $this->sendEvent('done', ['session_id' => $sessionId]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Stream text in chunks to simulate typing effect.
     */
    private function streamText(string $text): void
    {
        // Split by sentences for natural streaming
        $sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        
        foreach ($sentences as $sentence) {
            $this->sendEvent('chunk', ['text' => $sentence . ' ']);
            flush();
            usleep(50000); // 50ms delay between sentences
        }
    }

    /**
     * Send SSE event.
     */
    private function sendEvent(string $type, array $data): void
    {
        $data['type'] = $type;
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        
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
        if (!is_string($input)) {
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
        if (!is_string($input) || mb_strlen(trim($input)) < self::MIN_SESSION_ID_LENGTH) {
            return (string) Str::uuid();
        }

        $normalized = preg_replace('/[^a-zA-Z0-9_-]/', '', $input);
        
        if (mb_strlen($normalized) < self::MIN_SESSION_ID_LENGTH) {
            return (string) Str::uuid();
        }

        return substr($normalized, 0, 64);
    }
}

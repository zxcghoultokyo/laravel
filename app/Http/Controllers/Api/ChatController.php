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

    public function handle(Request $request)
    {
        $payload = $request->all();

        Log::info('ChatController::handle incoming', ['payload' => $payload]);

        try {
            $message   = (string) ($payload['message'] ?? '');
            $sessionId = $payload['session_id'] ?? null;

            // Якщо фронт не передав session_id — генеримо, щоб контекст не губився
            if (! is_string($sessionId) || trim($sessionId) === '') {
                $sessionId = (string) Str::uuid();
            }

            if (trim($message) === '') {
                return response()->json([
                    'type'       => 'text',
                    'text'       => 'Напишіть, будь ласка, запит 🙂',
                    'data'       => null,
                    'session_id' => $sessionId,
                ]);
            }

            $response = $this->chatService->handleMessage($message, $sessionId, [
                'session_id' => $sessionId,
            ]);

            // Повертаємо session_id завжди
            $response['session_id'] = $sessionId;

            Log::info('ChatController::handle response', ['response' => $response]);

            return response()->json($response);
        } catch (\Throwable $e) {
            Log::error('ChatController::handle exception: ' . $e->getMessage(), ['exception' => $e]);

            return response()->json([
                'type'       => 'text',
                'text'       => 'Сталася помилка. Спробуйте ще раз 🙏',
                'data'       => null,
                'session_id' => $payload['session_id'] ?? null,
            ], 500);
        }
    }
}

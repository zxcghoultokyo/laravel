<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Chat\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    protected ChatService $chatService;

    public function __construct(ChatService $chatService)
    {
        $this->chatService = $chatService;
    }

    public function handle(Request $request)
    {
        $payload = $request->all();

        Log::info('ChatController::handle incoming', [
            'payload' => $payload,
        ]);

        try {
            $message   = (string) ($payload['message'] ?? '');
            $sessionId = $payload['session_id'] ?? null;
            $context   = $payload['context'] ?? [];

            // ТУТ ВАЖЛИВО: підстав свій метод, якщо він у тебе називається не handleMessage, а handle
            $response = $this->chatService->handleMessage(
                $message,
                $sessionId,
                is_array($context) ? $context : []
            );

            Log::info('ChatController::handle response', [
                'response' => $response,
            ]);

            return response()->json($response);
        } catch (\Throwable $e) {
            Log::error('ChatController::handle exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'type' => 'message',
                'text' => 'Сталася технічна помилка на сервері. Спробуй, будь ласка, ще раз пізніше 🛠️',
            ], 500);
        }
    }
}

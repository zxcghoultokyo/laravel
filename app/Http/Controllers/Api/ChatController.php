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
        $message   = (string) $request->input('message', '');
        $sessionId = $request->input('session_id');

        // Якщо фронт не передав session_id — генеруємо тут,
        // щоб сесія існувала вже з першого запиту.
        if (!is_string($sessionId) || trim($sessionId) === '') {
            $sessionId = (string) Str::uuid();
        } else {
            $sessionId = trim($sessionId);
        }

        Log::info('ChatController::handle incoming', [
            'payload' => [
                'message'    => $message,
                'session_id' => $sessionId,
            ],
        ]);

        if (trim($message) === '') {
            return response()->json([
                'type'       => 'text',
                'text'       => 'Напишіть, будь ласка, запит 🙂',
                'data'       => null,
                'session_id' => $sessionId,
            ]);
        }

        $response = $this->chatService->handleMessage($message, $sessionId);

        // Завжди додаємо session_id у відповідь,
        // щоб фронт міг зберегти/підтвердити його.
        $response['session_id'] = $sessionId;

        Log::info('ChatController::handle response', [
            'response' => $response,
        ]);

        return response()->json($response);
    }
}

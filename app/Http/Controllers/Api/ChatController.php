<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Chat\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    public function __construct(
        protected ChatService $chatService
    ) {}

    public function handle(Request $request)
    {
        $message    = (string) $request->input('message', '');
        $sessionId  = $request->input('session_id'); // може бути null

        Log::info('ChatController::handle incoming', [
            'payload' => [
                'message'    => $message,
                'session_id' => $sessionId,
            ],
        ]);

        if (trim($message) === '') {
            return response()->json([
                'type' => 'text',
                'text' => 'Напишіть, будь ласка, запит 🙂',
                'data' => null,
            ]);
        }

        $response = $this->chatService->handleMessage($message, $sessionId);

        Log::info('ChatController::handle response', [
            'response' => $response,
        ]);

        return response()->json($response);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Chat\ChatService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ChatController extends Controller
{
    public function __construct(
        protected ChatService $chatService,
    ) {
    }

    /**
     * POST /api/chat
     *
     * Body:
     * {
     *   "message": "турнікети",
     *   "session_id": "optional-session-id"
     * }
     */
    public function handle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message'    => ['required', 'string', 'max:2000'],
            'session_id' => ['nullable', 'string', 'max:255'],
        ]);

        $response = $this->chatService->handleMessage(
            $validated['message'],
            $validated['session_id'] ?? null
        );

        return response()->json($response);
    }
}

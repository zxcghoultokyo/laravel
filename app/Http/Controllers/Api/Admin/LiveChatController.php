<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Metrics\MetricsService;
use App\Services\Session\SessionContextService;
use App\Events\OperatorMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Controller for admin live chat management.
 * Allows operators to monitor and take over AI chats.
 */
class LiveChatController extends Controller
{
    public function __construct(
        protected MetricsService $metricsService,
        protected SessionContextService $sessionService,
    ) {}

    /**
     * Get active chat sessions.
     */
    public function active(Request $request): JsonResponse
    {
        $sessions = $this->metricsService->getActiveSessions(
            $request->integer('limit', 50)
        );

        return response()->json([
            'sessions' => $sessions,
            'count' => count($sessions),
        ]);
    }

    /**
     * Take over a chat session (switch from AI to operator).
     */
    public function takeover(Request $request, string $sessionId): JsonResponse
    {
        // For now, use a placeholder operator ID (in real app, get from auth)
        $operatorId = $request->integer('operator_id', 1);

        $session = $this->metricsService->getSession($sessionId);
        
        if ($session && $session->status === 'operator') {
            return response()->json([
                'error' => 'Session already taken over by operator',
                'operator_id' => $session->operator_id,
            ], 409);
        }

        $this->metricsService->markOperatorTakeover($sessionId, $operatorId);

        // Broadcast to the chat widget that operator took over
        // This requires WebSocket/Pusher to be configured
        try {
            event(new OperatorMessage($sessionId, [
                'type' => 'takeover',
                'message' => "Оператор приєднався до чату. Тепер вам відповідає жива людина 👋",
                'operator_id' => $operatorId,
            ]));
        } catch (\Throwable $e) {
            Log::warning('LiveChatController: failed to broadcast takeover', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('LiveChatController: operator took over session', [
            'session_id' => $sessionId,
            'operator_id' => $operatorId,
        ]);

        return response()->json([
            'message' => 'Session taken over successfully',
            'session_id' => $sessionId,
            'operator_id' => $operatorId,
            'context' => $this->sessionService->loadContext($sessionId),
        ]);
    }

    /**
     * Release session back to AI.
     */
    public function release(string $sessionId): JsonResponse
    {
        $this->metricsService->releaseSession($sessionId);

        try {
            event(new OperatorMessage($sessionId, [
                'type' => 'release',
                'message' => "Оператор передав чат назад AI-асистенту. Я готовий допомогти! 🤖",
            ]));
        } catch (\Throwable $e) {
            Log::warning('LiveChatController: failed to broadcast release', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Session released back to AI',
            'session_id' => $sessionId,
        ]);
    }

    /**
     * Send a message as operator.
     */
    public function sendMessage(Request $request, string $sessionId): JsonResponse
    {
        $message = $request->string('message');
        
        if (empty($message)) {
            return response()->json(['error' => 'Message is required'], 400);
        }

        $session = $this->metricsService->getSession($sessionId);
        
        if (!$session || $session->status !== 'operator') {
            return response()->json([
                'error' => 'Session is not in operator mode. Take over first.',
            ], 400);
        }

        // Broadcast operator message to chat widget
        try {
            event(new OperatorMessage($sessionId, [
                'type' => 'message',
                'text' => $message,
                'operator_id' => $session->operator_id,
                'timestamp' => now()->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            Log::warning('LiveChatController: failed to broadcast message', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Failed to send message. WebSocket may not be configured.',
                'details' => $e->getMessage(),
            ], 500);
        }

        // Update session activity
        $this->metricsService->updateActiveSession($sessionId, [
            'message_count' => DB::raw('message_count + 1'),
        ]);

        return response()->json([
            'message' => 'Message sent',
            'session_id' => $sessionId,
        ]);
    }
}

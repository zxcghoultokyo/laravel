<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiSalesAgent;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ChatController extends Controller
{
    public function __construct(
        protected AiSalesAgent $aiSalesAgent,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $response = $this->aiSalesAgent->respond($data['message']);

        return response()->json($response);
    }
}

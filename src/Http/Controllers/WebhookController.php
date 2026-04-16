<?php

namespace Anwar\AgentOrchestrator\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Anwar\AgentOrchestrator\Services\AiAgentService;

class WebhookController extends Controller
{
    protected AiAgentService $aiAgentService;

    public function __construct(AiAgentService $aiAgentService)
    {
        $this->aiAgentService = $aiAgentService;
    }

    /**
     * Handle the incoming webhook from n8n.
     */
    public function handleWebhook(Request $request)
    {
        // 1. Authenticate the request
        $expectedSecret = config('agent.webhook_secret');
        $bearerToken = $request->bearerToken();

        if (empty($expectedSecret) || $bearerToken !== $expectedSecret) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        // 2. Validate the payload
        $validated = $request->validate([
            'phone' => 'required|string',
            'message' => 'required|string',
            'platform' => 'nullable|string',
            'session_id' => 'nullable|string',
        ]);

        try {
            $platform = $validated['platform'] ?? 'web';

            // 3. Process the message using the AI Agent Service
            $finalMessage = $this->aiAgentService->processMessage(
                $validated['phone'],
                $validated['message'],
                $platform,
                $validated['session_id'] ?? null
            );

            // 4. Return the formatted response for n8n
            return response()->json([
                'success' => true,
                'reply' => $finalMessage,
            ]);
        } catch (\Exception $e) {
            \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('error', 'Agent Orchestrator Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'payload' => $validated ?? [],
            ]);

            return response()->json([
                'success' => false,
                'error' => 'An error occurred while processing the message.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}

<?php

namespace Anwar\AgentOrchestrator\Providers;

use Anwar\AgentOrchestrator\Contracts\AiProviderInterface;
use OpenAI\Laravel\Facades\OpenAI;

class OpenAiProvider implements AiProviderInterface
{
    /**
     * Generate response via OpenAI SDK.
     */
    public function generateResponse(string $systemPrompt, string $userPrompt, array $history = []): string
    {
        $model = config('agent.openai_model', 'gpt-4o-mini');

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Merge history if available
        foreach ($history as $turn) {
            $messages[] = $turn;
        }

        $messages[] = ['role' => 'user', 'content' => $userPrompt];

        try {
            $response = OpenAI::chat()->create([
                'model' => $model,
                'messages' => $messages,
                'stream' => false,
            ]);

            return $response->choices[0]->message->content ?? 'Processed your request, but I am having trouble speaking.';
        } catch (\Exception $e) {
            \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('error', "OpenAI Provider Error: " . $e->getMessage());
            throw $e; // Rethrow to let the service handle the fallback
        }
    }
}

<?php

namespace Anwar\AgentOrchestrator\Providers;

use Anwar\AgentOrchestrator\Contracts\AiProviderInterface;
use OpenAI\Laravel\Facades\OpenAI;

class OpenAiProvider implements AiProviderInterface
{
    /**
     * Generate response via OpenAI SDK.
     */
    public function generateResponse(string $systemPrompt, string $userPrompt): string
    {
        $model = config('agent.openai_model', 'gpt-4o-mini');

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        $response = OpenAI::chat()->create([
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
        ]);

        return $response->choices[0]->message->content ?? 'Processed your request, but I am having trouble speaking.';
    }
}

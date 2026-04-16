<?php

namespace Anwar\AgentOrchestrator\Providers;

use Anwar\AgentOrchestrator\Contracts\AiProviderInterface;
use Illuminate\Support\Facades\Http;

class OllamaProvider implements AiProviderInterface
{
    /**
     * Generate response via Ollama Http API.
     */
    public function generateResponse(string $systemPrompt, string $userPrompt, array $history = []): string
    {
        $baseUrl = rtrim(config('agent.ollama_base_url', 'http://localhost:11434'), '/');
        $model = config('agent.ollama_model', 'gemma2:2b');

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Merge history if available
        foreach ($history as $turn) {
            $messages[] = $turn;
        }

        $messages[] = ['role' => 'user', 'content' => $userPrompt];

        // Note: stream => false is important for n8n to avoid streaming overhead
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
        ];

        // 120 seconds timeout for larger models / complex queries
        $response = Http::timeout(120)->post("{$baseUrl}/api/chat", $payload);

        if ($response->successful()) {
            return $response->json('message.content') ?? 'Processed your request, but received an empty response.';
        }

        throw new \Exception("Ollama API Error: " . $response->body());
    }
}

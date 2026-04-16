<?php

namespace Anwar\AgentOrchestrator\Contracts;

interface AiProviderInterface
{
    /**
     * Generate a response using the given system and user prompts.
     *
     * @param string $systemPrompt
     * @param string $userPrompt
     * @param array $history
     * @return string
     */
    public function generateResponse(string $systemPrompt, string $userPrompt, array $history = []): string;
}

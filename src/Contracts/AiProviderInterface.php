<?php

namespace Anwar\AgentOrchestrator\Contracts;

interface AiProviderInterface
{
    /**
     * Generate a response using the given system and user prompts.
     *
     * @param string $systemPrompt
     * @param string $userPrompt
     * @return string
     */
    public function generateResponse(string $systemPrompt, string $userPrompt): string;
}

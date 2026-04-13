<?php

namespace Anwar\AgentOrchestrator\Services;

use OpenAI\Laravel\Facades\OpenAI;

class AiAgentService
{
    protected ContextManager $contextManager;
    protected AiSearchService $aiSearchService;
    protected VectorService $vectorService;
    protected \Anwar\AgentOrchestrator\Contracts\AiProviderInterface $aiProvider;

    public function __construct(
        ContextManager $contextManager, 
        AiSearchService $aiSearchService, 
        VectorService $vectorService,
        \Anwar\AgentOrchestrator\Contracts\AiProviderInterface $aiProvider
    ) {
        $this->contextManager = $contextManager;
        $this->aiSearchService = $aiSearchService;
        $this->vectorService = $vectorService;
        $this->aiProvider = $aiProvider;
    }

    /**
     * Process an incoming message and return the AI's response.
     *
     * @param string $phone
     * @param string $userMessage
     * @return string
     */

    public function processMessage(string $phone, string $userMessage): string
    {
        try {
            // 1. Fetch Customer Context (Existing Logic)
            $customerContext = $this->contextManager->getCustomerContext($phone);

            // 2. Pre-fetch Search Data to give AI "Initial Eyes"
            // Generate embedding once to save CPU (Ollama optimization)
            $queryVector = $this->vectorService->getEmbedding($userMessage);

            $products = $this->aiSearchService->searchWithContext('products', $userMessage, 5, $queryVector);
            $recipes = $this->aiSearchService->searchWithContext('recipes', $userMessage, 5, $queryVector);

            // 3. Prepare the System Prompt
            $basePrompt = config('agent.system_prompt', 'You are a helpful assistant for Gunma Halal Food.');

            $systemPrompt = "{$basePrompt}\n\n" .
                "User Context: {$customerContext}\n" .
                "Current Store Context (Top Matches): " . json_encode($products) . "\n" .
                "Recipe Knowledge (Top Matches): " . json_encode($recipes) . "\n\n" .
                "Role: You are 'Gunma Neighbor Chef'. You speak in a friendly, Indian Subcontinent-Japanese neighborly tone.\n" .
                "STRICT FORMATTING RULES (MANDATORY):\n" .
                "1. ALWAYS use HTML: <b> for bold, <ul>/<li> for lists, <br> for breaks.\n" .
                "2. NO MARKDOWN: Never use ** or # or backticks.\n" .
                "3. PRODUCT DISPLAY: For EVERY product you mention from the 'Current Store Context' or 'Recipe Knowledge', you MUST display its image and an 'Add to Cart' link exactly like this:\n" .
                "   <img src='{image_url}' width='100' style='border-radius:8px;'><br>\n" .
                "   <b>{product_title}</b> - Price: {price}<br>\n" .
                "   <a href='/cart/add/{product_id}'>[Add to Cart]</a>\n" .
                "4. BATCH ACTION: If you recommend more than one ingredient, you MUST add this button at the very bottom:\n" .
                "   <a href='/cart/add-multiple?ids=ID1,ID2,ID3' class='btn-batch'>🛒 Add All Ingredients to Cart</a>\n" .
                "5. Link recipes to products available in the store context naturally.";

            // 4. Generate Response via Provider Strategy
            $finalContent = $this->aiProvider->generateResponse($systemPrompt, $userMessage);

            return $this->wrapInHtmlTemplate($finalContent);

        } catch (\Exception $e) {
            \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('error', "AI Agent Error: " . $e->getMessage(), [
                'phone' => $phone,
                'trace' => $e->getTraceAsString(),
            ]);
            $errorMsg = "Salam! Ami ektu technical osubidhায় achi. Ektu por abar chesta korben ki?";
            return $this->wrapInHtmlTemplate($errorMsg);
        }
    }

    /**
     * Wrap the AI content in a branded HTML template.
     */
    protected function wrapInHtmlTemplate(string $content): string
    {
        return "<div class='ai-agent-response' style='font-family: \"Outfit\", sans-serif; max-width: 600px; line-height: 1.6; color: #333;'>
            {$content}
        </div>";
    }


}

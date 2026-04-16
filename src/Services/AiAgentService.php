<?php

namespace Anwar\AgentOrchestrator\Services;

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

    public function processMessage(string $phone, string $userMessage, string $platform = 'web'): string
    {
        try {
            // 1. Fetch Customer Context (Existing Logic)
            $customerContext = $this->contextManager->getCustomerContext($phone);

            // 2. Pre-fetch Search Data to give AI "Initial Eyes"
            // Generate embedding once to save CPU (Ollama optimization)
            $embedStart = hrtime(true);
            $queryVector = $this->vectorService->getEmbedding($userMessage);
            $embedEnd = hrtime(true);
            $embedTime = ($embedEnd - $embedStart) / 1e6; // to ms

            if (!$queryVector) {
                $fallbackMsg = "Salam! I'm having trouble understanding. Could you try a different message?";
                return in_array(strtolower($platform), ['whatsapp', 'messenger'])
                    ? $fallbackMsg
                    : $this->wrapInHtmlTemplate($fallbackMsg);
            }

            $searchStart = hrtime(true);
            $searchResults = $this->aiSearchService->searchMultiple(['products', 'recipes'], $queryVector, 5);
            $products = $searchResults['products'] ?? [];
            $recipes = $searchResults['recipes'] ?? [];
            $searchEnd = hrtime(true);
            $searchTime = ($searchEnd - $searchStart) / 1e6;

            // Log performance for debugging
            \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('debug', "Agent Performance Profile", [
                'embedding_ms' => round($embedTime, 2),
                'search_ms' => round($searchTime, 2),
                'source' => config('agent.embedding_source'),
                'provider' => config('agent.ai_provider'),
            ]);

            // 3. Prepare the System Prompt
            $basePrompt = config('agent.system_prompt', 'You are a helpful assistant for Gunma Halal Food.');

            $systemPrompt = "{$basePrompt}\n\n" .
                "User Context: {$customerContext}\n" .
                "Current Store Context (Top Matches): " . json_encode($products) . "\n" .
                "Recipe Knowledge (Top Matches): " . json_encode($recipes) . "\n\n" .
                "Role: You are 'Gunma Neighbor Chef'. You speak in a friendly, Indian Subcontinent-Japanese neighborly tone.\n";

            if (in_array(strtolower($platform), ['whatsapp', 'messenger'])) {
                $systemPrompt .= "STRICT FORMATTING RULES (MANDATORY):\n" .
                    "1. NO HTML: Never use <b>, <img>, <a>, or <br>. Use plain text and newlines.\n" .
                    "2. BOLDING: Use *bold* or **bold** for emphasis.\n" .
                    "3. PRODUCT DISPLAY: When mentioning a product, provide the title, price, and a raw Add to Cart link: https://gunmahalalfood.com/cart/add/{product_id}\n" .
                    "4. Link recipes to products naturally using raw text links.\n";
            } else {
                $systemPrompt .= "STRICT FORMATTING RULES (MANDATORY):\n" .
                    "1. ALWAYS use HTML: <b> for bold, <ul>/<li> for lists, <br> for breaks.\n" .
                    "2. NO MARKDOWN: Never use ** or # or backticks.\n" .
                    "3. PRODUCT DISPLAY: For EVERY product you mention from the 'Current Store Context' or 'Recipe Knowledge', you MUST display its image and an 'Add to Cart' link exactly like this:\n" .
                    "   <img src='{image_url}' width='100' style='border-radius:8px;'><br>\n" .
                    "   <b>{product_title}</b> - Price: {price}<br>\n" .
                    "   <a href='/cart/add/{product_id}'>[Add to Cart]</a>\n" .
                    "4. BATCH ACTION: If you recommend more than one ingredient, you MUST add this button at the very bottom:\n" .
                    "   <a href='/cart/add-multiple?ids=ID1,ID2,ID3' class='btn-batch'>🛒 Add All Ingredients to Cart</a>\n" .
                    "5. Link recipes to products available in the store context naturally.";
            }

            // 4. Generate Response via Provider Strategy
            $finalContent = $this->aiProvider->generateResponse($systemPrompt, $userMessage);

            if (in_array(strtolower($platform), ['whatsapp', 'messenger'])) {
                return $finalContent;
            }

            return $this->wrapInHtmlTemplate($finalContent);

        } catch (\Exception $e) {
            \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('error', "AI Agent Error: " . $e->getMessage(), [
                'phone' => $phone,
                'trace' => $e->getTraceAsString(),
            ]);
            $errorMsg = "Salam! Ami ektu technical osubidhায় achi. Ektu por abar chesta korben ki?";
            if (in_array(strtolower($platform), ['whatsapp', 'messenger'])) {
                return $errorMsg;
            }
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

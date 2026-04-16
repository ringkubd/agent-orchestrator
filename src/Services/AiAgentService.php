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

    public function processMessage(string $phone, string $userMessage, string $platform = 'web', ?string $sessionId = null): string
    {
        // 0. Set safety limit (e.g. 30s) to avoid 504 hangs
        $maxTime = config('agent.max_response_time', 30);
        set_time_limit($maxTime + 5); 
        $startTime = hrtime(true);

        \Illuminate\Support\Facades\Log::info("[AI Agent] Incoming Message", [
            'phone' => $phone, 
            'platform' => $platform, 
            'session' => $sessionId,
            'message' => $userMessage
        ]);
        
        try {
            // 1. Check Fast-Track Cache (L0)
            $identity = $sessionId ?? $phone;
            $fastCacheKey = "agent_fast_" . md5($userMessage . $platform . $identity);
            $cachedResponse = \Illuminate\Support\Facades\Cache::get($fastCacheKey);
            if ($cachedResponse) {
                \Illuminate\Support\Facades\Log::info("[AI Agent] Fast-Track Cache Hit");
                return $cachedResponse;
            }

            // 2. Fetch Customer Context (Always uses phone)
            $customerContext = $this->contextManager->getCustomerContext($phone);

            // 3. Simple Query Analysis
            $isSimple = strlen($userMessage) < 6 || preg_match('/^(hi|hello|hey|salam|bye|thanks|thank you)(\s*!*)?$/i', trim($userMessage));
            $searchLimit = $isSimple ? 2 : 5; 

            // 4. Pre-fetch Search Data
            $embedStart = hrtime(true);
            $queryVector = $this->vectorService->getEmbedding($userMessage);
            $embedEnd = hrtime(true);
            $embedTime = ($embedEnd - $embedStart) / 1e6;

            if (!$queryVector) {
                $fallbackMsg = "Salam! I'm having trouble understanding. Could you try a different message?";
                return in_array(strtolower($platform), ['whatsapp', 'messenger'])
                    ? $fallbackMsg
                    : $this->wrapInHtmlTemplate($fallbackMsg);
            }

            $searchStart = hrtime(true);
            $searchResults = $this->aiSearchService->searchMultiple(['products', 'recipes'], $queryVector, $searchLimit);
            $products = $searchResults['products'] ?? [];
            $recipes = $searchResults['recipes'] ?? [];
            $searchEnd = hrtime(true);
            $searchTime = ($searchEnd - $searchStart) / 1e6;

            \Illuminate\Support\Facades\Log::info("[AI Agent] Search Results", [
                'products_count' => count($products),
                'recipes_count' => count($recipes),
                'search_time_ms' => round($searchTime, 2)
            ]);

            // 5. Build Prompt
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
                    "3. PRODUCT DISPLAY: Only mention products from 'Current Store Context'. Provide title, price, and raw Add to Cart link: https://gunmahalalfood.com/cart/add/{product_id}\n" .
                    "4. HALLUCINATION GUARD: If NO products are found in the 'Current Store Context', do NOT suggest buying anything or show placeholder links. Just give the advice/recipe.\n";
            } else {
                $systemPrompt .= "STRICT FORMATTING RULES (MANDATORY):\n" .
                    "1. ALWAYS use HTML: <b> for bold, <ul>/<li> for lists, <br> for breaks.\n" .
                    "2. NO MARKDOWN: Never use ** or # or backticks.\n" .
                    "3. PRODUCT DISPLAY: For EVERY product you mention from the 'Current Store Context', you MUST display its image and an 'Add to Cart' link exactly like this:\n" .
                    "   <img src='{image_url}' width='100' style='border-radius:8px;'><br>\n" .
                    "   <b>{product_title}</b> - Price: {price}<br>\n" .
                    "   <a href='/cart/add/{product_id}'>[Add to Cart]</a>\n" .
                    "4. BATCH ACTION: If you recommend more than one ingredient from the context, you MUST add this button at the very bottom:\n" .
                    "   <a href='/cart/add-multiple?ids=ID1,ID2,ID3' class='btn-batch'>🛒 Add All Ingredients to Cart</a> (Replace IDs with actual numeric IDs from context!)\n" .
                    "5. HALLUCINATION GUARD: NEVER mention a product that is not in the 'Current Store Context'. NEVER use placeholder IDs like 'ID1'. If no relevant products are found, skip the product display and batch button entirely.";
            }

            // 6. Generate Response
            $aiStart = hrtime(true);
            
            // Fetch Conversation History (L1 Memory) - Isolated by Session
            $historyKey = "convo_history_" . ($sessionId ?? $phone);
            $history = \Illuminate\Support\Facades\Cache::get($historyKey, []);
            
            $finalContent = $this->aiProvider->generateResponse($systemPrompt, $userMessage, $history);
            $aiEnd = hrtime(true);
            $aiTime = ($aiEnd - $aiStart) / 1e6;

            // Update Conversation History
            $history[] = ['role' => 'user', 'content' => $userMessage];
            $history[] = ['role' => 'assistant', 'content' => $finalContent];
            
            // Limit history size
            $limit = config('agent.history_limit', 10);
            $history = array_slice($history, -$limit);
            
            \Illuminate\Support\Facades\Cache::put($historyKey, $history, config('agent.history_ttl', 3600));

            // Store in Persistent Database (Async)
            \Anwar\AgentOrchestrator\Jobs\StoreConversationTurn::dispatch(
                $phone,
                $userMessage,
                $finalContent,
                $platform,
                $sessionId,
                [
                    'search_ms' => round($searchTime, 2),
                    'ai_ms' => round($aiTime, 2),
                    'total_ms' => round((hrtime(true) - $startTime) / 1e6, 2),
                ]
            );

            // Total time profile
            \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('debug', "Agent Performance Profile", [
                'embedding_ms' => round($embedTime, 2),
                'search_ms' => round($searchTime, 2),
                'ai_ms' => round($aiTime, 2),
                'total_ms' => round((hrtime(true) - $startTime) / 1e6, 2),
                'cache' => 'miss',
            ]);

            $finalResponse = in_array(strtolower($platform), ['whatsapp', 'messenger']) 
                ? $finalContent 
                : $this->wrapInHtmlTemplate($finalContent);

            // 7. Store in Fast Cache for 5 minutes (L0)
            \Illuminate\Support\Facades\Cache::put($fastCacheKey, $finalResponse, 300);

            return $finalResponse;

        } catch (\Exception $e) {
            \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('error', "AI Agent Error: " . $e->getMessage(), [
                'phone' => $phone,
                'trace' => $e->getTraceAsString(),
            ]);
            $errorMsg = "Salam! Ami ektu technical osubidhায় achi. Ektu por abar chesta korben ki?";
            return in_array(strtolower($platform), ['whatsapp', 'messenger'])
                ? $errorMsg
                : $this->wrapInHtmlTemplate($errorMsg);
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

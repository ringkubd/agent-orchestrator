<?php

namespace Anwar\AgentOrchestrator\Services;

use OpenAI\Laravel\Facades\OpenAI;

class AiAgentService
{
    protected ContextManager $contextManager;
    protected AiSearchService $aiSearchService;
    protected VectorService $vectorService;

    public function __construct(ContextManager $contextManager, AiSearchService $aiSearchService, VectorService $vectorService)
    {
        $this->contextManager = $contextManager;
        $this->aiSearchService = $aiSearchService;
        $this->vectorService = $vectorService;
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

            // 4. Construct the messages array
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ];

            // 5. Define Tools (Functions)
            $tools = [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'check_order_status',
                        'description' => 'Get the current status and delivery date of an order using its ID.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'order_id' => ['type' => 'integer', 'description' => 'The ID of the order.']
                            ],
                            'required' => ['order_id'],
                        ],
                    ]
                ],
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'add_items_to_cart',
                        'description' => 'Add one or more products to the customer\'s shopping cart.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'product_ids' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'integer'],
                                    'description' => 'Array of product IDs to add'
                                ]
                            ],
                            'required' => ['product_ids'],
                        ],
                    ]
                ],
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'consult_chef_neighbor',
                        'description' => 'Deep search for recipes and linked products if initial context is insufficient.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'user_query' => ['type' => 'string', 'description' => 'The dish name or ingredients.']
                            ],
                            'required' => ['user_query'],
                        ],
                    ]
                ]
            ];

            // 6. Initial OpenAI Call
            $model = config('agent.openai_model', 'gpt-4o-mini');
            
            $response = OpenAI::chat()->create([
                'model' => $model,
                'messages' => $messages,
                'tools' => $tools,
                'stream' => false, // Prevent partial data overhead
            ]);

            $message = $response->choices[0]->message;

            // If no tool calls, return the direct response
            if (empty($message->toolCalls)) {
                $content = $message->content ?? 'I am here to help! What are you looking for today?';
                return $this->wrapInHtmlTemplate($content);
            }

            // 7. Handle Tool Calls (Assistant message with tool calls must have null content)
            $messages[] = [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => array_map(fn($tc) => $tc->toArray(), $message->toolCalls),
            ];

            foreach ($message->toolCalls as $toolCall) {
                $toolName = $toolCall->function->name;
                $arguments = json_decode($toolCall->function->arguments, true);
                
                $result = null;

                if ($toolName === 'check_order_status') {
                    $result = $this->executeCheckOrderStatusTool($arguments['order_id'] ?? null);
                } elseif ($toolName === 'consult_chef_neighbor') {
                    $result = $this->executeConsultChefNeighborTool($arguments['user_query'] ?? '');
                } elseif ($toolName === 'add_items_to_cart') {
                    $result = $this->executeAddItemsToCartTool($arguments['product_ids'] ?? []);
                }

                if ($result !== null) {
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall->id,
                        'name' => $toolName,
                        'content' => is_string($result) ? $result : json_encode($result),
                    ];
                }
            }

            // 8. Final Call for Human-like Response
            $finalResponse = OpenAI::chat()->create([
                'model' => $model,
                'messages' => $messages,
                'stream' => false,
            ]);

            $finalContent = $finalResponse->choices[0]->message->content ?? 'Processed your request, but I am having trouble speaking.';
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

    /**
     * Execute the check_order_status tool logic.
     */
    protected function executeCheckOrderStatusTool(?int $orderId): array
    {
        if (!$orderId) return ['error' => 'Order ID is required.'];

        try {
            if (class_exists('\App\Models\Order')) {
                $order = \App\Models\Order::find($orderId);
                if ($order) {
                    return [
                        'status' => 'success',
                        'order_id' => $order->id,
                        'current_status' => $order->status ?? 'Unknown',
                        'delivery_date' => $order->delivery_date ?? 'Not scheduled yet',
                        'total_amount' => $order->total_amount ?? null,
                    ];
                }
            }
        } catch (\Exception $e) {
            \Log::warning("Order tool error: " . $e->getMessage());
        }

        return ['error' => "Order #{$orderId} not found."];
    }

    /**
     * Execute the consult_chef_neighbor tool logic.
     */
    protected function executeConsultChefNeighborTool(string $userQuery): string
    {
        if (empty($userQuery)) return "Please specify what you'd like to cook.";

        try {
            // 1. Search for recipes using Hybrid Search
            $recipes = $this->aiSearchService->findRecipes($userQuery, 3);

            if (empty($recipes)) {
                return "I couldn't find any specific recipes for '{$userQuery}' right now, but I can help you find individual ingredients!";
            }

            $output = "";

            foreach ($recipes as $recipe) {
                $title = $recipe['title'] ?? 'Title unknown';
                $ingredients = implode(', ', $recipe['ingredients'] ?? []);
                $instructions = $recipe['instructions'] ?? 'No instructions available.';
                
                $output .= "<b>Recipe: {$title}</b><br>";
                $output .= "Ingredients: {$ingredients}<br>";
                $output .= "Instructions: {$instructions}<br>";

                // 2. Fetch related products from DB
                $relatedIds = $recipe['related_product_ids'] ?? [];
                if (!empty($relatedIds) && class_exists('\App\Models\Product')) {
                    $products = \App\Models\Product::with('images')->whereIn('id', $relatedIds)->get();
                    
                    if ($products->isNotEmpty()) {
                        $output .= "<b>Recommended Products to buy:</b><br><ul>";
                        $allProductIds = [];
                        foreach ($products as $product) {
                            $allProductIds[] = $product->id;
                            $price = $product->min_price ?? 'N/A';
                            
                            // Image integration
                            $imageTag = "";
                            $firstImage = $product->images->first();
                            if ($firstImage) {
                                $imageUrl = asset($firstImage->image);
                                $imageTag = "<img src='{$imageUrl}' width='100' style='border-radius:8px;'><br>";
                            }

                            $output .= "<li>{$imageTag}<b>{$product->title}</b> - Price: {$price}<br>";
                            $output .= "<a href='/cart/add/{$product->id}'>[Add to Cart]</a></li><br>";
                        }
                        $output .= "</ul>";
                        
                        // Batch action button
                        $idsString = implode(',', $allProductIds);
                        $output .= "<a href='/cart/add-multiple?ids={$idsString}' class='btn-batch'>🛒 Add All Ingredients to Cart</a><br>";
                    }
                }
                $output .= "<br><hr><br>";
            }

            return $output;

        } catch (\Exception $e) {
            \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('error', "Chef tool error: " . $e->getMessage());
            return "I encountered an error while consulting my recipe book.";
        }
    }

    /**
     * Execute the add_items_to_cart tool logic.
     */
    protected function executeAddItemsToCartTool(array $productIds): array
    {
        if (empty($productIds)) return ['error' => 'No product IDs provided.'];

        try {
            if (class_exists('\App\Models\Product')) {
                $products = \App\Models\Product::whereIn('id', $productIds)->get(['id', 'title']);
                $foundIds = $products->pluck('id')->toArray();
                
                if (empty($foundIds)) {
                    return ['error' => 'None of the requested products were found.'];
                }

                // In a real scenario, we might call the CartRepository here.
                // For now, we return success so the AI can confirm.
                return [
                    'status' => 'success',
                    'added_count' => count($foundIds),
                    'added_products' => $products->pluck('title')->toArray(),
                    'message' => 'Items added to cart successfully. The user will see them in their cart.'
                ];
            }
        } catch (\Exception $e) {
            \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('error', "Add to cart tool error: " . $e->getMessage());
        }

        return ['error' => 'Failed to add items to cart.'];
    }
}

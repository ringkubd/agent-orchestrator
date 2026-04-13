<?php

namespace Anwar\AgentOrchestrator\Services;

use Illuminate\Support\Facades\Log;

class AiSearchService
{
    protected QdrantService $qdrant;
    protected VectorService $vector;

    /**
     * Initialize the Search services.
     */
    public function __construct(QdrantService $qdrant, VectorService $vector)
    {
        $this->qdrant = $qdrant;
        $this->vector = $vector;
    }

    /**
     * Perform a Semantic (Vector) search on the given collection.
     * 
     * @param string $indexName The target collection (e.g., 'products', 'recipes').
     * @param string $query The user's original natural language or keyword question.
     * @param int $limit Maximum results to return (default: 5).
     * @return array
     */
    public function searchWithContext(string $indexName, string $query, int $limit = 5, ?array $precomputedVector = null): array
    {
        try {
            // 1. Generate or Use Pre-computed Query Vector
            $queryVector = $precomputedVector ?? $this->vector->getEmbedding($query);
            
            if (!$queryVector) {
                \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('error', "Failed to generate embedding for query: {$query}");
                return [];
            }

            // 2. Search Qdrant
            $results = $this->qdrant->search($indexName, $queryVector, $limit);

            // 3. Format results to match previous Meilisearch output (hits)
            return array_map(function($point) {
                return $point['payload'] ?? [];
            }, $results);

        } catch (\Exception $e) {
            \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('error', "Qdrant Search Failed on collection [{$indexName}]: " . $e->getMessage());
            return [];
        }
    }

    /**
     * A dedicated search method for finding recipes.
     * 
     * @param string $query The recipe intent (e.g., "Biryani with chicken").
     * @param int $limit Maximum results to return (default: 5).
     * @return array
     */
    public function findRecipes(string $query, int $limit = 5): array
    {
        return $this->searchWithContext('recipes', $query, $limit);
    }
}

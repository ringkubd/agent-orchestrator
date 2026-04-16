<?php

namespace Anwar\AgentOrchestrator\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

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
            return $this->formatResults($results);

        } catch (\Exception $e) {
            \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('error', "Qdrant Search Failed on collection [{$indexName}]: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Search multiple collections in parallel.
     */
    public function searchMultiple(array $collections, array $queryVector, int $limit = 5): array
    {
        try {
            $host = $this->qdrant->getHost();
            $timeout = $this->qdrant->getTimeout();

            $responses = Http::pool(fn ($pool) => array_map(
                fn ($col) => $pool->as($col)->timeout($timeout)->post("{$host}/collections/{$col}/points/search", [
                    'vector' => $queryVector,
                    'limit' => $limit,
                    'with_payload' => true,
                ]),
                $collections
            ));

            $finalResults = [];
            foreach ($collections as $col) {
                $resp = $responses[$col] ?? null;
                if ($resp instanceof Response && $resp->successful()) {
                    $data = $resp->json();
                    $finalResults[$col] = $this->formatResults($data['result'] ?? []);
                } else {
                    $finalResults[$col] = [];
                    \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('error', "Parallel Qdrant Search Failed [{$col}]");
                }
            }

            return $finalResults;
        } catch (\Exception $e) {
            \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('error', "Parallel Search Error: " . $e->getMessage());
            // Return empty arrays for all collections so the agent can still respond
            return array_fill_keys($collections, []);
        }
    }

    /**
     * Helper to format Qdrant results.
     */
    protected function formatResults(array $results): array
    {
        return array_map(function($point) {
            return $point['payload'] ?? [];
        }, $results);
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

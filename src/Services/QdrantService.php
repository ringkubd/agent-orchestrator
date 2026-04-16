<?php

namespace Anwar\AgentOrchestrator\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QdrantService
{
    protected string $host;
    protected int $timeout;

    public function __construct()
    {
        $this->host = config('agent.qdrant_host', 'http://localhost:6333');
        // Cap timeout at 10s
        $this->timeout = min((int) config('agent.qdrant_timeout', 5), 10);
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * Ensure a collection exists with correct vector size.
     */
    public function ensureCollection(string $name, int $dimensions): bool
    {
        try {
            $response = Http::timeout($this->timeout)->get($this->host . "/collections/{$name}");
            if ($response->successful()) {
                return true;
            }
        } catch (\Exception $e) {
            // Collection likely doesn't exist
        }

        try {
            Http::timeout($this->timeout)->put($this->host . "/collections/{$name}", [
                'vectors' => [
                    'size' => $dimensions,
                    'distance' => 'Cosine',
                ],
            ]);
            return true;
        } catch (\Exception $e) {
            \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('error', "Qdrant Collection Creation Failed [{$name}]: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Upsert points into a collection.
     */
    public function upsertPoints(string $collection, array $points): bool
    {
        if (empty($points)) {
            return true;
        }

        try {
            Http::timeout($this->timeout)->put($this->host . "/collections/{$collection}/points", [
                'points' => $points,
            ]);
            return true;
        } catch (\Exception $e) {
            \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('error', "Qdrant Upsert Failed [{$collection}]: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Search for similar points.
     */
    public function search(string $collection, array $vector, int $limit = 5): array
    {
        try {
            $response = Http::timeout($this->timeout)->post($this->host . "/collections/{$collection}/points/search", [
                'vector' => $vector,
                'limit' => $limit,
                'with_payload' => true,
            ]);

            $data = $response->json();
            return $data['result'] ?? [];
        } catch (\Exception $e) {
            \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('error', "Qdrant Search Failed [{$collection}]: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Delete a collection.
     */
    public function deleteCollection(string $name): bool
    {
        try {
            Http::timeout($this->timeout)->delete($this->host . "/collections/{$name}");
            return true;
        } catch (\Exception $e) {
            \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('error', "Qdrant Collection Deletion Failed [{$name}]: " . $e->getMessage());
            return false;
        }
    }
}

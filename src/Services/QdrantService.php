<?php

namespace Anwar\AgentOrchestrator\Services;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;

class QdrantService
{
    protected GuzzleClient $client;
    protected string $host;
    protected int $timeout;

    public function __construct()
    {
        $this->host = config('agent.qdrant_host', 'http://localhost:6333');
        // Cap timeout at 10s
        $this->timeout = min((int) config('agent.qdrant_timeout', 5), 10);
        $this->client = new GuzzleClient([
            'timeout' => $this->timeout,
            'connect_timeout' => 2,
        ]);
    }

    /**
     * Ensure a collection exists with correct vector size.
     */
    public function ensureCollection(string $name, int $dimensions): bool
    {
        try {
            $response = $this->client->get($this->host . "/collections/{$name}");
            if ($response->getStatusCode() === 200) {
                return true;
            }
        } catch (\Exception $e) {
            // Collection likely doesn't exist
        }

        try {
            $this->client->put($this->host . "/collections/{$name}", [
                'json' => [
                    'vectors' => [
                        'size' => $dimensions,
                        'distance' => 'Cosine',
                    ],
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
            $this->client->put($this->host . "/collections/{$collection}/points", [
                'json' => ['points' => $points],
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
            $response = $this->client->post($this->host . "/collections/{$collection}/points/search", [
                'json' => [
                    'vector' => $vector,
                    'limit' => $limit,
                    'with_payload' => true,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
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
            $this->client->delete($this->host . "/collections/{$name}");
            return true;
        } catch (\Exception $e) {
            \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('error', "Qdrant Collection Deletion Failed [{$name}]: " . $e->getMessage());
            return false;
        }
    }
}

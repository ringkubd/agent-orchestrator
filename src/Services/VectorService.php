<?php

namespace Anwar\AgentOrchestrator\Services;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;

class VectorService
{
    protected GuzzleClient $client;
    protected string $model;
    protected string $source;
    protected int $timeout;
    protected static array $localCache = [];

    public function __construct()
    {
        $this->source = config('agent.embedding_source', 'ollama'); // 'ollama' or 'openai'
        // Set maximum 10s timeout
        $this->timeout = min((int) config('agent.embedding_timeout', 15), 10);
        $this->model = $this->source === 'openai' 
            ? config('agent.openai_embedding_model', 'text-embedding-3-small')
            : config('agent.ollama_embedding_model', 'nomic-embed-text');

        $this->client = new GuzzleClient([
            'timeout' => $this->timeout,
            'connect_timeout' => 3,
        ]);
    }

    /**
     * Get the embedding for a given text.
     */
    public function getEmbedding(string $text): ?array
    {
        $cacheKey = "vector_" . md5($text . $this->source . $this->model);
        
        // 1. Check Request-Local Cache (L1)
        if (isset(self::$localCache[$cacheKey])) {
            return self::$localCache[$cacheKey];
        }

        // 2. Check Persistent Cache (L2 - Redis/File/etc)
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached) {
            self::$localCache[$cacheKey] = $cached;
            return $cached;
        }

        if ($this->source === 'openai') {
            $embedding = $this->getOpenAiEmbedding($text);
        } else {
            $embedding = $this->getOllamaEmbedding($text);
        }

        if ($embedding) {
            // 3. Store in both caches (L1 and L2)
            // Store in L2 for 7 days
            self::$localCache[$cacheKey] = $embedding;
            \Illuminate\Support\Facades\Cache::put($cacheKey, $embedding, now()->addDays(7));
        }

        return $embedding;
    }

    /**
     * Get embedding via OpenAI API.
     */
    protected function getOpenAiEmbedding(string $text): ?array
    {
        try {
            $response = \OpenAI\Laravel\Facades\OpenAI::embeddings()->create([
                'model' => $this->model,
                'input' => $text,
            ]);

            return $response->embeddings[0]->embedding ?? null;
        } catch (\Exception $e) {
            \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('error', "OpenAI Embedding Failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get embedding via local Ollama API.
     */
    protected function getOllamaEmbedding(string $text): ?array
    {
        $host = config('agent.ollama_host');
        
        // Final safety check to avoid 0.0.0.0 or empty strings
        if (empty($host) || $host === '0.0.0.0') {
            $host = 'http://localhost:11434';
        }

        $endpoint = rtrim($host, '/') . '/api/embeddings';
        
        try {
            $response = $this->client->post($endpoint, [
                'json' => [
                    'model' => $this->model,
                    'prompt' => $text,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            // Handle different Ollama versions/responses
            if (isset($data['embedding'])) {
                return $data['embedding'];
            }
            
            if (isset($data['embeddings']) && is_array($data['embeddings'])) {
                return $data['embeddings'][0] ?? null;
            }

            return null;
        } catch (\Exception $e) {
            \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('error', "Ollama Embedding Failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get the expected dimensions for the current model.
     */
    public function getDimensions(): int
    {
        if ($this->source === 'openai') {
            return 1536; // Default for text-embedding-3-small
        }

        return 768; // Default for nomic-embed-text
    }
}

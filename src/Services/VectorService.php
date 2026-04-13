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
    protected static array $cache = [];

    public function __construct()
    {
        $this->source = config('agent.embedding_source', 'ollama'); // 'ollama' or 'openai'
        $this->timeout = (int) config('agent.embedding_timeout', 15);
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
     * 
     * @param string $text
     * @return array|null
     */
    public function getEmbedding(string $text): ?array
    {
        $cacheKey = md5($text . $this->source . $this->model);
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        if ($this->source === 'openai') {
            $embedding = $this->getOpenAiEmbedding($text);
        } else {
            $embedding = $this->getOllamaEmbedding($text);
        }

        if ($embedding) {
            self::$cache[$cacheKey] = $embedding;
        }

        return $embedding;
    }

    /**
     * Get embedding via OpenAI API.
     */
    protected function getOpenAiEmbedding(string $text): ?array
    {
        $apiKey = config('openai.api_key');
        
        try {
            $response = $this->client->post('https://api.openai.com/v1/embeddings', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'input' => $text,
                    'model' => $this->model,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['data'][0]['embedding'] ?? null;
        } catch (\Exception $e) {
            Log::error("OpenAI Embedding Failed: " . $e->getMessage());
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
            $host = 'http://localhost:11435';
        }

        $endpoint = rtrim($host, '/') . '/api/embeddings';
        
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
            Log::error("Ollama Embedding Failed: " . $e->getMessage());
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

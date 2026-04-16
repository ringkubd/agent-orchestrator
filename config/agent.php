<?php

return [
    'webhook_secret' => env('AGENT_WEBHOOK_SECRET', 'secret'),
    'ai_provider' => env('AI_PROVIDER', 'openai'),
    'openai_model' => env('AGENT_OPENAI_MODEL', 'gpt-4o-mini'),
    'ollama_base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
    'ollama_model' => env('OLLAMA_MODEL', 'gemma4:e4b'),
    'system_prompt' => env('AGENT_SYSTEM_PROMPT', 'You are a helpful assistant for Gunma Halal Food.'),
    'meilisearch_host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
    'meilisearch_key' => env('MEILISEARCH_KEY', 'dxjk6Kq39DRTiVLn'),

    'qdrant_host' => env('QDRANT_HOST', 'http://localhost:6333'),
    'qdrant_timeout' => env('QDRANT_TIMEOUT', 5),
    'embedding_source' => env('AGENT_EMBEDDING_SOURCE', 'ollama'), // ollama or openai
    'embedding_timeout' => env('AGENT_EMBEDDING_TIMEOUT', 10),
    'ollama_host' => env('OLLAMA_HOST', 'http://127.0.0.1:11434'),
    'ollama_embedding_model' => env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'),
    'openai_embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
];

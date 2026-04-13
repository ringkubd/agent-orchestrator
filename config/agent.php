<?php

return [
    'webhook_secret' => env('AGENT_WEBHOOK_SECRET', 'secret'),
    'openai_model' => env('AGENT_OPENAI_MODEL', 'gpt-4o-mini'),
    'system_prompt' => env('AGENT_SYSTEM_PROMPT', 'You are a helpful assistant for Gunma Halal Food.'),
    'meilisearch_host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
    'meilisearch_key' => env('MEILISEARCH_KEY', 'dxjk6Kq39DRTiVLn'),

    'qdrant_host' => env('QDRANT_HOST', 'http://localhost:6333'),
    'embedding_source' => env('AGENT_EMBEDDING_SOURCE', 'ollama'), // ollama or openai
    'ollama_host' => env('OLLAMA_HOST', 'http://127.0.0.1:11435'),
    'ollama_embedding_model' => env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'),
    'openai_embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
];

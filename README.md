# AI Agent Orchestrator for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/anwar/agent-orchestrator.svg?style=flat-square)](https://packagist.org/packages/anwar/agent-orchestrator)
[![Total Downloads](https://img.shields.io/packagist/dt/anwar/agent-orchestrator.svg?style=flat-square)](https://packagist.org/packages/anwar/agent-orchestrator)

A powerful AI-driven core for Laravel applications that orchestrates OpenAI tool calling, local vector embeddings via Ollama, and semantic search via Qdrant. Specifically designed to handle complex "Neighbor Chef" personas with HTML-rich responses and direct cart integration.

---

## 🎨 Features

- **🚀 Hybrid Semantic Search**: Integrated with **Qdrant** for high-performance vector search.
- **🧠 Local Embeddings**: Support for local **Ollama** (`nomic-embed-text`) to save costs and ensure privacy.
- **🛠️ OpenAI Tool Calling**: Seamlessly handle tool calls for order tracking, recipe consultation, and cart management.
- **🍱 Branded HTML Responses**: Enforces strict HTML formatting (no Markdown) for premium UI rendering.
- **🛒 Cart Integration**: Automatic generation of `[Add to Cart]` links and batch ingredient buttons.
- **🔌 Webhook Ready**: Built-in endpoint for n8n or other automation platform orchestration.

---

## 📦 Installation

You can install the package via composer:

```bash
composer require anwar/agent-orchestrator
```

The service provider will automatically register itself.

### Publish Configuration
```bash
php artisan vendor:publish --tag="agent-config"
```

---

## ⚙️ Configuration

Add the following variables to your `.env` file:

```env
# AI Agent Settings
AGENT_WEBHOOK_SECRET=your_secure_secret
AGENT_OPENAI_MODEL=gpt-4o-mini
OPENAI_REQUEST_TIMEOUT=120

# Vector Database (Qdrant)
QDRANT_HOST=http://localhost:6333

# Embedding Source (ollama or openai)
AGENT_EMBEDDING_SOURCE=ollama
OLLAMA_HOST=http://localhost:11435
OLLAMA_EMBEDDING_MODEL=nomic-embed-text

# OpenAI (if source is openai)
OPENAI_API_KEY=your_openai_key
```

---

## 🚀 Usage

### 1. Synchronize Data to Qdrant
To populate the vector database with your products and recipes:

```bash
# Sync Recipes
php artisan agent:sync-chef-brain

# Sync Active Products
php artisan agent:sync-products
```

### 2. Using the Service
You can resolve and use the `AiAgentService` anywhere in your application:

```php
use Anwar\AgentOrchestrator\Services\AiAgentService;

$agent = app(AiAgentService::class);
$response = $agent->processMessage($customerPhone, "I want to cook Chicken Biryani");

// $response will contain HTML-formatted content with images and cart links.
```

### 3. Webhook Integration
The package provides a standard webhook endpoint:
**Endpoint**: `POST /api/v1/agent/webhook`

Payload Example:
```json
{
    "phone": "08012345678",
    "message": "Find me some Miso paste"
}
```

---

## 👨‍🍳 Persona: Gunma Neighbor Chef
The agent is pre-configured to behave as a **Friendly, Bengali-Japanese neighbor**. 
- **Tone**: Uses phrases like *"Itadakimasu Bhai"*, *"Ja-matod"*, and *"Salam!"*.
- **Formatting**: Strictly uses `<b>`, `<ul>`, `<li>`, and `<br>`.
- **E-commerce**: Integrates product images and cart buttons directly into the chat flow.

---

## 🏗️ Dependencies
- [OpenAI PHP SDK](https://github.com/openai-php/laravel)
- [Qdrant](https://qdrant.tech/)
- [Ollama](https://ollama.com/) (Optional for local embeddings)

---

## 🔗 Repository
[https://github.com/ringkubd/agent-orchestrator](https://github.com/ringkubd/agent-orchestrator)

## 📄 License
The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

---
*Developed with ❤️ for Gunma Halal Food.*

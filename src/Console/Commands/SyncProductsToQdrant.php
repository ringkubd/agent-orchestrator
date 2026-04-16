<?php

namespace Anwar\AgentOrchestrator\Console\Commands;

use Illuminate\Console\Command;
use Anwar\AgentOrchestrator\Services\QdrantService;
use Anwar\AgentOrchestrator\Services\VectorService;

class SyncProductsToQdrant extends Command
{
    protected $signature = 'agent:sync-products-qdrant';
    protected $description = 'Sync active products to Qdrant with embeddings.';

    public function handle(QdrantService $qdrantService, VectorService $vectorService)
    {
        $this->info('Starting Product Synchronization to Qdrant...');

        $collectionName = 'products';
        $dimensions = $vectorService->getDimensions();

        // 1. Dimension Guard: Check existing collection info
        $host = $qdrantService->getHost();
        $response = \Illuminate\Support\Facades\Http::get("{$host}/collections/{$collectionName}");
        if ($response->successful()) {
            $currentDim = $response->json('result.config.params.vectors.size');
            if ($currentDim && $currentDim != $dimensions) {
                $this->warn("Dimension mismatch: Qdrant ({$currentDim}) != Config ({$dimensions}). Recreating collection...");
                $qdrantService->deleteCollection($collectionName);
            }
        }

        if (!$qdrantService->ensureCollection($collectionName, $dimensions)) {
            $this->error("Failed to ensure collection: {$collectionName}");
            return Command::FAILURE;
        }

        if (!class_exists('\App\Models\Product')) {
            $this->error("Product model not found.");
            return Command::FAILURE;
        }

        $products = \App\Models\Product::where('status', 'Active')
            ->where('is_online_available', 'Yes')
            ->with('categories')
            ->get();

        $this->info("Found " . $products->count() . " products to process.");

        $points = [];
        foreach ($products as $product) {
            $this->info("Processing: {$product->title}");

            $textToEmbed = "Product: {$product->title}. Description: {$product->short_description}";
            $vector = $vectorService->getEmbedding($textToEmbed);

            if (!$vector) {
                $this->warn("Failed to embed: {$product->title}");
                continue;
            }

            $points[] = [
                'id' => $product->id,
                'vector' => $vector,
                'payload' => [
                    'id' => $product->id,
                    'title' => $product->title,
                    'slug' => $product->slug,
                    'short_description' => $product->short_description,
                    'price' => $product->latestStock?->online_price,
                    // Use a placeholder for image if not found
                    'image' => $product->images->first()->image ?? 'https://via.placeholder.com/150',
                ]
            ];

            // Batch upsert every 50 points to save memory
            if (count($points) >= 50) {
                $qdrantService->upsertPoints($collectionName, $points);
                $points = [];
                $this->info("Batched 50 products...");
            }
        }

        if (count($points) > 0) {
            $qdrantService->upsertPoints($collectionName, $points);
        }

        $this->info('Product sync to Qdrant completed!');
        return Command::SUCCESS;
    }
}

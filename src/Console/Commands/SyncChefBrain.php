<?php

namespace Anwar\AgentOrchestrator\Console\Commands;

use Illuminate\Console\Command;
use Anwar\AgentOrchestrator\Services\QdrantService;
use Anwar\AgentOrchestrator\Services\VectorService;
use Illuminate\Support\Str;

class SyncChefBrain extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agent:sync-chef-brain';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync the Chef Brain by populating Qdrant with Halal recipes and linking them to products.';

    /**
     * Execute the console command.
     */
    public function handle(QdrantService $qdrantService, VectorService $vectorService)
    {
        $this->info('Starting Chef Brain Synchronization (Qdrant)...');

        $collectionName = 'recipes';
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

        // 1. Ensure Collection
        if (!$qdrantService->ensureCollection($collectionName, $dimensions)) {
            $this->error("Failed to ensure Qdrant collection: {$collectionName}");
            return Command::FAILURE;
        }
        $this->info("Ensured collection '{$collectionName}' with {$dimensions} dimensions.");

        // 2. Fetch Products for Mapping
        $this->info('Fetching active products for mapping...');
        $products = [];
        if (class_exists('\App\Models\Product')) {
            $products = \App\Models\Product::where('status', 'active')
                ->get(['id', 'title'])
                ->map(fn($p) => ['id' => $p->id, 'title' => strtolower($p->title)])
                ->toArray();
        }
        $this->info(count($products) . ' products fetched.');

        // 3. Get Recipes
        $recipes = $this->getInitialKnowledge();
        $this->info('Processing ' . count($recipes) . ' recipes...');

        // 4. Map Products and Generate Vectors
        $points = [];
        foreach ($recipes as $index => $recipe) {
            $this->info("Processing recipe: {$recipe['title']}...");

            // Fuzzy product mapping
            $relatedProductIds = [];
            $recipeContent = $recipe['title'] . ' ' . implode(' ', $recipe['ingredients']) . ' ' . $recipe['instructions'];
            $recipeLower = strtolower($recipeContent);

            foreach ($products as $product) {
                if (str_contains($recipeLower, $product['title'])) {
                    $relatedProductIds[] = $product['id'];
                }
            }
            $recipe['related_product_ids'] = array_values(array_unique(array_merge($recipe['related_product_ids'] ?? [], $relatedProductIds)));

            // Generate Embedding
            $vector = $vectorService->getEmbedding($recipeContent);
            if (!$vector) {
                $this->warn("Failed to generate embedding for recipe: {$recipe['title']}. Skipping.");
                continue;
            }

            // Create Qdrant Point
            $points[] = [
                'id' => $index + 1, // Qdrant points need numeric or UUID IDs
                'vector' => $vector,
                'payload' => $recipe
            ];
        }

        // 5. Batch Indexing
        $this->info('Pushing ' . count($points) . ' points to Qdrant...');
        if ($qdrantService->upsertPoints($collectionName, $points)) {
            $this->info('Chef Brain Sync completed successfully!');
            return Command::SUCCESS;
        }

        $this->error('Failed to upsert points to Qdrant.');
        return Command::FAILURE;
    }

    /**
     * Initial high-quality Halal recipes (Bengali & Japanese fusion).
     */
    private function getInitialKnowledge(): array
    {
        return [
            [
                'id' => 'recipe_1',
                'title' => 'Japanese Miso Chicken Curry',
                'ingredients' => ['Halal Chicken', 'Miso Paste', 'Curry Powder', 'Carrots', 'Potatoes'],
                'instructions' => 'Combine Japanese miso with traditional curry spices. Sear chicken, add vegetables, and simmer in a rich miso-curry base.',
                'nutritional_info' => ['calories' => 450, 'protein' => '35g', 'fat' => '15g'],
                'category' => 'Japanese-Halal',
                'related_product_ids' => [],
            ],
            [
                'id' => 'recipe_2',
                'title' => 'Bengali Shorshe Ilish Sushi',
                'ingredients' => ['Hilsa Fish', 'Mustard Paste', 'Sushi Rice', 'Nori Sheets', 'Green Chilies'],
                'instructions' => 'A fusion twist! Steam mustard-marinated hilsa and use as a topping or filling for sushi rolls using seasoned rice.',
                'nutritional_info' => ['calories' => 380, 'protein' => '25g', 'fat' => '20g'],
                'category' => 'Fusion',
                'related_product_ids' => [],
            ],
            [
                'id' => 'recipe_3',
                'title' => 'Chicken Teriyaki Biryani',
                'ingredients' => ['Basmati Rice', 'Chicken', 'Teriyaki Sauce', 'Ginger', 'Saffron'],
                'instructions' => 'Marinate chicken in teriyaki sauce and ginger. Layer with saffron-infused basmati rice for a sweet-savory biryani experience.',
                'nutritional_info' => ['calories' => 520, 'protein' => '30g', 'fat' => '12g'],
                'category' => 'Fusion',
                'related_product_ids' => [],
            ],
            [
                'id' => 'recipe_4',
                'title' => 'Beef Ramen Korma',
                'ingredients' => ['Ramen Noodles', 'Halal Beef', 'Korma Paste', 'Soft Boiled Egg', 'Green Onions'],
                'instructions' => 'Serve rich, creamy beef korma over perfectly cooked ramen noodles. Garnish with classic ramen toppings.',
                'nutritional_info' => ['calories' => 600, 'protein' => '40g', 'fat' => '25g'],
                'category' => 'Fusion',
                'related_product_ids' => [],
            ],
            [
                'id' => 'recipe_5',
                'title' => 'Iftar Special Benli Fruit Salad',
                'ingredients' => ['Mango', 'Papaya', 'Chickpeas', 'Chaat Masala', 'Yogurt'],
                'instructions' => 'Combine fresh tropical fruits with boiled chickpeas and a dash of chaat masala. Serve chilled with a dollop of yogurt.',
                'nutritional_info' => ['calories' => 250, 'protein' => '8g', 'fat' => '3g'],
                'category' => 'Iftar',
                'related_product_ids' => [],
            ],
            [
                'id' => 'recipe_6',
                'title' => 'Sehri Power Oatmeal',
                'ingredients' => ['Oats', 'Dates', 'Almonds', 'Honey', 'Milk'],
                'instructions' => 'Cook oats in milk. Top with chopped dates and crushed almonds. Drizzle with honey for long-lasting energy.',
                'nutritional_info' => ['calories' => 400, 'protein' => '12g', 'fat' => '10g'],
                'category' => 'Sehri',
                'related_product_ids' => [],
            ],
            [
                'id' => 'recipe_7',
                'title' => 'Salmon Misoyaki with Rice',
                'ingredients' => ['Halal Salmon', 'Miso', 'Mirin (Halal substitute)', 'Soy Sauce', 'Steamed Rice'],
                'instructions' => 'Marinate salmon in a miso-based sauce. Broil until golden and serve with warm steamed rice.',
                'nutritional_info' => ['calories' => 450, 'protein' => '30g', 'fat' => '22g'],
                'category' => 'Japanese-Halal',
                'related_product_ids' => [],
            ],
            [
                'id' => 'recipe_8',
                'title' => 'Beef Tehari with Wasabi Kick',
                'ingredients' => ['Kalizira Rice', 'Beef', 'Wasabi Paste', 'Mustard Oil', 'Spices'],
                'instructions' => 'Prepare classic beef tehari but add a small amount of wasabi paste towards the end for a unique pungent note.',
                'nutritional_info' => ['calories' => 550, 'protein' => '35g', 'fat' => '18g'],
                'category' => 'Fusion',
                'related_product_ids' => [],
            ],
            [
                'id' => 'recipe_9',
                'title' => 'Vegetable Tempura Pakora',
                'ingredients' => ['Eggplant', 'Sweet Potato', 'Besan (Gram Flour)', 'Ice Cold Water', 'Spices'],
                'instructions' => 'Use a tempura-style light batter with chickpea flour. Deep fry vegetables until extra crispy.',
                'nutritional_info' => ['calories' => 300, 'protein' => '10g', 'fat' => '15g'],
                'category' => 'Iftar',
                'related_product_ids' => [],
            ],
            [
                'id' => 'recipe_10',
                'title' => 'Matcha Lassi',
                'ingredients' => ['Yogurt', 'Matcha Powder', 'Sugar', 'Water', 'Ice'],
                'instructions' => 'Blend yogurt, matcha powder, and sugar until frothy. A refreshing fusion drink.',
                'nutritional_info' => ['calories' => 150, 'protein' => '5g', 'fat' => '4g'],
                'category' => 'Fusion',
                'related_product_ids' => [],
            ],
            [
                'id' => 'recipe_11',
                'title' => 'Japanese Style Chicken Roast',
                'ingredients' => ['Whole Chicken', 'Soy Sauce', 'Ginger', 'Garlic', 'Star Anise'],
                'instructions' => 'Roast chicken marinated in a blend of soy sauce and aromatics for a savory, umami-rich skin.',
                'nutritional_info' => ['calories' => 500, 'protein' => '45g', 'fat' => '20g'],
                'category' => 'Japanese-Halal',
                'related_product_ids' => [],
            ],
            [
                'id' => 'recipe_12',
                'title' => 'Classic Bengali Mutton Curry',
                'ingredients' => ['Mutton', 'Potatoes', 'Onions', 'Cumin', 'Turmeric'],
                'instructions' => 'Slow-cook mutton with potatoes and a traditional spice blend until the meat is succulent.',
                'nutritional_info' => ['calories' => 650, 'protein' => '40g', 'fat' => '35g'],
                'category' => 'Traditional',
                'related_product_ids' => [],
            ],
            [
                'id' => 'recipe_13',
                'title' => 'Ebi Fry with Tartar Sauce',
                'ingredients' => ['Shrimp', 'Panko Breadcrumbs', 'Flour', 'Egg', 'Mayonnaise'],
                'instructions' => 'Bread shrimp with panko and deep fry. Serve with a tangy homemade tartar sauce.',
                'nutritional_info' => ['calories' => 400, 'protein' => '20g', 'fat' => '22g'],
                'category' => 'Japanese-Halal',
                'related_product_ids' => [],
            ],
            [
                'id' => 'recipe_14',
                'title' => 'Dal Tadka with Garlic Garnish',
                'ingredients' => ['Red Lentils', 'Garlic', 'Dry Chilies', 'Cumin Seeds', 'Ghee'],
                'instructions' => 'Boil lentils until soft. Add a "tadka" (tempering) of fried garlic and spices in ghee.',
                'nutritional_info' => ['calories' => 200, 'protein' => '12g', 'fat' => '8g'],
                'category' => 'Traditional',
                'related_product_ids' => [],
            ],
            [
                'id' => 'recipe_15',
                'title' => 'Beef Gyudon Bowl',
                'ingredients' => ['Thinly Sliced Beef', 'Onions', 'Soy Sauce', 'Dashi (Halal)', 'Rice'],
                'instructions' => 'Simmer beef and onions in a savory dashi-soy broth. Serve over a bowl of rice.',
                'nutritional_info' => ['calories' => 550, 'protein' => '30g', 'fat' => '15g'],
                'category' => 'Japanese-Halal',
                'related_product_ids' => [],
            ],
            [
                'id' => 'recipe_16',
                'title' => 'Bengali Fish Fry (Japanese Style)',
                'ingredients' => ['Bhetki Fish', 'Panko', 'Mustard', 'Lemon', 'Egg'],
                'instructions' => 'Marinate fish in mustard and lemon, then coat with Japanese panko for an extra crispy texture.',
                'nutritional_info' => ['calories' => 350, 'protein' => '28g', 'fat' => '18g'],
                'category' => 'Fusion',
                'related_product_ids' => [],
            ],
            [
                'id' => 'recipe_17',
                'title' => 'Miso Soup with Paneer',
                'ingredients' => ['Miso Paste', 'Tofu (or Paneer)', 'Seaweed', 'Green Onions', 'Dashi'],
                'instructions' => 'A fusion take on miso soup using paneer cubes for extra protein and a familiar taste.',
                'nutritional_info' => ['calories' => 120, 'protein' => '8g', 'fat' => '6g'],
                'category' => 'Fusion',
                'related_product_ids' => [],
            ],
            [
                'id' => 'recipe_18',
                'title' => 'Sehri Beef Stew',
                'ingredients' => ['Beef', 'Root Vegetables', 'Onions', 'Thyme', 'Pepper'],
                'instructions' => 'Slow-cooked hearty beef stew, perfect for a filling and nutritious Sehri meal.',
                'nutritional_info' => ['calories' => 500, 'protein' => '40g', 'fat' => '20g'],
                'category' => 'Sehri',
                'related_product_ids' => [],
            ],
            [
                'id' => 'recipe_19',
                'title' => 'Iftar Date & Nut Balls',
                'ingredients' => ['Dates', 'Walnuts', 'Desiccated Coconut', 'Cardamom', 'Honey'],
                'instructions' => 'Blend dates and nuts. Shape into balls and roll in coconut for a quick energy boost.',
                'nutritional_info' => ['calories' => 150, 'protein' => '3g', 'fat' => '7g'],
                'category' => 'Iftar',
                'related_product_ids' => [],
            ],
            [
                'id' => 'recipe_20',
                'title' => 'Japanese Rice Cakes (Mochi) with Jaggery',
                'ingredients' => ['Glutinous Rice Flour', 'Jaggery', 'Water', 'Starch'],
                'instructions' => 'Make soft mochi cakes and fill or drizzle with a Bengali-style liquid jaggery (Nolen Gur).',
                'nutritional_info' => ['calories' => 200, 'protein' => '2g', 'fat' => '1g'],
                'category' => 'Fusion',
                'related_product_ids' => [],
            ],
            [
                'id' => 'recipe_21',
                'title' => 'Chicken Katsudon Biryani',
                'ingredients' => ['Chicken Cutlet (Katsu)', 'Egg', 'Biryani Rice', 'Tonkatsu Sauce', 'Onions'],
                'instructions' => 'Serve crispy chicken katsu over aromatic biryani rice, topped with a soft-cooked onion-egg mixture.',
                'nutritional_info' => ['calories' => 600, 'protein' => '35g', 'fat' => '22g'],
                'category' => 'Fusion',
                'related_product_ids' => [],
            ],
        ];
    }
}

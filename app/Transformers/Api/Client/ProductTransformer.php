<?php

namespace Pterodactyl\Transformers\Api\Client;

use Pterodactyl\Models\Product;
use Pterodactyl\Transformers\Api\Client\BaseClientTransformer;

class ProductTransformer extends BaseClientTransformer
{
    /**
     * Return the resource name for JSON:API output.
     */
    public function getResourceName(): string
    {
        return Product::RESOURCE_NAME;
    }

    /**
     * Transform a Product model into a representation that can be consumed by the
     * application's frontend.
     */
    public function transform(Product $product): array
    {
        // Ensure nests and eggs are loaded to avoid N+1 queries.
        $product->loadMissing('nests.eggs');

        // Flatten eggs from all associated nests.
        $eggs = $product->nests
            ->flatMap(function ($nest) {
                return $nest->eggs;
            })
            ->unique('id')
            ->values()
            ->map(fn($egg) => [
                'id' => $egg->id,
                'name' => $egg->name,
            ]);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'cpu' => $product->cpu,
            'memory' => $product->memory,
            'disk' => $product->disk,
            'swap' => $product->swap,
            'credits' => $product->credits,
            'max_per_user' => $product->max_per_user,
            'eggs' => $eggs,
        ];
    }
} 
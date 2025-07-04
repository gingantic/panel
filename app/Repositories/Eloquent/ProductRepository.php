<?php

namespace Pterodactyl\Repositories\Eloquent;

use Pterodactyl\Models\Product;

class ProductRepository extends EloquentRepository
{
    /**
     * Return the model backing this repository.
     */
    public function model(): string
    {
        return Product::class;
    }

    /**
     * Get all products with counts of nests and nodes.
     */
    public function getAllWithDetails()
    {
        return $this->getBuilder()->withCount('nests', 'nodes')->get($this->getColumns());
    }
} 
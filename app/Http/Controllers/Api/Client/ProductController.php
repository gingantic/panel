<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Pterodactyl\Models\Product;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Transformers\Api\Client\ProductTransformer;

class ProductController extends ClientApiController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Return a list of available products for server creation.
     */
    public function index(): array
    {
        $products = Product::where('disabled', false)
            ->orderBy('credits')
            ->get();

        return $this->fractal->collection($products)
            ->transformWith(new ProductTransformer())
            ->toArray();
    }
} 
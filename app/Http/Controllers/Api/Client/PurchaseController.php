<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Spatie\QueryBuilder\QueryBuilder;
use Pterodactyl\Models\UserProductPurchase;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Transformers\Api\Client\PurchaseTransformer;

class PurchaseController extends ClientApiController
{
    public function __invoke(ClientApiRequest $request): array
    {
        $purchases = QueryBuilder::for(UserProductPurchase::query()->where('user_id', $request->user()->id))
            ->allowedSorts(['created_at', 'next_renew_at'])
            ->defaultSort('-created_at')
            ->paginate(min($request->query('per_page', 25), 100))
            ->appends($request->query());

        return $this->fractal->collection($purchases)
            ->transformWith($this->getTransformer(PurchaseTransformer::class))
            ->toArray();
    }
} 
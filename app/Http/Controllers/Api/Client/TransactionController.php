<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Pterodactyl\Models\Transaction;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Transformers\Api\Client\TransactionTransformer;

class TransactionController extends ClientApiController
{
    public function __invoke(ClientApiRequest $request): array
    {
        $transactions = QueryBuilder::for(Transaction::query()->where('user_id', $request->user()->id))
            ->allowedFilters([
                AllowedFilter::exact('type'),
                AllowedFilter::exact('status'),
            ])
            ->allowedSorts(['created_at', 'processed_at', 'amount'])
            ->defaultSort('-created_at')
            ->paginate(min($request->query('per_page', 25), 100))
            ->appends($request->query());

        return $this->fractal->collection($transactions)
            ->transformWith($this->getTransformer(TransactionTransformer::class))
            ->toArray();
    }
} 
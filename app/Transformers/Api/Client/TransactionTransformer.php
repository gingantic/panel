<?php

namespace Pterodactyl\Transformers\Api\Client;

use Pterodactyl\Models\Transaction;

class TransactionTransformer extends BaseClientTransformer
{
    public function getResourceName(): string
    {
        return 'transaction';
    }

    public function transform(Transaction $model): array
    {
        return [
            'id' => $model->id,
            'amount' => $model->amount,
            'type' => $model->type,
            'description' => $model->description,
            'status' => $model->status,
            'balance_before' => $model->balance_before,
            'balance_after' => $model->balance_after,
            'processed_at' => optional($model->processed_at)->toIso8601String(),
            'created_at' => $model->created_at->toIso8601String(),
        ];
    }
} 
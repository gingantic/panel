<?php

namespace Pterodactyl\Transformers\Api\Client;

use Pterodactyl\Models\UserProductPurchase;

class PurchaseTransformer extends BaseClientTransformer
{
    public function getResourceName(): string
    {
        return 'purchase';
    }

    public function transform(UserProductPurchase $model): array
    {
        return [
            'id' => $model->id,
            'product_id' => $model->product_id,
            'server_id' => $model->server_id,
            'billing_cycle' => $model->billing_cycle,
            'credits_charged' => $model->credits_charged,
            'uptime_seconds' => $model->uptime_seconds,
            'next_renew_at' => optional($model->next_renew_at)->toIso8601String(),
            'status' => $model->status,
            'created_at' => $model->created_at->toIso8601String(),
        ];
    }
} 
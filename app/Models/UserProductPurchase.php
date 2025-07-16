<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Model;

class UserProductPurchase extends Model
{
    protected $table = 'user_product_purchases';

    protected $fillable = [
        'user_id',
        'product_id',
        'egg_id',
        'server_id',
        'credits_charged',
        'billing_cycle',
        'next_renew_at',
        'status',
        'transaction_id',
        'uptime_seconds',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'product_id' => 'integer',
        'egg_id' => 'integer',
        'server_id' => 'integer',
        'credits_charged' => 'integer',
        // Use immutable datetime to ensure CarbonImmutable instances for accurate, immutable date operations.
        'next_renew_at' => 'immutable_datetime',
        'uptime_seconds' => 'integer',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function server(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
} 
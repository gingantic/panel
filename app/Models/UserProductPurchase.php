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
    ];

    protected $casts = [
        'user_id' => 'integer',
        'product_id' => 'integer',
        'egg_id' => 'integer',
        'server_id' => 'integer',
        'credits_charged' => 'integer',
        'next_renew_at' => 'datetime',
    ];
} 
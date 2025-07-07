<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int $cpu
 * @property int $memory
 * @property int $disk
 * @property int $swap
 * @property int $credits
 * @property int $max_per_user
 * @property bool $disabled
 * @property \Pterodactyl\Models\Nest[]|\Illuminate\Database\Eloquent\Collection $nests
 * @property \Pterodactyl\Models\Node[]|\Illuminate\Database\Eloquent\Collection $nodes
 */
class Product extends Model
{
    /**
     * The resource name for this model when transformed into an API representation.
     */
    public const RESOURCE_NAME = 'product';

    /**
     * The table associated with the model.
     */
    protected $table = 'products';

    /**
     * Fields that are mass assignable.
     */
    protected $fillable = [
        'name',
        'description',
        'cpu',
        'memory',
        'disk',
        'swap',
        'credits',
        'billing_cycle',
        'max_per_user',
        'disabled',
    ];

    /**
     * Field casting definitions.
     */
    protected $casts = [
        'cpu' => 'integer',
        'memory' => 'integer',
        'disk' => 'integer',
        'swap' => 'integer',
        'credits' => 'integer',
        'billing_cycle' => 'string',
        'max_per_user' => 'integer',
        'disabled' => 'boolean',
    ];

    /**
     * Validation rules for creating a product.
     */
    public static array $validationRules = [
        'name' => 'required|string|min:2|max:255|unique:products,name',
        'description' => 'nullable|string|max:255',
        'cpu' => 'required|integer|min:1',
        'memory' => 'required|integer|min:1',
        'disk' => 'required|integer|min:1',
        'swap' => 'required|integer',
        'credits' => 'required|integer|min:0',
        'billing_cycle' => 'required|string|in:hourly,daily,weekly,monthly,yearly',
        'max_per_user' => 'required|integer|min:1',
        'disabled' => 'boolean',
    ];

    /**
     * Returns the nests this product can be used for.
     */
    public function nests(): BelongsToMany
    {
        return $this->belongsToMany(Nest::class, 'nest_product');
    }

    /**
     * Returns the nodes this product can be deployed to.
     */
    public function nodes(): BelongsToMany
    {
        return $this->belongsToMany(Node::class, 'node_product');
    }
} 
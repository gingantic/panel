<?php

namespace Pterodactyl\Http\Requests\Admin;

use Pterodactyl\Models\Product;

class ProductFormRequest extends AdminFormRequest
{
    /**
     * Set up validation rules for create/update requests.
     */
    public function rules(): array
    {
        if ($this->method() === 'PATCH') {
            return Product::getRulesForUpdate($this->route()->parameter('product')->id);
        }

        return Product::getRules();
    }
} 
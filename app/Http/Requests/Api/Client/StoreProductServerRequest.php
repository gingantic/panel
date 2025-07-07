<?php

namespace Pterodactyl\Http\Requests\Api\Client;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return !is_null($this->user());
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|min:3|max:255',
            'description' => 'nullable|string|max:500',
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'egg_id' => ['nullable', 'integer', 'exists:eggs,id'],
        ];
    }
} 
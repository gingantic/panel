<?php

namespace Pterodactyl\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UserCreditFormRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only admins can access this route via middleware, so return true.
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'amount' => 'required|integer|min:1',
            'action' => 'required|in:add,subtract',
            'description' => 'nullable|string|max:255',
        ];
    }

    /**
     * Normalize request data.
     */
    public function normalize(): array
    {
        return $this->only(['amount', 'action', 'description']);
    }
} 
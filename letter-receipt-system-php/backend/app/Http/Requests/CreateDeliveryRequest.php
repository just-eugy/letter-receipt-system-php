<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateDeliveryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Public endpoint
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fullName' => 'required|string|max:160',
            'phone' => 'nullable|string|max:60',
            'email' => 'nullable|email|max:180',
            'recipient' => 'nullable|string|max:160',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'fullName.required' => 'Full name is required.',
            'fullName.max' => 'Full name must not exceed 160 characters.',
            'phone.max' => 'Phone must not exceed 60 characters.',
            'email.email' => 'Email must be a valid email address.',
            'email.max' => 'Email must not exceed 180 characters.',
            'recipient.max' => 'Recipient must not exceed 160 characters.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Convert empty strings to null for optional fields
        foreach (['phone', 'email', 'recipient'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }
}
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'dateFrom' => 'nullable|date',
            'dateTo' => 'nullable|date|after_or_equal:dateFrom',
            'recipientPosition' => 'nullable|string|max:160',
            'status' => 'nullable|in:DELIVERED,RECEIVED',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'dateFrom.date' => 'Date from must be a valid date.',
            'dateTo.date' => 'Date to must be a valid date.',
            'dateTo.after_or_equal' => 'Date to must be after or equal to date from.',
            'recipientPosition.max' => 'Recipient position must not exceed 160 characters.',
            'status.in' => 'Status must be either DELIVERED or RECEIVED.',
        ];
    }
}
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Cryptocurrency;

class CreatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'currency' => [
                'required',
                'string',
                'exists:cryptocurrencies,symbol',
                function ($attribute, $value, $fail) {
                    $crypto = Cryptocurrency::where('symbol', strtoupper($value))
                        ->where('is_active', true)
                        ->first();
                    
                    if (!$crypto) {
                        $fail("The {$attribute} is not supported or is currently disabled.");
                    }
                }
            ],
            'amount' => [
                'required',
                'numeric',
                'min:0.00000001',
                function ($attribute, $value, $fail) {
                    $currency = strtoupper($this->input('currency'));
                    $crypto = Cryptocurrency::where('symbol', $currency)->first();
                    
                    if ($crypto && !$crypto->isValidAmount($value)) {
                        $fail("The {$attribute} must be between {$crypto->min_amount} and " . 
                              ($crypto->max_amount ?: 'unlimited') . " {$currency}.");
                    }
                }
            ],
            'amount_usd' => 'nullable|numeric|min:0',
            'callback_url' => 'nullable|url|max:500',
            'expires_in_minutes' => 'nullable|integer|min:5|max:1440', // 5 minutes to 24 hours
            'required_confirmations' => 'nullable|integer|min:1|max:100',
            'metadata' => 'nullable|array',
            'metadata.*' => 'string|max:1000'
        ];
    }

    public function messages(): array
    {
        return [
            'currency.required' => 'Currency is required.',
            'currency.exists' => 'The specified currency is not supported.',
            'amount.required' => 'Amount is required.',
            'amount.numeric' => 'Amount must be a valid number.',
            'amount.min' => 'Amount must be greater than 0.',
            'callback_url.url' => 'Callback URL must be a valid URL.',
            'expires_in_minutes.min' => 'Expiration time must be at least 5 minutes.',
            'expires_in_minutes.max' => 'Expiration time cannot exceed 24 hours.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge([
                'currency' => strtoupper($this->currency)
            ]);
        }
    }
}
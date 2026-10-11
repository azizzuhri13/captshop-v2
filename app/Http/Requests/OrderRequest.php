<?php

namespace App\Http\Requests;

use App\Models\ProductItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderRequest extends FormRequest
{
    /**
     * Indicates if the validator should stop on the first rule failure.
     */
    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'product_item_id' => [
                'required',
                'integer',
                Rule::exists('product_items', 'id')->where('is_active', true),
            ],
            'payment_channel' => [
                'required',
                'string',
                Rule::exists('payment_channels', 'code')->where('is_active', true),
            ],
            'customer_data' => ['required', 'array', 'min:1'],
            'customer_data.*' => ['nullable', 'string', 'max:100'],
            'customer_phone' => ['required', 'string', 'regex:/^(\+?62|0)8[1-9][0-9]{6,10}$/'],
            'customer_email' => [config('payment.default') === 'belibayar' ? 'required' : 'nullable', 'email'],
            'coupon_code' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_item_id.exists' => 'Item tidak ditemukan atau sedang tidak tersedia.',
            'payment_channel.exists' => 'Metode pembayaran tidak tersedia.',
            'customer_phone.regex' => 'Nomor WhatsApp harus nomor Indonesia yang valid (contoh: 08xxxxxxxxxx).',
        ];
    }

    /**
     * Pastikan setiap input field wajib pada produk terisi di customer_data.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $item = ProductItem::query()
                ->whereKey((int) $this->input('product_item_id'))
                ->first();

            if ($item === null) {
                return; // sudah divalidasi rule exists
            }

            foreach ($item->product?->input_fields ?? [] as $field) {
                if (is_array($field) && ($field['required'] ?? false) === true) {
                    $value = $this->input('customer_data.'.$field['name']);

                    if ($value === null || trim((string) $value) === '') {
                        $validator->errors()->add(
                            'customer_data.'.$field['name'],
                            ($field['label'] ?? $field['name']).' wajib diisi.'
                        );
                    }
                }
            }
        });
    }
}

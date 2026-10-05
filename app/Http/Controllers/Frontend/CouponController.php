<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    /**
     * Validasi kode kupon dari form order (dipanggil via Alpine.js).
     * $amount = harga item terpilih (sebelum biaya layanan).
     */
    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $code = trim(strtoupper($validated['code']));
        $amount = (float) $validated['amount'];

        $coupon = Coupon::query()->where('code', $code)->first();

        if ($coupon === null || ($error = $coupon->validationError($amount)) !== null) {
            return response()->json([
                'valid' => false,
                'message' => $error ?? 'Kode kupon tidak ditemukan.',
            ], 422);
        }

        $discount = $coupon->calculateDiscount($amount);

        return response()->json([
            'valid' => true,
            'code' => $coupon->code,
            'discount' => $discount,
            'discount_formatted' => 'Rp '.number_format($discount, 0, ',', '.'),
            'discount_type' => $coupon->discount_type,
            'discount_value' => (float) $coupon->discount_value,
            'message' => 'Kupon berhasil dipakai! Hemat Rp '.number_format($discount, 0, ',', '.'),
        ]);
    }
}

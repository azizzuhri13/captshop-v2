<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\PaymentChannel;
use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\Payment\PaymentException;
use App\Services\Payment\PaymentGatewayInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    /**
     * Batas waktu pembayaran sebelum transaksi hangus (jam).
     */
    public const PAYMENT_EXPIRY_HOURS = 24;

    public function __construct(
        protected PaymentGatewayInterface $gateway,
    ) {}

    /**
     * Buat pesanan baru: validasi item & channel, hitung diskon kupon, fee &
     * profit, simpan transaksi (pending), lalu panggil gateway untuk
     * mendapatkan instruksi pembayaran.
     *
     * @param  array<string, mixed>  $data  Data pesanan tervalidasi dari OrderRequest.
     *
     * @throws \InvalidArgumentException Item, channel, atau kupon tidak valid.
     * @throws PaymentException
     */
    public function place(array $data): Transaction
    {
        $item = ProductItem::query()
            ->where('is_active', true)
            ->whereKey($data['product_item_id'])
            ->lockForUpdate()
            ->first();

        if ($item === null) {
            throw new \InvalidArgumentException('Item tidak ditemukan atau tidak aktif.');
        }

        $channel = PaymentChannel::query()
            ->where('is_active', true)
            ->where('gateway', $this->gateway->gatewayName())
            ->where('code', $data['payment_channel'])
            ->first();

        if ($channel === null) {
            throw new \InvalidArgumentException('Channel pembayaran tidak tersedia.');
        }

        $amount = (float) $item->effectivePrice();

        // Kupon divalidasi ulang di server (bukan sekadar percaya hasil
        // /coupon/check) dengan basis harga item yang dipilih.
        $coupon = $this->resolveCoupon((string) ($data['coupon_code'] ?? ''), $amount);
        $discount = $coupon?->calculateDiscount($amount) ?? 0.0;

        // Belibayar menerima nominal IDR bulat; tampilkan dan tagih jumlah yang sama.
        $fee = round($channel->calculateFee($amount - $discount));
        $total = round($amount - $discount + $fee);
        $profit = round($amount - $discount - (float) $item->buy_price, 2);

        return DB::transaction(function () use ($item, $channel, $data, $amount, $coupon, $discount, $fee, $total, $profit) {
            $transaction = Transaction::create([
                'invoice_id' => $this->generateInvoiceId(),
                'product_item_id' => $item->id,
                'customer_data' => $data['customer_data'],
                'customer_phone' => $data['customer_phone'],
                'customer_email' => $data['customer_email'] ?? null,
                'amount' => (string) $amount,
                'fee' => (string) $fee,
                'total' => (string) $total,
                'profit' => (string) $profit,
                'coupon_code' => $coupon?->code,
                'coupon_discount' => (string) $discount,
                'payment_method' => $channel->group,
                'payment_channel' => $channel->code,
                'payment_gateway' => $this->gateway->gatewayName(),
                'status' => 'pending',
                'expired_at' => now()->addHours(self::PAYMENT_EXPIRY_HOURS),
            ]);

            if ($coupon !== null) {
                // Increment atomik agar kuota tidak lolos pada order konkuren.
                Coupon::query()
                    ->whereKey($coupon->id)
                    ->where(function ($query) {
                        $query->where('max_usage', 0)->orWhereRaw('used_count < max_usage');
                    })
                    ->increment('used_count');
            }

            try {
                $payment = $this->gateway->createTransaction($transaction, [
                    'name' => $item->product?->name.' — '.$item->name,
                    'price' => (int) round($total),
                    'quantity' => 1,
                ], [
                    'name' => $data['customer_name'] ?? 'Pelanggan',
                    'phone' => $data['customer_phone'],
                    'email' => $data['customer_email'] ?? null,
                ], [
                    'method' => $channel->code,
                    'sku' => $item->supplier_item_code ?? $transaction->invoice_id,
                ]);

                if ($this->gateway->gatewayName() !== 'belibayar') {
                    throw PaymentException::requestFailed('Gateway pembayaran tidak didukung.');
                }
                $needsCode = in_array($channel->group, ['Virtual Account', 'Retail'], true);
                $needsRedirect = in_array($channel->code, ['DANA', 'SPAY', 'ASTRA', 'SC'], true);
                if (($payment['reference'] ?? null) !== $transaction->invoice_id
                        || ! is_string($payment['transaction_id'] ?? null)
                        || ! is_int($payment['amount'] ?? null)
                        || $payment['amount'] !== (int) $transaction->total
                        || ($payment['payment_channel'] ?? null) !== $channel->code
                        || ($payment['status'] ?? null) !== 'pending'
                        || ! is_int($payment['expired_at'] ?? null)
                        || $payment['expired_at'] <= now()->timestamp
                        || ($needsCode && empty($payment['va_number']))
                        || ($channel->code === 'QRIS' && empty($payment['qr_url']) && empty($payment['qr_code']))
                        || ($needsRedirect && empty($payment['payment_url']))) {
                    throw PaymentException::requestFailed('Respons pembayaran Belibayar tidak lengkap atau tidak cocok.');
                }
                $transaction->forceFill([
                    'payment_ref' => $payment['transaction_id'],
                    'payment_url' => $payment['payment_url'] ?? null,
                    'payment_instructions' => [
                        'va_number' => $payment['va_number'] ?? null,
                        'qr_content' => $payment['qr_content'] ?? null,
                        'qr_url' => $payment['qr_url'] ?? $payment['qr_code'] ?? null,
                    ],
                    'expired_at' => \Illuminate\Support\Carbon::createFromTimestamp($payment['expired_at']),
                ])->save();
            } catch (PaymentException $e) {
                Log::warning('Gagal membuat transaksi Belibayar; pesanan dibatalkan.', [
                    'invoice_id' => $transaction->invoice_id,
                    'error' => $e->getMessage(),
                ]);
                throw $e; // Rollback transaksi & penggunaan kupon; jangan buat invoice tanpa instruksi bayar.
            }

            return $transaction;
        });
    }

    /**
     * Cari & validasi kupon berdasarkan kode untuk pembelian $amount.
     *
     * @throws \InvalidArgumentException Kupon tidak ditemukan/tidak valid.
     */
    protected function resolveCoupon(string $code, float $amount): ?Coupon
    {
        $code = trim(strtoupper($code));

        if ($code === '') {
            return null;
        }

        $coupon = Coupon::query()->where('code', $code)->first();

        if ($coupon === null || ($error = $coupon->validationError($amount)) !== null) {
            throw new \InvalidArgumentException($error ?? 'Kupon tidak ditemukan.');
        }

        return $coupon;
    }

    /**
     * Generate ID invoice unik: INV-YYYYMMDD-XXXXXX.
     */
    protected function generateInvoiceId(): string
    {
        do {
            $random = strtoupper(str_pad(base_convert((string) random_int(0, 36 ** 6 - 1), 10, 36), 6, '0', STR_PAD_LEFT));
            $invoiceId = 'INV-'.now()->format('Ymd').'-'.$random;
        } while (Transaction::query()->where('invoice_id', $invoiceId)->exists());

        return $invoiceId;
    }
}

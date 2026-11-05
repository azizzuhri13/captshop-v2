<?php

namespace App\Services;

use App\Models\CapcutAccount;
use App\Models\Coupon;
use App\Models\PaymentChannel;
use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\Payment\PaymentException;
use App\Services\Payment\PaymentGatewayInterface;
use App\Support\DigiflazzCatalog;
use Illuminate\Support\Carbon;
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

        if ($item->isOutOfStock()) {
            throw new \InvalidArgumentException('Maaf, stok untuk paket '.$item->name.' sedang habis.');
        }

        // Checkout DigiFlazz hanya boleh jalan di atas pricelist segar yang
        // menyatakan SKU persis ini tersedia; status aktif di DB saja bisa
        // tertinggal dari kenyataan supplier.
        if ($item->product?->supplier_type === 'digiflazz'
            && ! DigiflazzCatalog::isAvailableRow(DigiflazzCatalog::freshRow((string) $item->supplier_item_code))) {
            throw new \InvalidArgumentException('Katalog DigiFlazz belum sinkron atau paket ini sedang tidak tersedia. Coba beberapa saat lagi.');
        }

        $channel = PaymentChannel::query()
            ->where('is_active', true)
            ->where('gateway', $this->gateway->gatewayName())
            ->where('code', $data['payment_channel'])
            ->first();

        if ($channel === null) {
            throw new \InvalidArgumentException('Channel pembayaran tidak tersedia.');
        }

        // Tripay menolak transaksi tanpa email pembeli. Tolak lebih awal agar
        // user melihat error validasi, bukan pesanan yang batal di tengah jalan
        // (dan jangan pernah mengarang alamat email pengganti).
        if ($this->gateway->gatewayName() === 'tripay'
            && trim((string) ($data['customer_email'] ?? '')) === '') {
            throw new \InvalidArgumentException('Email wajib diisi untuk metode pembayaran ini.');
        }

        return DB::transaction(function () use ($item, $channel, $data) {
            // CapCut: kunci akun stok termurah (paling tua) pada saat checkout
            // supaya harga & alokasi akun persis sama dengan yang dibayar.
            $capcutAccount = null;

            if ($item->product?->slug === 'capcut') {
                CapcutAccount::expireStale();

                $capcutAccount = CapcutAccount::query()
                    ->sellable()
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if ($capcutAccount === null) {
                    throw new \InvalidArgumentException('Maaf, stok untuk paket '.$item->name.' sedang habis.');
                }

                $amount = (float) $capcutAccount->tierInfo()['price'];
            } else {
                $amount = (float) $item->effectivePrice();
            }

            // Kupon divalidasi ulang di server (bukan sekadar percaya hasil
            // /coupon/check) dengan basis harga item yang dipilih.
            $coupon = $this->resolveCoupon((string) ($data['coupon_code'] ?? ''), $amount);
            $discount = $coupon?->calculateDiscount($amount) ?? 0.0;

            // Belibayar menerima nominal IDR bulat; tampilkan dan tagih jumlah yang sama.
            $fee = round($channel->calculateFee($amount - $discount));
            $total = round($amount - $discount + $fee);
            $profit = round($amount - $discount - (float) $item->buy_price, 2);

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

            if ($capcutAccount !== null) {
                // Reservasi akun persis ini untuk invoice; dilepas lagi bila
                // pembayaran kedaluwarsa/dibatalkan (lihat CapcutAccount::releaseForTransaction).
                $capcutAccount->forceFill([
                    'status' => 'reserved',
                    'channel' => 'web',
                    'transaction_id' => $transaction->id,
                ])->save();
            }

            if ($coupon !== null) {
                // Increment atomik agar kuota tidak lolos pada order konkuren.
                $updated = Coupon::query()
                    ->whereKey($coupon->id)
                    ->where(function ($query) {
                        $query->where('max_usage', 0)->orWhereRaw('used_count < max_usage');
                    })
                    ->increment('used_count');

                if ($coupon->max_usage > 0 && $updated === 0) {
                    throw new \InvalidArgumentException('Kuota kupon telah habis.');
                }
            }

            try {
                $payment = $this->createPayment($transaction, [
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

                // Bentuk kanonik per gateway: identitas merchant_ref/amount/channel,
                // status pending, expiry masa depan, dan minimal satu instruksi bayar
                // yang valid. Semua penyimpangan = pesanan dibatalkan (fail-closed).
                $canonical = $this->authorizePayment($payment, $transaction, $channel);

                $transaction->forceFill([
                    'payment_ref' => $canonical['provider_ref'],
                    'payment_url' => $canonical['payment_url'],
                    'payment_instructions' => [
                        'merchant_ref' => $canonical['merchant_ref'],
                        'channel' => $canonical['channel'],
                        'va_number' => $canonical['va_number'],
                        ...(isset($canonical['va_number_raw']) ? ['va_number_raw' => $canonical['va_number_raw']] : []),
                        'qr_content' => $canonical['qr_string'],
                        'qr_url' => $canonical['qr_url'],
                        'payable_amount' => $canonical['payable_amount'],
                    ],
                    'expired_at' => Carbon::createFromTimestamp($canonical['expires_at']),
                ])->save();
            } catch (PaymentException $e) {
                Log::warning('Gagal membuat transaksi pembayaran; pesanan dibatalkan.', [
                    'invoice_id' => $transaction->invoice_id,
                    'gateway' => $this->gateway->gatewayName(),
                    'error' => $e->getMessage(),
                ]);
                throw $e; // Rollback transaksi & penggunaan kupon; jangan buat invoice tanpa instruksi bayar.
            }

            return $transaction;
        });
    }

    /**
     * Panggil gateway untuk membuat tagihan, dengan pemulihan aman untuk
     * kegagalan jaringan yang ambigu.
     *
     * Bila koneksi putus saat create, transaksi bisa saja SUDAH terbentuk di
     * Tripay meski respons tidak pernah sampai. Karena itu:
     *  - create tidak pernah diulang otomatis (risiko double charge);
     *  - satu kali GET detail berdasarkan merchant_ref (invoice kita) dipakai
     *    untuk memastikan apakah transaksi benar-benar ada;
     *  - hanya transaksi pending yang identitasnya cocok yang dipakai ulang.
     * Bila detail juga gagal/tidak cocok, error asli dilempar sehingga pesanan
     * dibatalkan dan tidak pernah tampil dengan instruksi bayar palsu.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $customer
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    protected function createPayment(Transaction $transaction, array $item, array $customer, array $params): array
    {
        try {
            return $this->gateway->createTransaction($transaction, $item, $customer, $params);
        } catch (PaymentException $e) {
            if ($this->gateway->gatewayName() !== 'tripay') {
                throw $e;
            }

            try {
                $detail = $this->gateway->getTransactionDetail((string) $transaction->invoice_id);
                $recovered = $this->gateway->normalizeDetail($detail, $transaction);
            } catch (PaymentException) {
                throw $e;
            }

            if (($recovered['status'] ?? null) !== 'pending') {
                throw $e;
            }

            Log::warning('Create Tripay ambigu; transaksi pending dipulihkan lewat detail merchant_ref.', [
                'invoice_id' => $transaction->invoice_id,
                'provider_ref' => $recovered['provider_ref'],
            ]);

            return $detail;
        }
    }

    /**
     * Validasi kanonik sebelum instruksi bayar disimpan. Nilai yang dipakai
     * untuk menagih pelanggan harus persis sama dengan pesanan kita.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    protected function authorizePayment(array $response, Transaction $transaction, PaymentChannel $channel): array
    {
        $canonical = $this->gateway->normalizeCharge($response, $transaction, $channel);

        $invoiceId = (string) $transaction->invoice_id;
        $total = (int) round((float) $transaction->total);

        if (($canonical['merchant_ref'] ?? null) !== $invoiceId
            || ! is_string($canonical['provider_ref'] ?? null)
            || $canonical['provider_ref'] === ''
            || ! is_int($canonical['merchant_amount'] ?? null)
            || $canonical['merchant_amount'] !== $total
            || ($canonical['status'] ?? null) !== 'pending'
            || ! is_int($canonical['expires_at'] ?? null)
            || $canonical['expires_at'] <= now()->timestamp) {
            throw PaymentException::requestFailed('Respons pembayaran tidak lengkap atau tidak cocok dengan pesanan.');
        }

        $this->assertChannelIdentity((string) $canonical['channel'], $channel);
        $this->assertPayableInstructions($canonical);

        return $canonical;
    }

    /**
     * Channel yang ditagih harus channel yang dipilih pelanggan: kode internal
     * untuk Belibayar, kode metode Tripay hasil pemetaan config untuk Tripay.
     *
     * @throws PaymentException
     */
    protected function assertChannelIdentity(string $gatewayChannel, PaymentChannel $channel): void
    {
        if ($this->gateway->gatewayName() === 'tripay') {
            $map = config('payment.tripay.channels', []);
            $expected = is_array($map) ? ($map[strtoupper(trim((string) $channel->code))] ?? null) : null;

            if (! is_string($expected) || $expected === ''
                || strtoupper(trim($gatewayChannel)) !== strtoupper($expected)) {
                throw PaymentException::requestFailed('Channel pembayaran Tripay tidak sesuai permintaan.');
            }

            return;
        }

        if ($gatewayChannel !== (string) $channel->code) {
            throw PaymentException::requestFailed('Channel pembayaran tidak sesuai permintaan.');
        }
    }

    /**
     * Minimal satu instruksi bayar harus bisa dipakai pelanggan, dan setiap
     * instruksi yang ada harus berbentuk wajar. URL non-HTTPS ditolak supaya
     * tautan bayar tidak bisa dibelokkan ke skema lain (javascript:, dll).
     *
     * @param  array<string, mixed>  $canonical
     *
     * @throws PaymentException
     */
    protected function assertPayableInstructions(array &$canonical): void
    {
        $vaNumber = $canonical['va_number'] ?? null;

        // Belibayar may format VA numbers with ASCII spaces (including a
        // leading pad). Keep the provider value for audit, but only expose the
        // validated, copyable number to the customer.
        if ($this->gateway->gatewayName() === 'belibayar' && is_string($vaNumber) && str_contains($vaNumber, ' ')) {
            if (preg_match('/^[A-Za-z0-9._ -]+$/D', $vaNumber) !== 1) {
                throw PaymentException::requestFailed('Kode pembayaran dari gateway tidak valid.');
            }

            $canonical['va_number_raw'] = $vaNumber;
            $canonical['va_number'] = $vaNumber = str_replace(' ', '', $vaNumber);
        }
        $qrString = $canonical['qr_string'] ?? null;
        $qrUrl = $canonical['qr_url'] ?? null;
        $paymentUrl = $canonical['payment_url'] ?? null;

        if ($vaNumber === null && $qrString === null && $qrUrl === null && $paymentUrl === null) {
            throw PaymentException::requestFailed('Gateway tidak mengembalikan instruksi pembayaran.');
        }

        if ($vaNumber !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{3,39}$/D', $vaNumber) !== 1) {
            throw PaymentException::requestFailed('Kode pembayaran dari gateway tidak valid.');
        }

        foreach ([$paymentUrl, $qrUrl] as $url) {
            if ($url !== null) {
                $this->assertHttpsUrl($url);
            }
        }
    }

    /**
     * @throws PaymentException
     */
    protected function assertHttpsUrl(string $url): void
    {
        // Belibayar sandbox memakai QR dummy inline; itu satu-satunya non-HTTPS
        // yang boleh tampil, dan hanya sebagai gambar.
        if (str_starts_with($url, 'data:image/')) {
            return;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])) {
            throw PaymentException::requestFailed('Tautan pembayaran dari gateway tidak valid.');
        }
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

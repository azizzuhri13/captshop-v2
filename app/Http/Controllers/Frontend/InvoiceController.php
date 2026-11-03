<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\Payment\BelibayarService;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\TripayService;
use App\Services\FulfillmentService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    /**
     * Kedua service konkret di-inject (bukan binding gateway default) karena
     * halaman invoice harus menanyakan status ke gateway yang benar-benar
     * dipakai transaksi tersebut. Transaksi lama tetap Belibayar walau
     * konfigurasi default sudah berpindah ke Tripay.
     */
    public function __construct(
        protected BelibayarService $belibayar,
        protected TripayService $tripay,
        protected FulfillmentService $fulfillment,
    ) {}

    /**
     * Halaman invoice: detail pesanan + instruksi pembayaran.
     * Akses publik via invoice ID (tanpa login, sesuai konsep guest checkout),
     * tetapi kredensial akun hanya tampil lewat tautan bertanda tangan resmi
     * (yang dikirim ke WhatsApp/email pembeli).
     */
    public function show(Request $request, string $invoiceId): View
    {
        $transaction = Transaction::query()
            ->where('invoice_id', $invoiceId)
            ->with(['productItem.product.category'])
            ->firstOrFail();

        if ($transaction->status === 'pending' && $transaction->expired_at && $transaction->expired_at->isPast()) {
            $transaction->forceFill(['status' => 'expired'])->save();
            // Lepas reservasi akun CapCut yang terkunci untuk invoice kedaluwarsa.
            \App\Models\CapcutAccount::releaseForTransaction($transaction->id);
        }

        $canViewCredentials = $request->hasValidSignature()
            || session('order_placed_'.$invoiceId) === true
            || (\Illuminate\Support\Facades\Auth::guard('web')->check()
                && \Illuminate\Support\Facades\Auth::guard('web')->user()->email
                && \Illuminate\Support\Facades\Auth::guard('web')->user()->email === $transaction->customer_email);

        return view('frontend.invoice', [
            'transaction' => $transaction,
            'canViewCredentials' => $canViewCredentials,
        ]);
    }

    /**
     * Cek status pembayaran ke gateway lalu refresh tampilan.
     *
     * Hanya transaksi yang masih pending/expired dan punya referensi provider
     * yang di-inquiry. Respons gateway wajib cocok dengan data tersimpan
     * (merchant_ref, referensi provider, nominal, channel) sebelum status
     * boleh berubah; ketidakcocokan apa pun diabaikan tanpa efek samping.
     */
    public function checkStatus(string $invoiceId)
    {
        $transaction = Transaction::query()
            ->where('invoice_id', $invoiceId)
            ->firstOrFail();

        if (in_array($transaction->status, ['pending', 'expired'], true) && $transaction->payment_ref) {
            $gateway = $this->gatewayFor($transaction);

            if ($gateway !== null) {
                try {
                    $detail = $gateway->getTransactionDetail($this->inquiryReference($transaction, $gateway));
                    $canonical = $gateway->normalizeDetail($detail, $transaction);

                    $this->assertInquiryMatches($canonical, $transaction, $gateway);

                    $status = (string) $canonical['status'];

                    if ($status === 'paid') {
                        if ($this->claimPaid($transaction)) {
                            $this->fulfillment->fulfill($transaction->fresh());
                        }
                    } elseif ($status === 'expired') {
                        $this->expirePending($transaction);
                    }
                } catch (\Throwable $e) {
                    // Kegagalan gateway atau mismatch tidak boleh mengubah status pesanan.
                }
            }
        }

        return back()->with('info', 'Status terbaru: '.strtoupper($transaction->fresh()->status));
    }

    /**
     * Service gateway sesuai transaksi, bukan sesuai konfigurasi default saat
     * ini. Transaksi tanpa gateway (data lama) tidak pernah di-inquiry.
     */
    protected function gatewayFor(Transaction $transaction): ?PaymentGatewayInterface
    {
        return match ((string) $transaction->payment_gateway) {
            'belibayar' => $this->belibayar,
            'tripay' => $this->tripay,
            default => null,
        };
    }

    /**
     * Kunci pencarian detail di provider: Belibayar memakai reference kita
     * (invoice_id), Tripay memakai reference miliknya (payment_ref).
     */
    protected function inquiryReference(Transaction $transaction, PaymentGatewayInterface $gateway): string
    {
        return $gateway->gatewayName() === 'tripay'
            ? (string) $transaction->payment_ref
            : (string) $transaction->invoice_id;
    }

    /**
     * Bandingkan bentuk kanonik detail dengan data yang tersimpan.
     *
     * @param  array<string, mixed>  $canonical
     *
     * @throws \RuntimeException
     */
    protected function assertInquiryMatches(array $canonical, Transaction $transaction, PaymentGatewayInterface $gateway): void
    {
        $total = (int) round((float) $transaction->total);

        if (($canonical['merchant_ref'] ?? null) !== (string) $transaction->invoice_id
            || ($canonical['provider_ref'] ?? null) !== (string) $transaction->payment_ref
            || ! is_int($canonical['merchant_amount'] ?? null)
            || $canonical['merchant_amount'] !== $total) {
            throw new \RuntimeException('Status pembayaran tidak cocok.');
        }

        $expectedChannel = $this->storedChannelCode($transaction, $gateway);

        if ($expectedChannel === ''
            || strtoupper(trim((string) ($canonical['channel'] ?? ''))) !== $expectedChannel) {
            throw new \RuntimeException('Channel pembayaran tidak cocok.');
        }

        if (! in_array((string) ($canonical['status'] ?? ''), ['pending', 'paid', 'expired', 'failed'], true)) {
            throw new \RuntimeException('Status pembayaran tidak dikenali.');
        }
    }

    /**
     * Channel yang tersimpan (kode internal) dipetakan ke kode provider:
     * Tripay memakai kode metode, Belibayar memakai kode internal apa adanya.
     */
    protected function storedChannelCode(Transaction $transaction, PaymentGatewayInterface $gateway): string
    {
        $stored = strtoupper(trim((string) $transaction->payment_channel));

        if ($gateway->gatewayName() !== 'tripay') {
            return $stored;
        }

        $map = config('payment.tripay.channels', []);

        return is_array($map) ? strtoupper((string) ($map[$stored] ?? '')) : '';
    }

    /**
     * Klaim status paid secara idempoten: hanya satu pemanggil yang menang,
     * dan hanya bila transaksi masih pending/expired. Fulfillment dijalankan
     * setelah klaim, di luar lock.
     */
    protected function claimPaid(Transaction $transaction): bool
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($transaction) {
            $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['pending', 'expired'], true)) {
                return false;
            }

            $locked->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

            return true;
        });
    }

    /**
     * Tandai kedaluwarsa hanya bila masih pending, sambil melepas reservasi
     * akun CapCut yang terkunci.
     */
    protected function expirePending(Transaction $transaction): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($transaction) {
            $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'pending') {
                $locked->forceFill(['status' => 'expired'])->save();
                \App\Models\CapcutAccount::releaseForTransaction($locked->id);
            }
        });
    }
}
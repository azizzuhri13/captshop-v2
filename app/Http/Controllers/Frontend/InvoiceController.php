<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\Payment\BelibayarService;
use App\Services\FulfillmentService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function __construct(
        protected BelibayarService $belibayar,
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
     */
    public function checkStatus(string $invoiceId)
    {
        $transaction = Transaction::query()
            ->where('invoice_id', $invoiceId)
            ->firstOrFail();

        if (in_array($transaction->status, ['pending', 'expired'], true)
            && $transaction->payment_gateway === 'belibayar' && $transaction->payment_ref) {
            try {
                $detail = $this->belibayar->getTransactionDetail($transaction->invoice_id);
                if (($detail['transaction_id'] ?? null) !== $transaction->payment_ref
                    || ($detail['reference'] ?? null) !== $transaction->invoice_id
                    || ($detail['payment_channel'] ?? null) !== $transaction->payment_channel
                    || ! is_int($detail['amount'] ?? null)
                    || $detail['amount'] !== (int) $transaction->total) {
                    throw new \RuntimeException('Status pembayaran tidak cocok.');
                }
                $status = $this->belibayar->mapStatus((string) ($detail['status'] ?? ''));
                if ($status === 'paid' && in_array($transaction->status, ['pending', 'expired'], true)) {
                    $claimed = \Illuminate\Support\Facades\DB::transaction(function () use ($transaction) {
                        $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
                        if (! in_array($locked->status, ['pending', 'expired'], true)) {
                            return false;
                        }
                        $locked->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
                        return true;
                    });
                    if ($claimed) {
                        $this->fulfillment->fulfill($transaction->fresh());
                    }
                } elseif ($status === 'expired' && $transaction->status === 'pending') {
                    \Illuminate\Support\Facades\DB::transaction(function () use ($transaction) {
                        $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
                        if ($locked->status === 'pending') {
                            $locked->forceFill(['status' => 'expired'])->save();
                        }
                    });
                }
            } catch (\Throwable $e) {
                // Kegagalan gateway atau mismatch tidak boleh mengubah status pesanan.
            }
        }

        return back()->with('info', 'Status terbaru: '.strtoupper($transaction->fresh()->status));
    }
}

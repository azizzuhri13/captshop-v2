<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\Payment\PaymentGatewayInterface;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function __construct(
        protected PaymentGatewayInterface $gateway,
    ) {}

    /**
     * Halaman invoice: detail pesanan + instruksi pembayaran.
     * Akses publik via invoice ID (tanpa login, sesuai konsep guest checkout).
     */
    public function show(string $invoiceId): View
    {
        $transaction = Transaction::query()
            ->where('invoice_id', $invoiceId)
            ->with(['productItem.product.category'])
            ->firstOrFail();

        return view('frontend.invoice', compact('transaction'));
    }

    /**
     * Cek status pembayaran ke gateway lalu refresh tampilan.
     */
    public function checkStatus(string $invoiceId)
    {
        $transaction = Transaction::query()
            ->where('invoice_id', $invoiceId)
            ->firstOrFail();

        if ($transaction->status === 'pending' && $transaction->payment_ref) {
            try {
                $detail = $this->gateway->getTransactionDetail($transaction->payment_ref);

                $status = $this->gateway->mapStatus((string) ($detail['status'] ?? ''));

                if ($status !== 'pending' && $transaction->status === 'pending') {
                    $transaction->forceFill([
                        'status' => $status,
                        'paid_at' => $status === 'paid' ? now() : $transaction->paid_at,
                    ])->save();
                }
            } catch (\Throwable $e) {
                // Gateway tidak merespons: biarkan status pending, user bisa coba lagi.
            }
        }

        return back()->with('info', 'Status terbaru: '.strtoupper($transaction->fresh()->status));
    }
}

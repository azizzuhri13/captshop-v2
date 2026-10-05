<?php

namespace App\Services\Notification;

use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function __construct(
        protected WhatsAppService $whatsapp,
        protected EmailService $email,
    ) {}

    /**
     * Notifikasi saat pesanan dibuat (pending): WA + email invoice.
     */
    public function orderCreated(Transaction $transaction): void
    {
        $this->dispatch(fn () => $this->whatsapp->sendInvoiceNotification($transaction), $transaction, 'invoice WhatsApp');
        $this->dispatch(fn () => $this->email->sendInvoice($transaction), $transaction, 'invoice email');
    }

    /**
     * Notifikasi saat pembayaran diterima (paid): WA + email bukti pembayaran.
     */
    public function paymentReceived(Transaction $transaction): void
    {
        $this->dispatch(fn () => $this->whatsapp->sendPaidNotification($transaction), $transaction, 'paid WhatsApp');
        $this->dispatch(fn () => $this->email->sendReceipt($transaction), $transaction, 'receipt email');
    }

    /**
     * Notifikasi saat pesanan selesai (completed): WA + email receipt.
     */
    public function orderCompleted(Transaction $transaction): void
    {
        $this->dispatch(fn () => $this->whatsapp->sendSuccessNotification($transaction), $transaction, 'success WhatsApp');
        $this->dispatch(fn () => $this->email->sendReceipt($transaction), $transaction, 'receipt email');
    }

    /**
     * Jalankan notifikasi; kegagalan tidak boleh menggagalkan proses transaksi.
     */
    protected function dispatch(callable $notifier, Transaction $transaction, string $channel): void
    {
        try {
            $notifier();
        } catch (\Throwable $e) {
            Log::error('Gagal mengirim notifikasi '.$channel.' untuk '.$transaction->invoice_id.': '.$e->getMessage());
        }
    }
}

<?php

namespace App\Services\Notification;

use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function __construct(
        protected WhatsAppService $whatsapp,
        protected EmailService $email,
        protected ?TelegramAlertService $telegram = null,
    ) {
        $this->telegram = $telegram ?? app(TelegramAlertService::class);
    }

    /**
     * Notifikasi saat pesanan dibuat (pending): WA + email invoice + alert Telegram.
     */
    public function orderCreated(Transaction $transaction): void
    {
        $this->dispatch(fn () => $this->whatsapp->sendInvoiceNotification($transaction), $transaction, 'invoice WhatsApp');
        $this->dispatch(fn () => $this->email->sendInvoice($transaction), $transaction, 'invoice email');
        $this->dispatch(fn () => $this->telegram?->alertNewOrder($transaction), $transaction, 'telegram new order');
    }

    /**
     * Notifikasi saat pembayaran diterima (paid): WA + email bukti pembayaran + alert Telegram dana masuk.
     */
    public function paymentReceived(Transaction $transaction): void
    {
        $this->dispatch(fn () => $this->whatsapp->sendPaidNotification($transaction), $transaction, 'paid WhatsApp');
        $this->dispatch(fn () => $this->email->sendReceipt($transaction), $transaction, 'receipt email');
        $this->dispatch(fn () => $this->telegram?->alertOrderPaid($transaction), $transaction, 'telegram order paid');

        if ((float) $transaction->total >= 500000) {
            $this->dispatch(fn () => $this->telegram?->alertHighValueOrder($transaction), $transaction, 'telegram high value');
        }
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
     * Jalankan notifikasi di background (defer) agar tidak memperlambat response;
     * kegagalan tidak boleh menggagalkan proses transaksi.
     */
    protected function dispatch(callable $notifier, Transaction $transaction, string $channel): void
    {
        $runner = function () use ($notifier, $transaction, $channel) {
            try {
                $notifier();
            } catch (\Throwable $e) {
                Log::error('Gagal mengirim notifikasi '.$channel.' untuk '.$transaction->invoice_id.': '.$e->getMessage());
            }
        };

        if (function_exists('defer')) {
            defer($runner);
        } else {
            $runner();
        }
    }
}

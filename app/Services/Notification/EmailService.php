<?php

namespace App\Services\Notification;

use App\Mail\InvoiceMail;
use App\Mail\ReceiptMail;
use App\Models\SiteSetting;
use App\Models\Transaction;
use Illuminate\Support\Facades\Mail;

class EmailService
{
    /**
     * Kirim email invoice (menunggu pembayaran) ke pelanggan.
     */
    public function sendInvoice(Transaction $transaction): void
    {
        if (empty($transaction->customer_email)) {
            return;
        }

        Mail::to($transaction->customer_email)->send(new InvoiceMail($transaction, $this->siteName()));
    }

    /**
     * Kirim email struk / bukti pembayaran (paid atau completed) ke pelanggan.
     */
    public function sendReceipt(Transaction $transaction): void
    {
        if (empty($transaction->customer_email)) {
            return;
        }

        Mail::to($transaction->customer_email)->send(new ReceiptMail($transaction, $this->siteName()));
    }

    protected function siteName(): string
    {
        return SiteSetting::get('site_name') ?? 'CAPTSHOP';
    }
}

<?php

namespace App\Mail;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Transaction $transaction,
        public string $siteName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->transaction->status === 'completed'
                ? $this->siteName.' — Pesanan '.$this->transaction->invoice_id.' Berhasil'
                : $this->siteName.' — Pembayaran Diterima '.$this->transaction->invoice_id,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.receipt',
        );
    }
}

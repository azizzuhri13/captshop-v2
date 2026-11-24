<?php

namespace App\Services\Notification;

use App\Models\SiteSetting;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class WhatsAppService
{
    /**
     * Kirim teks ke gateway lokal; kegagalan transport tidak menggagalkan transaksi.
     */
    public function send(string $phone, string $message, string $invoiceId, string $event): bool
    {
        $phone = $this->normalizePhone($phone);
        $key = (string) config('services.whatsapp_gateway.key', '');
        $baseUrl = LocalGatewayUrl::sanitize((string) config('services.whatsapp_gateway.url', ''));

        // Fail closed: never send (and never log the key or destination) when
        // the endpoint is not an approved loopback address.
        if ($phone === null || $key === '' || $baseUrl === null) {
            Log::warning('Notifikasi WhatsApp dilewati: konfigurasi atau nomor tujuan tidak valid.');

            return false;
        }

        try {
            $response = Http::baseUrl($baseUrl)
                // Never let the local adapter hold an order request open.
                ->connectTimeout(1)
                ->timeout(1)
                ->withHeaders([
                    'X-Internal-Key' => $key,
                    'Idempotency-Key' => "transaction:{$invoiceId}:event:{$event}",
                ])
                ->post('/v1/messages/text', [
                    'to' => $phone,
                    'text' => $message,
                    'metadata' => [
                        'invoice_id' => $invoiceId,
                        'event' => $event,
                        'source' => 'captshop-laravel',
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('WhatsApp gateway menolak notifikasi.', ['status' => $response->status()]);

                return false;
            }

            return true;
        } catch (\Throwable) {
            // Jangan log exception/request body yang bisa mengandung teks pesan atau key.
            Log::warning('WhatsApp gateway tidak dapat dijangkau.');

            return false;
        }
    }

    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';
        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        return preg_match('/^62[0-9]{8,13}$/', $digits) === 1 ? $digits : null;
    }

    /** Notifikasi pesanan berhasil dibuat (menunggu pembayaran). */
    public function sendInvoiceNotification(Transaction $transaction): bool
    {
        $message = implode("\n", [
            '*'.(SiteSetting::get('site_name') ?? 'CAPTSHOP').'*',
            '',
            'Pesanan Anda telah dibuat.',
            'Invoice: *'.$transaction->invoice_id.'*',
            'Item: '.$transaction->productItem?->name,
            'Total: *Rp '.number_format((float) $transaction->total, 0, ',', '.').'*',
            '',
            'Silakan selesaikan pembayaran sebelum '.$transaction->expired_at?->format('d M Y H:i').' WIB.',
            'Detail invoice: '.URL::signedRoute('invoice.show', ['invoiceId' => $transaction->invoice_id]),
        ]);

        return $this->send($transaction->customer_phone, $message, $transaction->invoice_id, 'created');
    }

    /** Notifikasi pembayaran diterima (pesanan diproses). */
    public function sendPaidNotification(Transaction $transaction): bool
    {
        $message = implode("\n", [
            '*'.(SiteSetting::get('site_name') ?? 'CAPTSHOP').'*',
            '',
            'Pembayaran untuk pesanan *'.$transaction->invoice_id.'* telah kami terima. ✅',
            'Item: '.$transaction->productItem?->name,
            'Total: Rp '.number_format((float) $transaction->total, 0, ',', '.'),
            '',
            'Pesanan Anda sedang diproses, mohon tunggu sebentar ya!',
        ]);

        return $this->send($transaction->customer_phone, $message, $transaction->invoice_id, 'paid');
    }

    /** Notifikasi pesanan berhasil tanpa membocorkan data supplier. */
    public function sendSuccessNotification(Transaction $transaction): bool
    {
        $message = implode("\n", [
            '*'.(SiteSetting::get('site_name') ?? 'CAPTSHOP').'*',
            '',
            'Pesanan *'.$transaction->invoice_id.'* telah berhasil diproses. 🎉',
            'Item: '.$transaction->productItem?->name,
            'Detail pesanan: '.URL::signedRoute('invoice.show', ['invoiceId' => $transaction->invoice_id]),
            '',
            'Terima kasih sudah berbelanja di '.(SiteSetting::get('site_name') ?? 'CAPTSHOP').'!',
        ]);

        return $this->send($transaction->customer_phone, $message, $transaction->invoice_id, 'completed');
    }
}

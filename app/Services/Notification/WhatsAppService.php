<?php

namespace App\Services\Notification;

use App\Models\SiteSetting;
use App\Models\Transaction;
use App\Support\Credentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    /**
     * Kirim notifikasi WhatsApp ke pelanggan.
     *
     * @param  array<string, string>  $params  Parameter Fonnte tambahan (countryCode, dst).
     */
    public function send(string $phone, string $message, array $params = []): bool
    {
        $token = Credentials::get('fonnte_token', config('services.fonnte.token'));
        $baseUrl = Credentials::get('fonnte_base_url', config('services.fonnte.base_url'));

        if (empty($token)) {
            Log::warning('Fonnte token kosong, notifikasi WhatsApp dilewati.');

            return false;
        }

        $response = Http::baseUrl(rtrim((string) $baseUrl, '/'))
            ->timeout(15)
            ->withHeaders(['Authorization' => $token])
            ->post('/send', array_merge([
                'target' => $phone,
                'message' => $message,
            ], $params));

        if (! $response->successful()) {
            Log::error('Gagal kirim WhatsApp via Fonnte.', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Notifikasi pesanan berhasil dibuat (menunggu pembayaran).
     */
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
            'Detail invoice: '.\Illuminate\Support\Facades\URL::signedRoute('invoice.show', ['invoiceId' => $transaction->invoice_id]),
        ]);

        return $this->send($transaction->customer_phone, $message);
    }

    /**
     * Notifikasi pembayaran diterima (pesanan diproses).
     */
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

        return $this->send($transaction->customer_phone, $message);
    }

    /**
     * Notifikasi pesanan berhasil (top-up berhasil diproses).
     */
    public function sendSuccessNotification(Transaction $transaction): bool
    {
        $message = implode("\n", [
            '*'.(SiteSetting::get('site_name') ?? 'CAPTSHOP').'*',
            '',
            'Pesanan *'.$transaction->invoice_id.'* telah berhasil diproses. 🎉',
            'Item: '.$transaction->productItem?->name,
            $transaction->supplier_sn ? 'SN: '.$transaction->supplier_sn : null,
            'Detail pesanan & kredensial: '.\Illuminate\Support\Facades\URL::signedRoute('invoice.show', ['invoiceId' => $transaction->invoice_id]),
            '',
            'Terima kasih sudah berbelanja di '.(SiteSetting::get('site_name') ?? 'CAPTSHOP').'!',
        ]);

        return $this->send($transaction->customer_phone, $message);
    }
}

<?php

namespace App\Services\Notification;

use App\Models\SiteSetting;
use App\Models\Transaction;
use App\Support\Credentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramAlertService
{
    /**
     * Kirim pesan teks / alert ke bot Telegram admin.
     */
    public function send(string $message): bool
    {
        // Cegah pengiriman notifikasi saat test runner (phpunit / pest) berjalan
        if (app()->environment('testing')) {
            return false;
        }

        $botToken = (string) Credentials::get('telegram_bot_token', config('services.telegram.bot_token'));
        $chatId = (string) Credentials::get('telegram_chat_id', config('services.telegram.chat_id'));

        if (empty($botToken) || empty($chatId)) {
            Log::info('Telegram alert dilewati: bot_token atau chat_id belum diatur.');
            return false;
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            if (! $response->successful()) {
                // Fallback kirim plain text jika ada karakter HTML (< / > / &) yang tidak valid
                $fallback = Http::timeout(10)->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => strip_tags(str_replace(['<br>', '</p>'], "\n", $message)),
                    'disable_web_page_preview' => true,
                ]);
                return $fallback->successful();
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Gagal mengirim Telegram alert: '.$e->getMessage());
            return false;
        }
    }

    /**
     * Alert: Pesanan Baru Masuk.
     */
    public function alertNewOrder(Transaction $transaction): bool
    {
        $totalFmt = number_format((float) $transaction->total, 0, ',', '.');
        $site = SiteSetting::get('site_name') ?? 'CAPTSHOP';
        $item = $transaction->productItem?->name ?? 'Produk';
        $channel = strtoupper((string) ($transaction->payment_channel ?? 'PG'));

        $text = "🛒 <b>[PESANAN BARU MASUK]</b>\n"
              . "Platform: <b>{$site}</b>\n"
              . "Invoice: <code>{$transaction->invoice_id}</code>\n"
              . "Item: <b>{$item}</b>\n"
              . "Total: <b>Rp {$totalFmt}</b>\n"
              . "Metode: {$channel}\n"
              . "Customer: {$transaction->customer_phone}\n"
              . "Status: ⏳ Menunggu Pembayaran\n"
              . "Waktu: " . now()->format('d/m/Y H:i:s') . " WIB";

        return $this->send($text);
    }

    /**
     * Alert: Pembayaran Diterima / Dana Masuk.
     */
    public function alertOrderPaid(Transaction $transaction): bool
    {
        $totalFmt = number_format((float) $transaction->total, 0, ',', '.');
        $site = SiteSetting::get('site_name') ?? 'CAPTSHOP';
        $item = $transaction->productItem?->name ?? 'Produk';
        $channel = strtoupper((string) ($transaction->payment_channel ?? 'PG'));

        $text = "✅ <b>[PEMBAYARAN DITERIMA]</b>\n"
              . "Platform: <b>{$site}</b>\n"
              . "Invoice: <code>{$transaction->invoice_id}</code>\n"
              . "Item: <b>{$item}</b>\n"
              . "Nominal: <b>Rp {$totalFmt}</b>\n"
              . "Metode: <b>{$channel}</b>\n"
              . "Customer: {$transaction->customer_phone}\n"
              . "Status: ⚡ Sedang Diproses Supplier\n"
              . "Waktu: " . now()->format('d/m/Y H:i:s') . " WIB";

        return $this->send($text);
    }

    /**
     * Alert 1: Saldo supplier menipis (< Rp 100.000).
     */
    public function alertLowBalance(float $balance, string $supplier = 'DigiFlazz'): bool
    {
        $balanceFmt = number_format($balance, 0, ',', '.');
        $site = SiteSetting::get('site_name') ?? 'CAPTSHOP';

        $text = "⚠️ <b>[ALERT SALDO MENIPIS]</b>\n"
              . "Platform: <b>{$site}</b>\n"
              . "Supplier: <b>{$supplier}</b>\n"
              . "Sisa Saldo: <b>Rp {$balanceFmt}</b> (Di bawah batas Rp 100.000)\n"
              . "Waktu: " . now()->format('d/m/Y H:i:s') . " WIB\n\n"
              . "<i>Harap segera lakukan top-up deposit agar fulfillment tidak terhenti.</i>";

        return $this->send($text);
    }

    /**
     * Alert 2: Transaksi bernilai besar (> Rp 500.000).
     */
    public function alertHighValueOrder(Transaction $transaction): bool
    {
        $totalFmt = number_format((float) $transaction->total, 0, ',', '.');
        $site = SiteSetting::get('site_name') ?? 'CAPTSHOP';
        $item = $transaction->productItem?->name ?? 'Produk';
        $channel = strtoupper((string) ($transaction->payment_channel ?? 'PG'));

        $text = "💰 <b>[TRANSAKSI BESAR]</b>\n"
              . "Platform: <b>{$site}</b>\n"
              . "Invoice: <code>{$transaction->invoice_id}</code>\n"
              . "Item: <b>{$item}</b>\n"
              . "Nominal: <b>Rp {$totalFmt}</b>\n"
              . "Metode: <b>{$channel}</b>\n"
              . "Customer: {$transaction->customer_phone}\n"
              . "Waktu: " . now()->format('d/m/Y H:i:s') . " WIB";

        return $this->send($text);
    }

    /**
     * Alert 3: Kegagalan fulfillment supplier pada transaksi live.
     */
    public function alertFulfillmentFailed(Transaction $transaction, string $error): bool
    {
        $site = SiteSetting::get('site_name') ?? 'CAPTSHOP';
        $item = $transaction->productItem?->name ?? 'Produk';

        $text = "🚨 <b>[FULFILLMENT GAGAL]</b>\n"
              . "Platform: <b>{$site}</b>\n"
              . "Invoice: <code>{$transaction->invoice_id}</code>\n"
              . "Item: <b>{$item}</b>\n"
              . "Error Supplier: <code>{$error}</code>\n"
              . "Status: Membutuhkan pengecekan manual admin.\n"
              . "Waktu: " . now()->format('d/m/Y H:i:s') . " WIB";

        return $this->send($text);
    }
}

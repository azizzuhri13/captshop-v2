<?php

namespace App\Services;

use App\Models\Transaction;
use App\Services\Notification\NotificationService;
use App\Services\Supplier\SupplierException;
use App\Services\Supplier\SupplierInterface;
use Illuminate\Support\Facades\Log;

class FulfillmentService
{
    public function __construct(
        protected SupplierInterface $supplier,
        protected NotificationService $notifications,
    ) {}

    /**
     * Proses fulfillment pesanan yang sudah dibayar: kirim notifikasi paid,
     * panggil supplier top-up, simpan response, lalu kirim notifikasi
     * ke pelanggan.
     */
    public function fulfill(Transaction $transaction): Transaction
    {
        if (! in_array($transaction->status, ['paid', 'processing'], true)) {
            return $transaction;
        }

        if ($transaction->processed_at === null) {
            $this->notify(fn () => $this->notifications->paymentReceived($transaction), $transaction, 'paid');
        }

        $transaction->forceFill(['status' => 'processing', 'processed_at' => now()])->save();

        try {
            $result = $this->supplier->topup($transaction, $transaction->customer_data ?? []);

            $status = $result['status'] === 'completed' ? 'completed' : 'processing';

            $transaction->forceFill([
                'status' => $status,
                'supplier_ref' => $result['supplier_ref'] ?? null,
                'supplier_sn' => $result['supplier_sn'] ?? null,
                'supplier_response' => json_encode($result['raw'] ?? $result),
                'completed_at' => $status === 'completed' ? now() : null,
            ])->save();

            if ($status === 'completed') {
                $this->notify(fn () => $this->notifications->orderCompleted($transaction), $transaction, 'completed');
            }
        } catch (SupplierException $e) {
            Log::error('Fulfillment gagal untuk '.$transaction->invoice_id.': '.$e->getMessage());

            $transaction->forceFill([
                'status' => 'processing',
                'supplier_response' => json_encode(['error' => $e->getMessage()]),
            ])->save();
        }

        return $transaction;
    }

    /**
     * Kirim notifikasi; kegagalan channel notifikasi tidak boleh
     * menggagalkan proses fulfillment.
     */
    protected function notify(callable $notifier, Transaction $transaction, string $event): void
    {
        try {
            $notifier();
        } catch (\Throwable $e) {
            Log::error('Gagal mengirim notifikasi '.$event.' untuk '.$transaction->invoice_id.': '.$e->getMessage());
        }
    }
}

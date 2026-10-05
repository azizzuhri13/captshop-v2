<?php

namespace App\Services\Supplier;

use App\Models\Transaction;

interface SupplierInterface
{
    /**
     * Nama supplier (mis. digiflazz, apigames).
     */
    public function supplierName(): string;

    /**
     * Eksekusi fulfillment top-up/pulsa ke supplier.
     *
     * @param  array<string, string>  $data  Data pelanggan sesuai input_fields
     *                                       produk (user_id, zone_id, dst).
     * @return array<string, mixed> Response supplier (ref, sn, status, dll).
     *
     * @throws SupplierException
     */
    public function topup(Transaction $transaction, array $data): array;

    /**
     * Cek saldo supplier.
     *
     * @return array<string, mixed> ['balance' => float, 'message' => string]
     *
     * @throws SupplierException
     */
    public function checkBalance(): array;
}

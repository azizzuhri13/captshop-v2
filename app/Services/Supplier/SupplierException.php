<?php

namespace App\Services\Supplier;

use RuntimeException;

class SupplierException extends RuntimeException
{
    public function __construct(string $message, protected bool $retryable = true)
    {
        parent::__construct($message);
    }

    /**
     * Kegagalan saat memanggil supplier (HTTP/API). Default boleh dicoba ulang,
     * kecuali supplier menyatakan transaksi gagal secara final.
     */
    public static function requestFailed(string $message, bool $retryable = true): self
    {
        return new self($message, $retryable);
    }

    /**
     * Kredensial belum disetel: percuma diulang, harus diperbaiki admin.
     */
    public static function notConfigured(): self
    {
        return new self('Kredensial supplier belum dikonfigurasi.', false);
    }

    /**
     * Produk/order belum siap dikirim ke supplier (SKU tidak terpetakan,
     * tujuan kosong, supplier tidak dikenal, harga beli melebihi harga jual).
     * Tidak boleh di-retry otomatis; harus tindakan admin.
     */
    public static function mappingError(string $message): self
    {
        return new self($message, false);
    }

    /**
     * Supplier menyatakan transaksi gagal secara final (mis. rc 02 / "Transaksi Gagal").
     */
    public static function terminalFailure(string $message): self
    {
        return new self($message, false);
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}

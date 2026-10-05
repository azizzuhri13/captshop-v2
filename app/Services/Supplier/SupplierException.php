<?php

namespace App\Services\Supplier;

use RuntimeException;

class SupplierException extends RuntimeException
{
    public static function requestFailed(string $message): self
    {
        return new self($message);
    }

    public static function notConfigured(): self
    {
        return new self('Kredensial supplier belum dikonfigurasi.');
    }
}

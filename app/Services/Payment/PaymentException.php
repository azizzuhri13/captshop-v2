<?php

namespace App\Services\Payment;

use RuntimeException;

class PaymentException extends RuntimeException
{
    public static function requestFailed(string $message): self
    {
        return new self($message);
    }

    public static function notConfigured(): self
    {
        return new self('Kredensial payment gateway belum dikonfigurasi.');
    }
}

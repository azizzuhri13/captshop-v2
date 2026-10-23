<?php

namespace App\Services\Supplier;

use RuntimeException;

/**
 * Dilempar saat pricelist tidak bisa dipakai sama sekali: API gagal DAN
 * tidak ada cache lokal yang bisa dibaca. Command sinkronisasi harus
 * berhenti tanpa menyentuh data katalog (fail-closed).
 */
class PricelistUnavailableException extends RuntimeException
{
}

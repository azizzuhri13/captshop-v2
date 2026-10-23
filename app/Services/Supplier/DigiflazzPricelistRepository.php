<?php

namespace App\Services\Supplier;

use App\Support\Credentials;
use App\Support\DigiflazzCatalog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sumber tunggal daftar harga (pricelist) DigiFlazz.
 *
 * Aturan penting:
 *  - Cache ditulis bersama metadata `fetched_at`, bukan sekadar array harga.
 *    Tanpa metadata, konsumen tidak bisa tahu cache itu baru atau berumur
 *    berhari-hari.
 *  - Fallback ke cache HANYA menyediakan data; keputusan memakainya ada di
 *    pemanggil lewat `PricelistSnapshot::isFresh()`. Dengan begitu data basi
 *    tidak pernah otomatis dipakai untuk menaikkan/menonaktifkan produk.
 *  - Cache disimpan di storage/app (ikut ter-backup dan tidak ikut dibersihkan
 *    oleh proses lain), bukan di direktori sementara.
 */
class DigiflazzPricelistRepository
{
    public const CACHE_VERSION = 1;

    private string $cachePath;

    public function __construct(?string $cachePath = null)
    {
        $this->cachePath = ($cachePath === null || $cachePath === '') ? DigiflazzCatalog::path() : $cachePath;
    }

    public function cachePath(): string
    {
        return $this->cachePath;
    }

    public function maxAgeMinutes(): int
    {
        return DigiflazzCatalog::ttlMinutes();
    }

    /**
     * Ambil pricelist: coba API dulu, lalu jatuh ke cache bila API tidak bisa dipakai.
     *
     * @throws PricelistUnavailableException bila API gagal dan cache tidak ada/rusak
     */
    public function fetch(DigiflazzClient $client, bool $forceCache = false): PricelistSnapshot
    {
        $failure = null;

        if (! $forceCache) {
            try {
                $items = $this->requestLive($client);
                if ($items !== []) {
                    $this->store($items);

                    return PricelistSnapshot::live($items);
                }
                $failure = 'respons API kosong';
            } catch (Throwable $e) {
                $failure = $e->getMessage();
                Log::warning('Pricelist DigiFlazz: API gagal, mencoba cache lokal.', [
                    'error' => $failure,
                ]);
            }
        } else {
            $failure = 'mode --force-cache: API tidak dipanggil';
        }

        $cached = $this->fromCache($failure);

        if ($cached === null) {
            throw new PricelistUnavailableException(
                'Pricelist DigiFlazz tidak tersedia dan cache lokal tidak bisa dipakai'
                .($failure ? " ({$failure})" : '').'.'
            );
        }

        return $cached;
    }

    /**
     * Panggil /price-list dan kembalikan daftar item. RC 83 (rate limit) dan
     * respons non-list diperlakukan sebagai kegagalan supaya pemanggil bisa
     * memutuskan lewat jalur cache + TTL.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws SupplierException|PricelistUnavailableException
     */
    private function requestLive(DigiflazzClient $client): array
    {
        $username = (string) Credentials::get('digiflazz_username', config('supplier.digiflazz.username'));
        $apiKey = (string) Credentials::get('digiflazz_api_key', config('supplier.digiflazz.api_key'));

        $res = $client->post('/price-list', [
            'cmd' => 'prepaid',
            'username' => $username,
            'sign' => md5($username.$apiKey.'pricelist'),
        ]);

        $data = $res['data'] ?? null;

        if (! is_array($data) || $data === []) {
            throw new PricelistUnavailableException('Pricelist DigiFlazz mengembalikan data kosong/tidak valid.');
        }

        // Respons error DigiFlazz berbentuk asosiatif {rc, message}, sedangkan
        // pricelist sukses berbentuk list item. RC 83 = rate limit.
        if (! array_is_list($data)) {
            $rc = (string) ($data['rc'] ?? '');
            $message = (string) ($data['message'] ?? 'struktur tidak dikenali');

            throw new PricelistUnavailableException(
                $rc === '83'
                    ? 'Pricelist DigiFlazz terkena rate limit (RC 83).'
                    : "Pricelist DigiFlazz ditolak (rc {$rc}): {$message}"
            );
        }

        return $data;
    }

    /**
     * Simpan cache dengan metadata kesegaran. Penulisan atomik: file sementara
     * lalu rename, supaya proses yang membaca di tengah penulisan tidak
     * menemukan JSON terpotong.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function store(array $items): void
    {
        $payload = [
            'version' => self::CACHE_VERSION,
            'source' => PricelistSnapshot::SOURCE_LIVE,
            'fetched_at' => CarbonImmutable::now()->toIso8601String(),
            'count' => count($items),
            'items' => array_values($items),
        ];

        try {
            $directory = dirname($this->cachePath);
            File::ensureDirectoryExists($directory);

            $tmp = $this->cachePath.'.tmp';
            File::put($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            File::move($tmp, $this->cachePath);
        } catch (Throwable $e) {
            // Gagal menulis cache tidak boleh menggagalkan sinkronisasi yang
            // datanya sudah valid; cukup catat agar kelihatan di log.
            Log::warning('Pricelist DigiFlazz: gagal menulis cache.', [
                'path' => $this->cachePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Baca cache. Mendukung format baru (envelope berisi fetched_at) dan format
     * lama (array polos) — format lama memakai mtime file sebagai perkiraan
     * waktu ambil dan ditandai `legacyCache`.
     */
    public function fromCache(?string $failureReason = null): ?PricelistSnapshot
    {
        foreach ([$this->cachePath] as $path) {
            if (! File::exists($path)) {
                continue;
            }

            try {
                $decoded = json_decode((string) File::get($path), true);
            } catch (Throwable $e) {
                Log::warning('Pricelist DigiFlazz: cache tidak bisa dibaca.', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! is_array($decoded) || $decoded === []) {
                continue;
            }

            $isEnvelope = isset($decoded['items']) && is_array($decoded['items']);
            $items = $isEnvelope ? $decoded['items'] : $decoded;

            if ($items === [] || ! array_is_list($items)) {
                continue;
            }

            $fetchedAt = $isEnvelope
                ? $this->parseFetchedAt($decoded['fetched_at'] ?? null, $path)
                : CarbonImmutable::createFromTimestamp((int) File::lastModified($path));

            return new PricelistSnapshot(
                array_values($items),
                $fetchedAt,
                PricelistSnapshot::SOURCE_CACHE,
                legacyCache: ! $isEnvelope,
                fallbackReason: $failureReason,
            );
        }

        return null;
    }


    private function parseFetchedAt(mixed $value, string $path): CarbonInterface
    {
        if (is_string($value) && $value !== '') {
            try {
                return CarbonImmutable::parse($value);
            } catch (Throwable) {
                // jatuh ke mtime di bawah
            }
        }

        // Tanpa timestamp yang bisa dipercaya, anggap setua file cache-nya.
        return CarbonImmutable::createFromTimestamp((int) File::lastModified($path));
    }

    /**
     * Catat sinkronisasi sukses terakhir supaya umur data bisa dilaporkan
     * walau command berikutnya gagal.
     *
     * @param  array<string, mixed>  $stats
     */
    public function recordSuccess(PricelistSnapshot $snapshot, array $stats = []): void
    {
        $payload = array_merge($stats, [
            'synced_at' => CarbonImmutable::now()->toIso8601String(),
            'source' => $snapshot->source,
            'data_fetched_at' => $snapshot->fetchedAt->toIso8601String(),
            'data_age_minutes' => $snapshot->ageMinutes(),
            'pricelist_items' => $snapshot->count(),
        ]);

        try {
            File::ensureDirectoryExists(dirname($this->successPath()));
            File::put($this->successPath(), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $e) {
            Log::warning('Pricelist DigiFlazz: gagal menulis penanda sukses.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastSuccess(): ?array
    {
        try {
            if (! File::exists($this->successPath())) {
                return null;
            }

            $decoded = json_decode((string) File::get($this->successPath()), true);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function successPath(): string
    {
        return dirname($this->cachePath).'/last-success.json';
    }
}

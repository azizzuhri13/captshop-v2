<?php

namespace App\Console\Commands;

use App\Models\NetflixAccount;
use Illuminate\Console\Command;

class ImportNetflixAccounts extends Command
{
    protected $signature = 'netflix:import-csv {path : Path ke file CSV}';

    protected $description = 'Import akun Netflix dari file CSV ke database internal';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! file_exists($path)) {
            $this->error("File tidak ditemukan: {$path}");
            return 1;
        }

        $handle = fopen($path, 'r');
        if (! $handle) {
            $this->error("Gagal membuka file: {$path}");
            return 1;
        }

        $count = 0;
        $rowNum = 0;

        while (($row = fgetcsv($handle, 1000, ',')) !== false) {
            $rowNum++;

            // Jika satu kolom tapi dipisahkan titik koma atau pipe
            if (count($row) === 1 && str_contains($row[0], ';')) {
                $row = explode(';', $row[0]);
            } elseif (count($row) === 1 && str_contains($row[0], '|')) {
                $row = explode('|', $row[0]);
            }

            // Skip header jika ada kata 'email'
            if ($rowNum === 1 && strtolower(trim($row[0] ?? '')) === 'email') {
                continue;
            }

            $email = trim($row[0] ?? '');
            $password = trim($row[1] ?? '');
            $profile = trim($row[2] ?? '');
            $pin = trim($row[3] ?? '');
            $notes = trim($row[4] ?? '');

            if (empty($email) || empty($password)) {
                continue;
            }

            NetflixAccount::create([
                'email' => $email,
                'password' => $password,
                'profile_name' => $profile ?: null,
                'pin' => $pin ?: null,
                'status' => 'ready',
                'notes' => $notes ?: null,
            ]);

            $count++;
        }

        fclose($handle);

        $this->info("Berhasil mengimpor {$count} akun Netflix ke status 'ready'!");
        return 0;
    }
}

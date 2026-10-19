<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DisneyAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DisneyAccountController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');
        $search = $request->query('q');

        $accounts = DisneyAccount::query()
            ->with('transaction:id,invoice_id')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($w) use ($search) {
                    $w->where('email', 'like', "%{$search}%")
                        ->orWhere('profile_name', 'like', "%{$search}%")
                        ->orWhere('notes', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $stats = [
            'total' => DisneyAccount::count(),
            'ready' => DisneyAccount::where('status', 'ready')->count(),
            'sold' => DisneyAccount::where('status', 'sold')->count(),
        ];

        return view('admin.disney-accounts.index', compact('accounts', 'stats'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:150'],
            'profile_name' => ['nullable', 'string', 'max:100'],
            'pin' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        DisneyAccount::create($validated + ['status' => 'ready']);

        return redirect()->route('admin.disney-accounts.index')
            ->with('success', 'Akun Disney+ berhasil ditambahkan ke stok ready.');
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $file = $request->file('csv_file');
        $handle = fopen($file->getRealPath(), 'r');

        if (! $handle) {
            return back()->with('error', 'Gagal membaca file CSV.');
        }

        $count = 0;
        $rowNum = 0;

        while (($row = fgetcsv($handle, 1000, ',')) !== false) {
            $rowNum++;

            if (count($row) === 1 && str_contains($row[0], ';')) {
                $row = explode(';', $row[0]);
            } elseif (count($row) === 1 && str_contains($row[0], '|')) {
                $row = explode('|', $row[0]);
            }

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

            DisneyAccount::create([
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

        return redirect()->route('admin.disney-accounts.index')
            ->with('success', "Berhasil mengimpor {$count} akun Disney+ dari CSV ke stok ready.");
    }

    public function update(Request $request, DisneyAccount $disneyAccount): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:150'],
            'profile_name' => ['nullable', 'string', 'max:100'],
            'pin' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string', 'max:500'],
            'status' => ['required', 'in:ready,sold'],
        ]);

        $disneyAccount->update($validated);

        return redirect()->route('admin.disney-accounts.index')
            ->with('success', 'Akun Disney+ berhasil diperbarui.');
    }

    public function destroy(DisneyAccount $disneyAccount): RedirectResponse
    {
        if ($disneyAccount->status === 'sold') {
            return back()->with('error', 'Akun yang sudah terjual tidak dapat dihapus untuk menjaga riwayat invoice.');
        }

        $disneyAccount->delete();

        return redirect()->route('admin.disney-accounts.index')
            ->with('success', 'Akun berhasil dihapus dari stok.');
    }
}

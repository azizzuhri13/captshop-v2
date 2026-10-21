<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CapcutAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CapcutAccountController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');
        $search = $request->query('q');

        $accounts = CapcutAccount::query()
            ->with('transaction:id,invoice_id')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($w) use ($search) {
                    $w->where('email', 'like', "%{$search}%")
                        ->orWhere('notes', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $stats = [
            'total' => CapcutAccount::count(),
            'ready' => CapcutAccount::where('status', 'ready')->count(),
            'sold' => CapcutAccount::where('status', 'sold')->count(),
        ];

        return view('admin.capcut-accounts.index', compact('accounts', 'stats'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        CapcutAccount::create($validated + ['status' => 'ready', 'channel' => 'web']);

        return redirect()->route('admin.capcut-accounts.index')
            ->with('success', 'Akun CapCut berhasil ditambahkan ke stok ready.');
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
            $notes = trim($row[2] ?? '');

            if (empty($email) || empty($password)) {
                continue;
            }

            CapcutAccount::create([
                'email' => $email,
                'password' => $password,
                'status' => 'ready',
                'channel' => 'web',
                'notes' => $notes ?: null,
            ]);

            $count++;
        }

        fclose($handle);

        return redirect()->route('admin.capcut-accounts.index')
            ->with('success', "{$count} akun CapCut berhasil di-import ke stok ready.");
    }

    public function update(Request $request, CapcutAccount $capcutAccount): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:150'],
            'status' => ['required', 'in:ready,sold'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $capcutAccount->update($validated);

        return redirect()->route('admin.capcut-accounts.index')
            ->with('success', 'Akun CapCut berhasil diperbarui.');
    }

    public function destroy(CapcutAccount $capcutAccount): RedirectResponse
    {
        if ($capcutAccount->status === 'sold') {
            return back()->with('error', 'Akun yang sudah terjual tidak dapat dihapus demi integritas riwayat transaksi.');
        }

        $capcutAccount->delete();

        return redirect()->route('admin.capcut-accounts.index')
            ->with('success', 'Akun CapCut berhasil dihapus dari stok.');
    }
}

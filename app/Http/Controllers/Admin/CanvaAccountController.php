<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CanvaAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CanvaAccountController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');
        $search = $request->query('q');

        $accounts = CanvaAccount::query()
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
            'total' => CanvaAccount::count(),
            'ready' => CanvaAccount::where('status', 'ready')->count(),
            'sold' => CanvaAccount::where('status', 'sold')->count(),
        ];

        return view('admin.canva-accounts.index', compact('accounts', 'stats'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        CanvaAccount::create($validated + ['status' => 'ready']);

        return redirect()->route('admin.canva-accounts.index')
            ->with('success', 'Akun Canva berhasil ditambahkan ke stok ready.');
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

            CanvaAccount::create([
                'email' => $email,
                'password' => $password,
                'status' => 'ready',
                'notes' => $notes ?: null,
            ]);

            $count++;
        }

        fclose($handle);

        return redirect()->route('admin.canva-accounts.index')
            ->with('success', "Berhasil mengimpor {$count} akun Canva dari CSV ke stok ready.");
    }

    public function update(Request $request, CanvaAccount $canvaAccount): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
            'status' => ['required', 'in:ready,sold'],
        ]);

        $canvaAccount->update($validated);

        return redirect()->route('admin.canva-accounts.index')
            ->with('success', 'Akun Canva berhasil diperbarui.');
    }

    public function destroy(CanvaAccount $canvaAccount): RedirectResponse
    {
        if ($canvaAccount->status === 'sold') {
            return back()->with('error', 'Akun yang sudah terjual tidak dapat dihapus untuk menjaga riwayat invoice.');
        }

        $canvaAccount->delete();

        return redirect()->route('admin.canva-accounts.index')
            ->with('success', 'Akun berhasil dihapus dari stok.');
    }
}

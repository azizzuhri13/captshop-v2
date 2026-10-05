<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrackController extends Controller
{
    /**
     * Form cek transaksi.
     */
    public function index(): View
    {
        return view('frontend.track', ['transactions' => null, 'q' => null]);
    }

    /**
     * Cari transaksi berdasarkan Invoice ID atau nomor HP.
     */
    public function search(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:4', 'max:100'],
        ]);

        $q = trim($validated['q']);

        $transactions = Transaction::query()
            ->where(function ($query) use ($q) {
                $query->where('invoice_id', 'like', "{$q}%")
                    ->orWhere('customer_phone', $q);
            })
            ->with(['productItem.product'])
            ->latest()
            ->take(20)
            ->get();

        return view('frontend.track', compact('transactions', 'q'));
    }
}

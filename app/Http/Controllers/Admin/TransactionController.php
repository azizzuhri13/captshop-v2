<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\FulfillmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TransactionController extends Controller
{
    public function index(Request $request): View
    {
        $transactions = Transaction::query()
            ->with('productItem.product:id,name')
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->input('q'), function ($query, $q) {
                $query->where(fn ($where) => $where
                    ->where('invoice_id', 'like', "%{$q}%")
                    ->orWhere('customer_phone', 'like', "%{$q}%"));
            })
            ->when($request->input('payment_channel'), fn ($query, $channel) => $query->where('payment_channel', $channel))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $statusCounts = Transaction::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return view('admin.transactions.index', compact('transactions', 'statusCounts'));
    }

    public function show(Transaction $transaction): View
    {
        $transaction->load('productItem.product.category');

        return view('admin.transactions.show', compact('transaction'));
    }

    /**
     * Ulangi fulfillment ke supplier (mis. transaksi stuck di processing).
     *
     * Admin boleh mengulang order yang sudah ditahan guard (status failed +
     * needs_manual_review) — mis. setelah SKU dipetakan ulang atau restock —
     * karena tindakan ini eksplisit dari manusia, bukan retry otomatis.
     */
    public function retryFulfillment(Transaction $transaction, FulfillmentService $fulfillment): RedirectResponse
    {
        $isRetryable = in_array($transaction->status, ['paid', 'processing'], true)
            || ($transaction->status === 'failed' && $transaction->needs_manual_review);

        if (! $isRetryable) {
            return back()->with('error', 'Retry fulfillment hanya untuk transaksi berstatus paid/processing, atau transaksi gagal yang ditahan untuk tindakan admin.');
        }

        $oldStatus = $transaction->status;

        $fulfillment->retryManually($transaction);

        $newStatus = $transaction->fresh()->status;

        AuditLog::record(auth('admin')->user(), 'retry_fulfillment', Transaction::class, $transaction->id, ['status' => $oldStatus], ['status' => $newStatus]);

        return back()->with('success', 'Fulfillment dijalankan ulang. Status terbaru: '.$newStatus);
    }

    /**
     * Tandai transaksi selesai secara manual (mis. supplier sukses via CS).
     */
    public function markCompleted(Transaction $transaction): RedirectResponse
    {
        if (! in_array($transaction->status, ['paid', 'processing', 'failed'], true)) {
            return back()->with('error', 'Transaksi ini tidak bisa ditandai selesai.');
        }

        $oldStatus = $transaction->status;

        $transaction->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
        ])->save();

        AuditLog::record(auth('admin')->user(), 'mark_completed', Transaction::class, $transaction->id, ['status' => $oldStatus], ['status' => 'completed']);

        return back()->with('success', 'Transaksi ditandai sebagai selesai.');
    }

    /**
     * Ekspor daftar transaksi (filter aktif) sebagai CSV.
     */
    public function export(Request $request)
    {
        $query = Transaction::query()
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('q'), fn ($q, $search) => $q->where('invoice_id', 'like', "%{$search}%"))
            ->latest();

        $filename = 'transactions-'.now()->format('Ymd-His').'.csv';

        AuditLog::record(auth('admin')->user(), 'export', Transaction::class, null, null, ['filter_status' => $request->input('status'), 'filter_q' => $request->input('q')]);

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        $columns = ['invoice_id', 'status', 'total', 'profit', 'customer_phone', 'payment_channel', 'created_at'];

        $callback = function () use ($query, $columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);

            $query->chunk(500, function ($rows) use ($out, $columns) {
                foreach ($rows as $row) {
                    fputcsv($out, array_map(fn ($column) => (string) $row->{$column}, $columns));
                }
            });

            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }
}

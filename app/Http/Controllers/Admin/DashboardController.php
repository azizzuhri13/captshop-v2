<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Ringkasan statistik bisnis untuk dashboard admin.
     */
    public function index(): View
    {
        $today = Transaction::query()
            ->whereDate('created_at', today())
            ->selectRaw("
                COUNT(*) as total_count,
                COALESCE(SUM(CASE WHEN status IN ('paid','processing','completed') THEN total ELSE 0 END), 0) as revenue,
                COALESCE(SUM(CASE WHEN status IN ('paid','processing','completed') THEN profit ELSE 0 END), 0) as profit,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count
            ")
            ->first();

        $month = Transaction::query()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->selectRaw("
                COUNT(*) as total_count,
                COALESCE(SUM(CASE WHEN status IN ('paid','processing','completed') THEN total ELSE 0 END), 0) as revenue
            ")
            ->first();

        // Tren 7 hari terakhir (untuk grafik sederhana).
        $trend = Transaction::query()
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->whereBetween('status', ['paid', 'completed'])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count, COALESCE(SUM(total),0) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy(fn ($row) => $row->date->format('Y-m-d'));

        $trendSeries = collect(range(6, 0))->map(function ($daysAgo) use ($trend) {
            $date = now()->subDays($daysAgo)->format('Y-m-d');

            return [
                'date' => now()->subDays($daysAgo)->translatedFormat('d M'),
                'count' => (int) ($trend[$date]->count ?? 0),
                'revenue' => (float) ($trend[$date]->revenue ?? 0),
            ];
        });

        $topProducts = Transaction::query()
            ->selectRaw('product_item_id, COUNT(*) as total, COALESCE(SUM(total),0) as revenue')
            ->whereBetween('status', ['paid', 'completed'])
            ->with('productItem.product:id,name', 'productItem:id,name,product_id')
            ->groupBy('product_item_id')
            ->orderByDesc('total')
            ->take(5)
            ->get();

        $recentTransactions = Transaction::query()
            ->with('productItem.product:id,name')
            ->latest()
            ->take(8)
            ->get();

        return view('admin.dashboard', [
            'today' => $today,
            'month' => $month,
            'trendSeries' => $trendSeries,
            'topProducts' => $topProducts,
            'recentTransactions' => $recentTransactions,
        ]);
    }
}

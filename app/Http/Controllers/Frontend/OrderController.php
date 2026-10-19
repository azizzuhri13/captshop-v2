<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderRequest;
use App\Services\Notification\NotificationService;
use App\Services\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orders,
        protected NotificationService $notifications,
    ) {}

    /**
     * Submit form order: validasi via OrderRequest, lalu delegasi ke OrderService.
     */
    public function store(OrderRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['customer_data'] = array_filter(
            array_map(fn ($value) => trim((string) $value), $data['customer_data']),
            fn ($value) => $value !== ''
        );

        try {
            $transaction = $this->orders->place($data);
        } catch (\InvalidArgumentException $e) {
            return back()
                ->withInput()
                ->withErrors(['order' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('Gagal memproses pesanan: '.$e->getMessage());

            return back()
                ->withInput()
                ->withErrors(['order' => 'Terjadi kesalahan saat memproses pesanan. Silakan coba lagi.']);
        }

        $this->notifications->orderCreated($transaction);

        // Tandai di session browser pemesan agar dapat melihat kredensial akun
        session()->put('order_placed_'.$transaction->invoice_id, true);

        return redirect()
            ->route('invoice.show', $transaction->invoice_id)
            ->with('success', 'Pesanan berhasil dibuat! Silakan selesaikan pembayaran.');
    }
}

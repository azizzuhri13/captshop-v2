<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PaymentChannel;
use App\Support\PaymentGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentChannelController extends Controller
{
    public function index(): View
    {
        // Kanal Tripay di-seed nonaktif dan hanya bisa dipakai setelah admin
        // mengaktifkannya, jadi keduanya harus terlihat di panel. Judul grup
        // diberi nama gateway agar channel berkode sama tidak tampak ganda.
        $channels = PaymentChannel::query()
            ->whereIn('gateway', PaymentGateway::SUPPORTED)
            ->orderBy('gateway')
            ->orderBy('group')
            ->orderBy('sort_order')
            ->get()
            // Baris lama ber-kode provider (mis. BCAVA) tidak boleh tampil:
            // checkout memakai kode internal (BCA), jadi baris itu tidak bisa
            // diaktifkan dan hanya membingungkan admin.
            ->filter(fn (PaymentChannel $channel) => $this->isManageable($channel))
            ->groupBy(fn (PaymentChannel $channel) => ucfirst($channel->gateway).' — '.$channel->group);

        return view('admin.payment-channels.index', compact('channels'));
    }

    public function edit(PaymentChannel $paymentChannel): View
    {
        $this->assertManageable($paymentChannel);

        return view('admin.payment-channels.edit', compact('paymentChannel'));
    }

    public function update(Request $request, PaymentChannel $paymentChannel): RedirectResponse
    {
        $this->assertManageable($paymentChannel);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'group' => ['required', 'string', 'max:50'],
            'flat_fee' => ['required', 'numeric', 'min:0'],
            'percent_fee' => ['required', 'numeric', 'min:0', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ]);

        $validated['is_active'] = $request->boolean('is_active');

        $old = $paymentChannel->only(array_keys($validated));

        $paymentChannel->update($validated);

        AuditLog::record(auth('admin')->user(), 'update', PaymentChannel::class, $paymentChannel->id, $old, $paymentChannel->only(array_keys($validated)));

        return redirect()->route('admin.payment-channels.index')->with('success', 'Channel pembayaran berhasil diperbarui.');
    }

    /**
     * Toggle aktif/nonaktif channel dari daftar.
     */
    public function toggle(PaymentChannel $paymentChannel): RedirectResponse
    {
        $this->assertManageable($paymentChannel);
        $old = ['is_active' => $paymentChannel->is_active];

        $paymentChannel->update(['is_active' => ! $paymentChannel->is_active]);

        AuditLog::record(auth('admin')->user(), 'toggle', PaymentChannel::class, $paymentChannel->id, $old, ['is_active' => $paymentChannel->fresh()->is_active]);

        $state = $paymentChannel->fresh()->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return back()->with('success', 'Channel '.$paymentChannel->name.' berhasil '.$state.'.');
    }

    /**
     * Hanya channel gateway yang didukung yang boleh diubah dari panel.
     *
     * Baris Tripay yang kodenya tidak ada di peta resmi config/payment.php
     * juga ditolak: checkout akan menolaknya, jadi mengaktifkannya hanya
     * menampilkan metode bayar yang pasti gagal.
     */
    protected function assertManageable(PaymentChannel $channel): void
    {
        abort_unless(in_array($channel->gateway, PaymentGateway::SUPPORTED, true), 404);

        if ($channel->gateway !== PaymentGateway::TRIPAY) {
            return;
        }

        abort_unless(
            $this->isManageable($channel),
            422,
            'Kode channel Tripay tidak terdaftar di konfigurasi pembayaran.'
        );
    }

    /**
     * Baris Tripay hanya boleh dikelola bila kodenya kunci peta resmi. Baris
     * peninggalan integrasi lama memakai kode provider (BCAVA) dan tidak akan
     * pernah cocok dengan kode internal yang dikirim checkout.
     */
    protected function isManageable(PaymentChannel $channel): bool
    {
        if (! in_array($channel->gateway, PaymentGateway::SUPPORTED, true)) {
            return false;
        }

        if ($channel->gateway !== PaymentGateway::TRIPAY) {
            return true;
        }

        $map = config('payment.tripay.channels', []);

        return is_array($map) && array_key_exists((string) $channel->code, $map);
    }
}

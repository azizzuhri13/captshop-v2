<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PaymentChannel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentChannelController extends Controller
{
    public function index(): View
    {
        $channels = PaymentChannel::query()
            ->orderBy('group')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('group');

        return view('admin.payment-channels.index', compact('channels'));
    }

    public function edit(PaymentChannel $paymentChannel): View
    {
        return view('admin.payment-channels.edit', compact('paymentChannel'));
    }

    public function update(Request $request, PaymentChannel $paymentChannel): RedirectResponse
    {
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
        $old = ['is_active' => $paymentChannel->is_active];

        $paymentChannel->update(['is_active' => ! $paymentChannel->is_active]);

        AuditLog::record(auth('admin')->user(), 'toggle', PaymentChannel::class, $paymentChannel->id, $old, ['is_active' => $paymentChannel->fresh()->is_active]);

        $state = $paymentChannel->fresh()->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return back()->with('success', 'Channel '.$paymentChannel->name.' berhasil '.$state.'.');
    }
}

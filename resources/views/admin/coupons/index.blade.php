@extends('layouts.admin')

@section('title', 'Kupon')
@section('page-title', 'Kupon Diskon')

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <p class="text-sm text-slate-500">{{ $coupons->total() }} kupon</p>
        <a href="{{ route('admin.coupons.create') }}" class="btn-primary !py-2 text-sm">+ Kupon Baru</a>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-800/40">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-800 text-xs uppercase text-slate-500">
                    <th class="p-3">Kode</th>
                    <th class="p-3">Diskon</th>
                    <th class="p-3">Min. Belanja</th>
                    <th class="p-3">Pemakaian</th>
                    <th class="p-3">Masa Berlaku</th>
                    <th class="p-3">Status</th>
                    <th class="p-3 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($coupons as $coupon)
                    <tr class="border-b border-slate-800/60 last:border-0">
                        <td class="p-3 font-mono font-bold text-primary-light">{{ strtoupper($coupon->code) }}</td>
                        <td class="p-3 font-medium text-slate-200">
                            @if ($coupon->discount_type === 'percentage')
                                {{ rtrim(rtrim(number_format($coupon->discount_value, 2), '0'), ',') }}%
                            @else
                                Rp {{ number_format((float) $coupon->discount_value, 0, ',', '.') }}
                            @endif
                        </td>
                        <td class="p-3 text-slate-400">{{ $coupon->min_purchase > 0 ? 'Rp '.number_format((float) $coupon->min_purchase, 0, ',', '.') : '—' }}</td>
                        <td class="p-3 text-slate-400">
                            {{ $coupon->used_count }} / {{ $coupon->max_usage > 0 ? $coupon->max_usage : '∞' }}
                        </td>
                        <td class="p-3 text-xs text-slate-400">
                            {{ $coupon->start_at?->format('d M y') ?? '∞' }} → {{ $coupon->end_at?->format('d M y') ?? '∞' }}
                        </td>
                        <td class="p-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $coupon->is_active ? 'bg-emerald-500/15 text-emerald-400' : 'bg-slate-500/15 text-slate-400' }}">
                                {{ $coupon->is_active ? 'Aktif' : 'Nonaktif' }}
                            </span>
                        </td>
                        <td class="p-3">
                            <div class="flex justify-end gap-2">
                                <a href="{{ route('admin.coupons.edit', $coupon) }}" class="rounded-lg border border-slate-700 px-3 py-1.5 text-xs font-medium text-slate-300 transition hover:border-primary hover:text-white">Edit</a>
                                <form action="{{ route('admin.coupons.destroy', $coupon) }}" method="POST" onsubmit="return confirm('Hapus kupon ini?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rounded-lg border border-red-500/40 px-3 py-1.5 text-xs font-medium text-red-400 transition hover:bg-red-500/10">Hapus</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-6 text-center text-slate-500">Belum ada kupon.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $coupons->links() }}
@endsection

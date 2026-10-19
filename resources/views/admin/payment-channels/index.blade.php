@extends('layouts.admin')

@section('title', 'Channel Pembayaran')
@section('page-title', 'Channel Pembayaran')

@section('content')
    <p class="mb-4 text-sm text-slate-500">Aktifkan/nonaktifkan channel dan atur biaya layanan per channel.</p>

    <div class="space-y-6">
        @foreach ($channels as $group => $groupChannels)
            <section class="overflow-hidden rounded-xl border border-slate-800 bg-slate-800/40">
                <header class="border-b border-slate-800 bg-slate-800/60 px-4 py-3">
                    <h2 class="font-heading text-sm font-bold text-slate-200">{{ $group }}</h2>
                </header>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-800 text-xs uppercase text-slate-500 whitespace-nowrap">
                                <th class="p-3">Channel</th>
                                <th class="p-3">Kode</th>
                                <th class="p-3">Fee Flat</th>
                                <th class="p-3">Fee %</th>
                                <th class="p-3">Status</th>
                                <th class="p-3 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            @foreach ($groupChannels as $channel)
                                <tr class="{{ $channel->is_active ? '' : 'opacity-50' }} whitespace-nowrap">
                                    <td class="p-3 font-medium text-slate-200">{{ $channel->name }}</td>
                                    <td class="p-3 font-mono text-xs text-slate-400">{{ $channel->gateway }}:{{ $channel->code }}</td>
                                    <td class="p-3 text-slate-400">Rp {{ number_format((float) $channel->flat_fee, 0, ',', '.') }}</td>
                                    <td class="p-3 text-slate-400">{{ rtrim(rtrim(number_format($channel->percent_fee, 2), '0'), ',') }}%</td>
                                    <td class="p-3">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $channel->is_active ? 'bg-emerald-500/15 text-emerald-400' : 'bg-slate-500/15 text-slate-400' }}">
                                            {{ $channel->is_active ? 'Aktif' : 'Nonaktif' }}
                                        </span>
                                    </td>
                                    <td class="p-3">
                                        <div class="flex justify-end gap-2">
                                            <a href="{{ route('admin.payment-channels.edit', $channel) }}" class="rounded-lg border border-slate-700 px-3 py-1.5 text-xs font-medium text-slate-300 transition hover:border-primary hover:text-white">Edit Fee</a>
                                            <form action="{{ route('admin.payment-channels.toggle', $channel) }}" method="POST">
                                                @csrf
                                                <button type="submit" class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition {{ $channel->is_active ? 'border-amber-500/50 text-amber-400 hover:bg-amber-500/10' : 'border-secondary/50 text-secondary hover:bg-secondary/10' }}">
                                                    {{ $channel->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endforeach
    </div>
@endsection

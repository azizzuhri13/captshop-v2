@extends('layouts.admin')

@section('title', 'Edit Channel')
@section('page-title', 'Edit Channel Pembayaran')

@section('content')
    <div class="max-w-xl rounded-xl border border-slate-800 bg-slate-800/40 p-5">
        <form action="{{ route('admin.payment-channels.update', $paymentChannel) }}" method="POST" class="space-y-4">
            @csrf
            @method('PUT')

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-300">Nama Tampilan *</label>
                    <input type="text" name="name" value="{{ old('name', $paymentChannel->name) }}" required class="input-dark">
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-300">Grup *</label>
                    <select name="group" class="input-dark">
                        @foreach (['Virtual Account', 'E-Wallet', 'Retail', 'QRIS'] as $group)
                            <option value="{{ $group }}" @selected(old('group', $paymentChannel->group) === $group)>{{ $group }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-300">Fee Flat (Rp) *</label>
                    <input type="number" name="flat_fee" step="0.01" min="0" value="{{ old('flat_fee', $paymentChannel->flat_fee) }}" required class="input-dark" placeholder="1000">
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-300">Fee Persen (%) *</label>
                    <input type="number" name="percent_fee" step="0.01" min="0" max="100" value="{{ old('percent_fee', $paymentChannel->percent_fee) }}" required class="input-dark" placeholder="0.70">
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-300">Urutan</label>
                    <input type="number" name="sort_order" min="0" max="9999" value="{{ old('sort_order', $paymentChannel->sort_order) }}" class="input-dark">
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-300">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $paymentChannel->is_active)) class="rounded border-slate-600 bg-slate-800 text-primary focus:ring-primary">
                Aktif
            </label>

            @error('name')<p class="text-xs text-red-400">{{ $message }}</p>@enderror
            @error('percent_fee')<p class="text-xs text-red-400">{{ $message }}</p>@enderror

            <div class="flex gap-2 pt-2">
                <button type="submit" class="btn-primary">Simpan Perubahan</button>
                <a href="{{ route('admin.payment-channels.index') }}" class="rounded-lg border border-slate-700 px-4 py-3 text-sm font-medium text-slate-300 transition hover:border-slate-500">Batal</a>
            </div>
        </form>
    </div>
@endsection

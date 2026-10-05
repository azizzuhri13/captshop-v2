@php
    $coupon = $coupon ?? null;
@endphp

<form
    action="{{ $coupon ? route('admin.coupons.update', $coupon) : route('admin.coupons.store') }}"
    method="POST"
    class="space-y-4"
>
    @csrf
    @if ($coupon)
        @method('PUT')
    @endif

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Kode Kupon *</label>
            <input type="text" name="code" value="{{ old('code', $coupon?->code) }}" required class="input-dark uppercase" placeholder="MERDEKA10">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Tipe Diskon *</label>
            <select name="discount_type" class="input-dark">
                <option value="percentage" @selected(old('discount_type', $coupon?->discount_type ?? 'percentage') === 'percentage')>Persentase (%)</option>
                <option value="nominal" @selected(old('discount_type', $coupon?->discount_type) === 'nominal')>Nominal (Rp)</option>
            </select>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Nilai Diskon *</label>
            <input type="number" name="discount_value" step="0.01" min="0" value="{{ old('discount_value', $coupon?->discount_value) }}" required class="input-dark" placeholder="10 (untuk 10%) atau 5000 (Rp)">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Minimal Pembelian</label>
            <input type="number" name="min_purchase" step="0.01" min="0" value="{{ old('min_purchase', $coupon?->min_purchase ?? 0) }}" class="input-dark" placeholder="0 = tanpa syarat">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Kuota Pemakaian</label>
            <input type="number" name="max_usage" min="0" value="{{ old('max_usage', $coupon?->max_usage ?? 0) }}" class="input-dark" placeholder="0 = tanpa batas">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Sudah Dipakai</label>
            <input type="number" name="used_count" min="0" value="{{ old('used_count', $coupon?->used_count ?? 0) }}" class="input-dark" disabled>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Berlaku Mulai</label>
            <input type="datetime-local" name="start_at" value="{{ old('start_at', $coupon?->start_at?->format('Y-m-d\TH:i')) }}" class="input-dark">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Berlaku Sampai</label>
            <input type="datetime-local" name="end_at" value="{{ old('end_at', $coupon?->end_at?->format('Y-m-d\TH:i')) }}" class="input-dark">
        </div>
    </div>

    <label class="flex items-center gap-2 text-sm text-slate-300">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $coupon?->is_active ?? true)) class="rounded border-slate-600 bg-slate-800 text-primary focus:ring-primary">
        Aktif
    </label>

    @error('code')<p class="text-xs text-red-400">{{ $message }}</p>@enderror
    @error('discount_value')<p class="text-xs text-red-400">{{ $message }}</p>@enderror

    <div class="flex gap-2 pt-2">
        <button type="submit" class="btn-primary">{{ $coupon ? 'Simpan Perubahan' : 'Buat Kupon' }}</button>
        <a href="{{ route('admin.coupons.index') }}" class="rounded-lg border border-slate-700 px-4 py-3 text-sm font-medium text-slate-300 transition hover:border-slate-500">Batal</a>
    </div>
</form>

@extends('layouts.admin')

@section('title', 'Stok Akun CapCut')
@section('page-title', 'Stok Akun CapCut')

@section('content')
<div x-data="{ 
    modalOpen: false, 
    importOpen: false, 
    editOpen: false, 
    editData: { id: null, email: '', password: '', status: 'ready', notes: '', url: '' },
    openEdit(account, url) {
        this.editData = {
            id: account.id,
            email: account.email,
            password: account.password,
            status: account.status,
            notes: account.notes || '',
            url: url
        };
        this.editOpen = true;
    }
}">
    {{-- Ringkasan Statistik --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-slate-800 bg-slate-800/40 p-4">
            <p class="text-xs font-medium text-slate-400">Total Akun Terdaftar</p>
            <p class="mt-1 font-heading text-2xl font-bold text-slate-100">{{ $stats['total'] }}</p>
        </div>
        <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4">
            <p class="text-xs font-medium text-emerald-400">Stok Siap Jual (Ready)</p>
            <p class="mt-1 font-heading text-2xl font-bold text-emerald-300">{{ $stats['ready'] }}</p>
        </div>
        <div class="rounded-xl border border-slate-800 bg-slate-800/40 p-4">
            <p class="text-xs font-medium text-slate-400">Akun Terjual (Sold)</p>
            <p class="mt-1 font-heading text-2xl font-bold text-slate-300">{{ $stats['sold'] }}</p>
        </div>
    </div>

    {{-- Bar Aksi & Filter --}}
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.capcut-accounts.index') }}" class="rounded-lg px-3 py-1.5 text-xs font-medium transition {{ !request('status') ? 'bg-primary text-white' : 'border border-slate-700 bg-slate-800 text-slate-300 hover:text-white' }}">
                Semua ({{ $stats['total'] }})
            </a>
            <a href="{{ route('admin.capcut-accounts.index', ['status' => 'ready']) }}" class="rounded-lg px-3 py-1.5 text-xs font-medium transition {{ request('status') === 'ready' ? 'bg-emerald-600 text-white' : 'border border-slate-700 bg-slate-800 text-slate-300 hover:text-white' }}">
                Ready ({{ $stats['ready'] }})
            </a>
            <a href="{{ route('admin.capcut-accounts.index', ['status' => 'sold']) }}" class="rounded-lg px-3 py-1.5 text-xs font-medium transition {{ request('status') === 'sold' ? 'bg-slate-700 text-white' : 'border border-slate-700 bg-slate-800 text-slate-300 hover:text-white' }}">
                Sold ({{ $stats['sold'] }})
            </a>
        </div>

        <div class="flex items-center gap-2">
            <button type="button" @click="importOpen = true" class="rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-xs font-medium text-slate-200 transition hover:border-slate-500 hover:text-white">
                📥 Import CSV
            </button>
            <button type="button" @click="modalOpen = true" class="btn-primary !py-2 text-xs font-bold">
                + Tambah Akun
            </button>
        </div>
    </div>

    {{-- Form Pencarian --}}
    <form method="GET" action="{{ route('admin.capcut-accounts.index') }}" class="mb-4 flex gap-2">
        @if(request('status'))
            <input type="hidden" name="status" value="{{ request('status') }}">
        @endif
        <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari email atau catatan..." class="input-dark text-xs !py-2 max-w-sm">
        <button type="submit" class="rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-xs font-medium text-slate-300 hover:text-white">
            Cari
        </button>
        @if(request('q'))
            <a href="{{ route('admin.capcut-accounts.index', array_filter(['status' => request('status')])) }}" class="rounded-lg border border-slate-700 px-3 py-2 text-xs font-medium text-slate-400 hover:text-slate-200">
                Reset
            </a>
        @endif
    </form>

    {{-- Tabel Data --}}
    <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-800/40">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-800 text-xs uppercase text-slate-500">
                    <th class="p-3">Email / ID Akun</th>
                    <th class="p-3">Password</th>
                    <th class="p-3">Status</th>
                    <th class="p-3">Transaksi Terkait</th>
                    <th class="p-3">Catatan</th>
                    <th class="p-3 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($accounts as $acc)
                    <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/20">
                        <td class="p-3 font-mono font-medium text-slate-200">{{ $acc->email }}</td>
                        <td class="p-3 font-mono text-slate-300" x-data="{ show: false }">
                            <div class="inline-flex items-center gap-1.5">
                                <span x-show="!show" class="text-xs tracking-widest text-slate-500 select-none">••••••••</span>
                                <span x-show="show" x-cloak class="text-xs text-slate-200 select-all">{{ $acc->password }}</span>
                                <button
                                    type="button"
                                    @click="show = !show"
                                    class="rounded p-1 text-slate-500 hover:bg-slate-700/50 hover:text-slate-300 transition"
                                    :title="show ? 'Sembunyikan password' : 'Tampilkan password'"
                                >
                                    <svg x-show="!show" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                    <svg x-show="show" x-cloak class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" />
                                    </svg>
                                </button>
                            </div>
                        </td>
                        <td class="p-3">
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $acc->status === 'ready' ? 'bg-emerald-500/15 text-emerald-400' : 'bg-slate-500/15 text-slate-400' }}">
                                {{ strtoupper($acc->status) }}
                            </span>
                        </td>
                        <td class="p-3 text-xs">
                            @if ($acc->channel === 'telegram')
                                <div class="inline-flex items-center gap-1 rounded bg-sky-500/15 border border-sky-500/30 px-2 py-0.5 text-[11px] font-semibold text-sky-400">
                                    <span>🤖 Telegram</span>
                                </div>
                                <p class="text-[10px] text-slate-500 mt-0.5">{{ $acc->sold_at?->translatedFormat('d M Y H:i') }}</p>
                            @elseif ($acc->transaction || $acc->channel === 'web')
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <span class="inline-flex items-center gap-1 rounded bg-emerald-500/15 border border-emerald-500/30 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-400">
                                        🌐 Web
                                    </span>
                                    @if ($acc->transaction)
                                        <a href="{{ route('admin.transactions.show', $acc->transaction) }}" class="font-mono text-primary-light hover:underline">
                                            {{ $acc->transaction->invoice_id }}
                                        </a>
                                    @endif
                                </div>
                                <p class="text-[10px] text-slate-500 mt-0.5">{{ $acc->sold_at?->translatedFormat('d M Y H:i') }}</p>
                            @else
                                <span class="text-slate-600">—</span>
                            @endif
                        </td>
                        <td class="p-3 text-xs text-slate-400 max-w-xs truncate">{{ $acc->notes ?? '—' }}</td>
                        <td class="p-3 text-right">
                            <div class="inline-flex items-center gap-1.5">
                                <button type="button" 
                                        @click="openEdit({{ Js::from($acc) }}, '{{ route('admin.capcut-accounts.update', $acc) }}')" 
                                        class="rounded px-2 py-1 text-xs text-sky-400 hover:bg-sky-500/10 transition">
                                    Edit
                                </button>
                                @if ($acc->status === 'ready')
                                    <form action="{{ route('admin.capcut-accounts.destroy', $acc) }}" method="POST" onsubmit="return confirm('Hapus akun ini dari stok?');" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rounded px-2 py-1 text-xs text-red-400 hover:bg-red-500/10 transition">
                                            Hapus
                                        </button>
                                    </form>
                                @else
                                    <span class="text-xs text-slate-600">Terkunci</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="p-6 text-center text-slate-500">
                            Belum ada akun CapCut dalam database stok.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $accounts->links() }}
    </div>

    {{-- MODAL TAMBAH SATUAN --}}
    <div x-show="modalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm" style="display: none;">
        <div @click.away="modalOpen = false" class="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900 p-6 shadow-2xl">
            <h3 class="font-heading text-base font-bold text-slate-100 mb-4">Tambah Akun CapCut Satuan</h3>
            <form action="{{ route('admin.capcut-accounts.store') }}" method="POST" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-xs font-medium text-slate-400 mb-1">Email Akun CapCut *</label>
                    <input type="email" name="email" required placeholder="capcut@gmail.com" class="input-dark text-xs">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-400 mb-1">Password *</label>
                    <input type="text" name="password" required placeholder="Pass1234" class="input-dark text-xs">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-400 mb-1">Catatan Tambahan (Opsional)</label>
                    <input type="text" name="notes" placeholder="Garansi 30 hari / Batch 1" class="input-dark text-xs">
                </div>
                <div class="mt-5 flex justify-end gap-2 pt-2 border-t border-slate-800">
                    <button type="button" @click="modalOpen = false" class="rounded-lg border border-slate-700 px-3 py-2 text-xs font-medium text-slate-400 hover:text-white">Batal</button>
                    <button type="submit" class="btn-primary !py-2 text-xs font-bold">Simpan Akun</button>
                </div>
            </form>
        </div>
    </div>

    {{-- MODAL IMPORT CSV --}}
    <div x-show="importOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm" style="display: none;">
        <div @click.away="importOpen = false" class="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900 p-6 shadow-2xl">
            <h3 class="font-heading text-base font-bold text-slate-100 mb-2">Import Akun CapCut dari CSV</h3>
            <p class="text-xs text-slate-400 mb-4 leading-relaxed">
                Pilih file CSV dari perangkat Anda. Format kolom: <code class="text-primary-light">email, password, notes</code>. Header pada baris pertama otomatis dilewati jika memuat kata "email".
            </p>
            <form action="{{ route('admin.capcut-accounts.import') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-medium text-slate-400 mb-1.5">File CSV / TXT</label>
                    <input type="file" name="csv_file" accept=".csv,.txt" required class="block w-full text-xs text-slate-400 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-800 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-slate-200 hover:file:bg-slate-700">
                </div>
                <div class="mt-5 flex justify-end gap-2 pt-2 border-t border-slate-800">
                    <button type="button" @click="importOpen = false" class="rounded-lg border border-slate-700 px-3 py-2 text-xs font-medium text-slate-400 hover:text-white">Batal</button>
                    <button type="submit" class="btn-primary !py-2 text-xs font-bold">Upload & Import</button>
                </div>
            </form>
        </div>
    </div>

    {{-- MODAL EDIT AKUN --}}
    <div x-show="editOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm" style="display: none;">
        <div @click.away="editOpen = false" class="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900 p-6 shadow-2xl">
            <h3 class="font-heading text-base font-bold text-slate-100 mb-4">Edit Akun CapCut</h3>
            <form :action="editData.url" method="POST" class="space-y-3">
                @csrf
                @method('PUT')
                <div>
                    <label class="block text-xs font-medium text-slate-400 mb-1">Email Akun CapCut *</label>
                    <input type="email" name="email" x-model="editData.email" required class="input-dark text-xs">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-400 mb-1">Password *</label>
                    <input type="text" name="password" x-model="editData.password" required class="input-dark text-xs">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-400 mb-1">Status Akun</label>
                    <select name="status" x-model="editData.status" class="input-dark text-xs">
                        <option value="ready">READY (Siap Dijual)</option>
                        <option value="sold">SOLD (Sudah Terjual)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-400 mb-1">Catatan Tambahan (Opsional)</label>
                    <input type="text" name="notes" x-model="editData.notes" class="input-dark text-xs">
                </div>
                <div class="mt-5 flex justify-end gap-2 pt-2 border-t border-slate-800">
                    <button type="button" @click="editOpen = false" class="rounded-lg border border-slate-700 px-3 py-2 text-xs font-medium text-slate-400 hover:text-white">Batal</button>
                    <button type="submit" class="btn-primary !py-2 text-xs font-bold">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

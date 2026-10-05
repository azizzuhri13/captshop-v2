@extends('layouts.admin')

@section('title', 'Akun Admin — Admin Panel')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="font-heading text-xl font-bold text-slate-100">Akun Admin</h1>
                <p class="mt-1 text-sm text-slate-500">Kelola akun dan role admin panel.</p>
            </div>
            <a href="{{ route('admin.accounts.create') }}" class="btn-primary">+ Tambah Admin</a>
        </div>

        <div class="overflow-x-auto rounded-2xl border border-slate-800 bg-slate-800/40">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-left text-xs uppercase tracking-wide text-slate-500">
                        <th class="px-4 py-3">Nama</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3">Role</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Dibuat</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($admins as $admin)
                        <tr class="border-b border-slate-800/60 last:border-0">
                            <td class="px-4 py-3 font-medium text-slate-100">
                                {{ $admin->name }}
                                @if ($admin->id === auth('admin')->id())
                                    <span class="ml-1 text-[11px] text-primary-light">(Anda)</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-400">{{ $admin->email }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold
                                    {{ $admin->role === 'super_admin' ? 'bg-violet-600/20 text-violet-300' : 'bg-slate-700/60 text-slate-300' }}">
                                    {{ $admin->role }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs font-semibold {{ $admin->is_active ? 'text-emerald-400' : 'text-red-400' }}">
                                    {{ $admin->is_active ? 'Aktif' : 'Nonaktif' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500">{{ $admin->created_at->format('d M Y') }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('admin.accounts.edit', $admin) }}" class="rounded-lg border border-slate-700 px-3 py-1.5 text-xs font-medium text-slate-300 transition hover:bg-slate-700">Edit</a>
                                    @if ($admin->id !== auth('admin')->id())
                                        <form action="{{ route('admin.accounts.destroy', $admin) }}" method="POST" onsubmit="return confirm('Hapus akun {{ $admin->email }}?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="rounded-lg border border-red-900/60 px-3 py-1.5 text-xs font-medium text-red-400 transition hover:bg-red-900/30">Hapus</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-slate-500">Belum ada akun admin.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $admins->links() }}
    </div>
@endsection

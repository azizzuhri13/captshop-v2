@extends('layouts.admin')

@section('title', 'Edit Admin — Admin Panel')

@section('content')
    <div class="mx-auto max-w-2xl space-y-6">
        <div>
            <h1 class="font-heading text-xl font-bold text-slate-100">Edit Akun Admin</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $admin->name }} — {{ $admin->email }}</p>
        </div>

        <form action="{{ route('admin.accounts.update', $admin) }}" method="POST" class="space-y-4 rounded-2xl border border-slate-800 bg-slate-800/40 p-6">
            @csrf
            @method('PUT')

            <div>
                <label for="name" class="mb-1.5 block text-sm font-medium text-slate-300">Nama</label>
                <input type="text" id="name" name="name" value="{{ old('name', $admin->name) }}" required class="input-dark">
                @error('name')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="email" class="mb-1.5 block text-sm font-medium text-slate-300">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email', $admin->email) }}" required class="input-dark">
                @error('email')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="password" class="mb-1.5 block text-sm font-medium text-slate-300">Password Baru</label>
                <input type="password" id="password" name="password" class="input-dark" autocomplete="new-password" placeholder="Kosongkan jika tidak diganti">
                @error('password')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="role" class="mb-1.5 block text-sm font-medium text-slate-300">Role</label>
                <select id="role" name="role" required class="input-dark">
                    @foreach (['super_admin' => 'Super Admin (semua akses)', 'admin' => 'Admin (katalog + transaksi)', 'operator' => 'Operator (produk & kupon)', 'cs' => 'CS (lihat transaksi)'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('role', $admin->role) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('role')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
            </div>

            <label class="flex items-center gap-3 text-sm text-slate-300">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $admin->is_active)) class="rounded border-slate-600 bg-slate-800 text-primary focus:ring-primary">
                Akun aktif (boleh login)
            </label>

            @if ($admin->id === auth('admin')->id())
                <p class="rounded-lg border border-amber-900/50 bg-amber-900/20 px-3 py-2 text-xs text-amber-300">
                    ⚠️ Ini akun Anda sendiri. Menonaktifkan atau menurunkan role bisa mengunci Anda keluar dari panel.
                </p>
            @endif

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="btn-primary">Simpan Perubahan</button>
                <a href="{{ route('admin.accounts.index') }}" class="btn-secondary">Batal</a>
            </div>
        </form>
    </div>
@endsection

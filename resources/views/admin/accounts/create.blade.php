@extends('layouts.admin')

@section('title', 'Tambah Admin — Admin Panel')

@section('content')
    <div class="mx-auto max-w-2xl space-y-6">
        <div>
            <h1 class="font-heading text-xl font-bold text-slate-100">Tambah Akun Admin</h1>
            <p class="mt-1 text-sm text-slate-500">Buat akun baru untuk mengakses admin panel.</p>
        </div>

        <form action="{{ route('admin.accounts.store') }}" method="POST" class="space-y-4 rounded-2xl border border-slate-800 bg-slate-800/40 p-6">
            @csrf

            <div>
                <label for="name" class="mb-1.5 block text-sm font-medium text-slate-300">Nama</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required class="input-dark" placeholder="Nama lengkap">
                @error('name')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="email" class="mb-1.5 block text-sm font-medium text-slate-300">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required class="input-dark" placeholder="nama@captshop.com">
                @error('email')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="password" class="mb-1.5 block text-sm font-medium text-slate-300">Password</label>
                <input type="password" id="password" name="password" required class="input-dark" autocomplete="new-password" placeholder="Minimal 8 karakter">
                @error('password')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="role" class="mb-1.5 block text-sm font-medium text-slate-300">Role</label>
                <select id="role" name="role" required class="input-dark">
                    @foreach (['super_admin' => 'Super Admin (semua akses)', 'admin' => 'Admin (katalog + transaksi)', 'operator' => 'Operator (produk & kupon)', 'cs' => 'CS (lihat transaksi)'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('role') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('role')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
            </div>

            <label class="flex items-center gap-3 text-sm text-slate-300">
                <input type="checkbox" name="is_active" value="1" checked class="rounded border-slate-600 bg-slate-800 text-primary focus:ring-primary">
                Akun aktif (boleh login)
            </label>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="btn-primary">Simpan</button>
                <a href="{{ route('admin.accounts.index') }}" class="btn-secondary">Batal</a>
            </div>
        </form>
    </div>
@endsection

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAccountController extends Controller
{
    public function index(): View
    {
        $admins = Admin::query()->orderBy('name')->paginate(20);

        return view('admin.accounts.index', compact('admins'));
    }

    public function create(): View
    {
        return view('admin.accounts.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $admin = Admin::create($validated);

        AuditLog::record(auth('admin')->user(), 'create', Admin::class, $admin->id, null, $this->redacted($admin));

        return redirect()->route('admin.accounts.index')->with('success', 'Akun admin berhasil dibuat.');
    }

    public function edit(Admin $account): View
    {
        return view('admin.accounts.edit', ['admin' => $account]);
    }

    public function update(Request $request, Admin $account): RedirectResponse
    {
        $validated = $this->validated($request, $account->id);

        // Password kosong = tidak diganti.
        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $old = $this->redacted($account);

        $account->update($validated);

        AuditLog::record(auth('admin')->user(), 'update', Admin::class, $account->id, $old, $this->redacted($account->fresh()));

        return redirect()->route('admin.accounts.index')->with('success', 'Akun admin berhasil diperbarui.');
    }

    public function destroy(Admin $account): RedirectResponse
    {
        if ($account->id === auth('admin')->id()) {
            return back()->with('error', 'Anda tidak bisa menghapus akun Anda sendiri.');
        }

        if ($account->isSuperAdmin() && Admin::query()->where('role', 'super_admin')->whereKeyNot($account->id)->doesntExist()) {
            return back()->with('error', 'Minimal harus ada satu akun super admin.');
        }

        $old = $this->redacted($account);

        $account->delete();

        AuditLog::record(auth('admin')->user(), 'delete', Admin::class, $account->id, $old, null);

        return redirect()->route('admin.accounts.index')->with('success', 'Akun admin berhasil dihapus.');
    }

    protected function validated(Request $request, ?int $ignoreId = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:admins,email'.($ignoreId ? ','.$ignoreId : '')],
            'password' => [$ignoreId ? 'nullable' : 'required', 'string', 'min:8'],
            'role' => ['required', 'in:super_admin,admin,operator,cs'],
            'is_active' => ['boolean'],
        ];

        $validated = $request->validate($rules);

        $validated['is_active'] = $request->boolean('is_active');

        return $validated;
    }

    /**
     * Representasi akun untuk audit log — password tidak pernah dicatat.
     *
     * @return array<string, mixed>
     */
    protected function redacted(Admin $admin): array
    {
        return [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->role,
            'is_active' => $admin->is_active,
        ];
    }
}

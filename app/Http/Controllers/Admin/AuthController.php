<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AuthController extends Controller
{
    /**
     * Tampilkan form login admin.
     */
    public function loginForm(): View
    {
        return view('admin.auth.login');
    }

    /**
     * Proses login admin (guard admin).
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');

        if (! auth('admin')->attempt($credentials, $remember)) {
            Log::warning('Percobaan login admin GAGAL', [
                'email' => $credentials['email'],
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 200),
            ]);
            AuditLog::record(null, 'login_failed', 'Admin', null, null, ['email' => $credentials['email']]);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Email atau password salah.']);
        }

        $admin = auth('admin')->user();

        if (! $admin->is_active) {
            auth('admin')->logout();

            Log::warning('Login admin ditolak: akun nonaktif', ['email' => $admin->email, 'ip' => $request->ip()]);
            AuditLog::record($admin, 'login_rejected_inactive', 'Admin', $admin->id, null, ['email' => $admin->email]);

            return redirect()->route('admin.login')->withErrors(['email' => 'Akun Anda dinonaktifkan.']);
        }

        $request->session()->regenerate();

        Log::info('Admin login: '.$admin->email);
        AuditLog::record($admin, 'login_success', 'Admin', $admin->id, null, ['email' => $admin->email]);

        return redirect()->intended(route('admin.dashboard'));
    }

    /**
     * Logout admin.
     */
    public function logout(Request $request): RedirectResponse
    {
        auth('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}

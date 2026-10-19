<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\User;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    /**
     * Tampilkan halaman login / register pengguna.
     */
    public function loginView(): View|RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            return redirect()->route('home');
        }

        return view('frontend.auth.login');
    }

    /**
     * Redirect ke halaman otentikasi Google OAuth.
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Tangani callback dari Google OAuth.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            Log::warning('Google OAuth dibatalkan pengguna atau gagal: ' . $request->get('error_description', $request->get('error')));
            return redirect()->route('login')->withErrors(['oauth' => 'Login dengan Google dibatalkan atau terjadi kesalahan.']);
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Exception $e) {
            Log::error('Gagal mengambil data user dari Google OAuth: ' . $e->getMessage());
            return redirect()->route('login')->withErrors(['oauth' => 'Tidak dapat terhubung ke Google. Silakan coba lagi.']);
        }

        $email = $googleUser->getEmail();
        $googleId = $googleUser->getId();
        $name = $googleUser->getName() ?: explode('@', $email)[0];
        $avatar = $googleUser->getAvatar();

        // Cari berdasarkan google_id terlebih dahulu, atau email
        $user = User::where('google_id', $googleId)->first();
        $isNewUser = false;

        if (! $user) {
            $user = User::where('email', $email)->first();

            if ($user) {
                // Tautkan google_id ke akun yang sudah ada
                $user->update([
                    'google_id' => $googleId,
                    'avatar' => $avatar ?? $user->avatar,
                ]);
            } else {
                // Registrasi pengguna baru
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'google_id' => $googleId,
                    'avatar' => $avatar,
                    'email_verified_at' => now(),
                ]);
                $isNewUser = true;
            }
        } else {
            // Update nama & avatar terbaru bila ada perubahan
            $user->update([
                'avatar' => $avatar ?? $user->avatar,
            ]);
        }

        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();

        $message = $isNewUser
            ? "Selamat datang di CAPTSHOP, {$user->name}! Akun Anda berhasil didaftarkan."
            : "Selamat datang kembali, {$user->name}!";

        return redirect()->intended(route('home'))->with('success', $message);
    }

    /**
     * Logout pengguna.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('info', 'Anda berhasil keluar dari akun.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Log;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class ResetPasswordController extends Controller
{
    public function showResetForm(Request $request)
    {
        $token = $request->token;
        $email = $request->email;

        if (!$token || !$email) {
            return view('auth.reset-password', [
                'token' => '',
                'email' => '',
                'error' => 'Link reset password tidak valid. Parameter token dan email wajib ada.'
            ]);
        }

        return view('auth.reset-password', [
            'token' => $token,
            'email' => $email,
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ], [
            'token.required' => 'Token reset password tidak ditemukan.',
            'email.required' => 'Alamat email wajib diisi.',
            'email.email' => 'Format alamat email tidak valid.',
            'password.required' => 'Password baru wajib diisi.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'password.min' => 'Password minimal harus 8 karakter.',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                DB::transaction(function () use ($user, $password) {
                    $user->forceFill([
                        'password' => Hash::make($password)
                    ])->setRememberToken(Str::random(60));

                    $user->save();

                    event(new PasswordReset($user));

                    Log::create([
                        'user_id'  => $user->id,
                        'activity' => 'Reset password',
                        'detail'   => $user->name . ' berhasil mereset password akun melalui link email.',
                    ]);
                });
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()
                ->route('password.reset.success')
                ->with('status', 'Password Anda berhasil direset! Silakan login dengan password baru.');
        }

        $errorMessage = match($status) {
            Password::INVALID_TOKEN => 'Token reset password tidak valid atau sudah kedaluwarsa.',
            Password::INVALID_USER => 'Tidak ditemukan akun dengan alamat email tersebut.',
            Password::RESET_THROTTLED => 'Terlalu banyak percobaan. Silakan tunggu beberapa menit sebelum mencoba lagi.',
            default => 'Terjadi kesalahan saat mereset password. Silakan coba lagi atau minta link reset yang baru.'
        };

        $errorMessage = $errorMessages[$status] ?? 'Terjadi kesalahan saat mereset password. Silakan coba lagi.';

        return back()->withErrors(['email' => $errorMessage])->withInput($request->only('email'));
    }
}

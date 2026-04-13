<?php

namespace App\Http\Controllers;

use App\Models\FcmToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
	public function login(Request $request)
	{
		$request->validate([
			'login'    => ['required', 'string'],
			'password' => ['required', 'string'],
		]);

		$loginValue = $request->input('login');
		$loginField = filter_var($loginValue, FILTER_VALIDATE_EMAIL)
			? 'email'
			: 'username';

		$user = User::where($loginField, $loginValue)->first();

		if (!$user) {
			return response()->json([
				'message' => 'Akun tidak ditemukan.',
			], 404);
		}

		if (!$user->is_active) {
			return response()->json([
				'message' => 'Akun Anda tidak aktif. Silakan hubungi owner / admin.',
			], 403);
		}

		if (!Hash::check($request->password, $user->password)) {
			return response()->json([
				'message' => 'Password salah.',
			], 401);
		}

		$token = $user->createToken('auth_token')->plainTextToken;

		$user->update([
			'current_token' => hash('sha256', $token),
		]);

		return response()->json([
			'message' => 'Login berhasil.',
			'token'   => $token,
			'user'    => [
				'id'       => $user->id,
				'name'     => $user->name,
				'email'    => $user->email,
				'phone'    => $user->phone,
				'username' => $user->username,
				'role'     => $user->role,
			],
		], 200);
	}

    public function refresh(Request $request)
    {
        $user = $request->user();

        
        $tokenModel = PersonalAccessToken::findToken($request->bearerToken());

        if ($user && $tokenModel) {
            
            $tokenModel->delete();

            
            $newToken = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'token' => $newToken,
                'message' => 'Token refreshed'
            ], 200);
        }

        
        return response()->json(['message' => 'Unauthorized'], 401);
    }

	public function logout(Request $request)
	{
		$user = $request->user();

		$deviceId = $request->header('X-Device-ID');

		if ($deviceId) {
			FcmToken::where('user_id', $user->id)
				->where('device_id', $deviceId)
        ->delete();
		}

        $token = PersonalAccessToken::findToken($request->bearerToken());

        if ($token) {
            $token->delete();
        }

		return response()->json([
			'message' => 'Logout berhasil.',
		], 200);
	}
}

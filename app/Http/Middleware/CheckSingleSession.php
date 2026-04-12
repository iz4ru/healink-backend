<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckSingleSession
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (!$token) return response()->json(['message' => 'Unauthorized'], 401);

        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        if (hash('sha256', $token) !== $user->current_token) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi aktif terdeteksi, akun ini sedang login di perangkat lain. Harap logout terlebih dahulu di perangkat tersebut.',
                'type' => 'SINGLE_SESSION_CONFLICT'
            ], 401);
        }

        return $next($request);
    }
}

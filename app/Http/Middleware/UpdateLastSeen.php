<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class UpdateLastSeen
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user) {
            $lastSeen = $user->last_seen ? Carbon::parse($user->last_seen) : null;
            $now = Carbon::now();

            if (!$lastSeen || $lastSeen->diffInMinutes($now) >= 1) {
                $user->update(['last_seen' => $now]);
            }
        }

        return $next($request);
    }
}

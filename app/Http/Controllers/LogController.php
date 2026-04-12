<?php

namespace App\Http\Controllers;

use App\Models\Log;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        if ($user->role !== 'owner') {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Hanya owner yang dapat melihat log.'
            ], 403);
        }

        $query = Log::with('user');

        if ($request->filled('search')) {
            $search = strtolower(trim($request->search));
            $query->where(function($q) use ($search) {
                $q->whereRaw('LOWER(activity) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(detail) LIKE ?', ["%{$search}%"]);
            });
        }

        $sort = $request->query('sort', 'newest');
        if ($sort === 'oldest') {
            $query->orderBy('created_at', 'asc');
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $perPage = $request->query('per_page', 10);
        $logs = $query->paginate($perPage);

        $logs = $logs->through(function($log) {
            $dateStr = Carbon::parse($log->created_at)
                            ->locale('id')
                            ->translatedFormat('l d/m/y, H:i');

            return [
                'user_role' => $log->user->role ?? null,
                'user_username' => $log->user->username ?? null,
                'user_name' => $log->user->name ?? null,
                'date'      => ucfirst($dateStr),
                'activity'  => $log->activity,
                'detail'    => $log->detail
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Berhasil mengambil log aktivitas',
            'data'    => $logs
        ], 200);
    }
}

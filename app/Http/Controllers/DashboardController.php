<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function indexAdmin(Request $request)
    {
        
        $tz = config('app.timezone', 'Asia/Jakarta');

        Carbon::setLocale('id');

        $now = Carbon::now()->setTimezone($tz);
        $onlineThreshold = $now->copy()->subMinutes(2);

        
        $todayStart = Carbon::now()->setTimezone($tz)->startOfDay();
        $todayEnd = Carbon::now()->setTimezone($tz)->endOfDay();
        $yesterdayStart = Carbon::now()->setTimezone($tz)->subDay()->startOfDay();
        $yesterdayEnd = Carbon::now()->setTimezone($tz)->subDay()->endOfDay();

        $todayStats = Transaction::where('status', 'sale')
            ->whereBetween('transaction_date', [$todayStart, $todayEnd])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(subtotal), 0) as total')
            ->first();

        $yesterdayStats = Transaction::where('status', 'sale')
            ->whereBetween('transaction_date', [$yesterdayStart, $yesterdayEnd])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(subtotal), 0) as total')
            ->first();

        $percentChange = $yesterdayStats->total > 0
            ? round((($todayStats->total - $yesterdayStats->total) / $yesterdayStats->total) * 100)
            : ($todayStats->count > 0 ? 100 : 0);

        
        $weeklyData = [];
        for ($i = 6; $i >= 0; $i--) {
            $dayStart = Carbon::now()->setTimezone($tz)->subDays($i)->startOfDay();
            $dayEnd = $dayStart->copy()->endOfDay();

            $dayTotal = Transaction::where('status', 'sale')
                ->whereBetween('transaction_date', [$dayStart, $dayEnd])
                ->sum('subtotal');

            $weeklyData[] = [
                'day' => $dayStart->isoFormat('ddd'),
                'value' => (float) $dayTotal,
            ];
        }

        
        
        $lowStock = Product::with('batches')
            ->whereNull('deleted_at')
            ->get()
            ->filter(fn($p) =>
                $p->batches->whereNull('deleted_at')->sum('stock') <= $p->min_stock &&
                $p->batches->whereNull('deleted_at')->sum('stock') > 0
            )
            ->count();

        
        $nearExpiry = ProductBatch::where('exp_date', '<=', Carbon::now()->setTimezone($tz)->addDays(30)->endOfDay())
            ->where('exp_date', '>=', Carbon::now()->setTimezone($tz)->startOfDay())
            ->where('stock', '>', 0)
            ->whereNull('deleted_at')
            ->count();

        
        $outOfStock = Product::with('batches')
            ->whereNull('deleted_at')
            ->get()
            ->filter(fn($p) => $p->batches->whereNull('deleted_at')->sum('stock') <= 0)
            ->count();

        
        $expired = ProductBatch::where('exp_date', '<', Carbon::now()->setTimezone($tz)->startOfDay())
            ->where('stock', '>', 0)
            ->whereNull('deleted_at')
            ->count();

        
        
        $activeCashiers = User::where('role', 'cashier')
            ->where('is_active', true)
            ->count();

        $onlineCashiers = User::where('role', 'cashier')
            ->where('is_active', true)
            ->where('last_seen', '>=', $onlineThreshold)
            ->get(['id', 'name', 'last_seen']);

        
        return response()->json([
            'success' => true,
            'data' => [
                'today' => [
                    'transaction_count' => $todayStats->count ?? 0,
                    'total_amount' => (float) ($todayStats->total ?? 0),
                    'percent_change' => $percentChange,
                ],
                'weekly_sales' => $weeklyData,
                'stock_alerts' => [
                    'low_stock' => $lowStock,
                    'near_expiry' => $nearExpiry,
                    'out_of_stock' => $outOfStock,
                    'expired' => $expired,
                ],
                'cashiers' => [
                    'active_count' => $activeCashiers,
                    'online_count' => $onlineCashiers->count(),
                    'online_list' => $onlineCashiers->map(fn($c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'last_seen' => Carbon::parse($c->last_seen)?->toIso8601String(),
                    ]),
                ],
            ],
        ]);
    }

    public function indexOwner(Request $request)
    {
        
        $tz = config('app.timezone', 'Asia/Jakarta');

        Carbon::setLocale('id');

        $now = Carbon::now()->setTimezone($tz);
        $onlineThreshold = $now->copy()->subMinutes(2);

        
        $todayStart = Carbon::now()->setTimezone($tz)->startOfDay();
        $todayEnd = Carbon::now()->setTimezone($tz)->endOfDay();
        $yesterdayStart = Carbon::now()->setTimezone($tz)->subDay()->startOfDay();
        $yesterdayEnd = Carbon::now()->setTimezone($tz)->subDay()->endOfDay();

        $todayStats = Transaction::where('status', 'sale')
            ->whereBetween('transaction_date', [$todayStart, $todayEnd])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(subtotal), 0) as total')
            ->first();

        $yesterdayStats = Transaction::where('status', 'sale')
            ->whereBetween('transaction_date', [$yesterdayStart, $yesterdayEnd])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(subtotal), 0) as total')
            ->first();

        $percentChange = $yesterdayStats->total > 0
            ? round((($todayStats->total - $yesterdayStats->total) / $yesterdayStats->total) * 100)
            : ($todayStats->count > 0 ? 100 : 0);

        
        $weeklyData = [];
        for ($i = 6; $i >= 0; $i--) {
            $dayStart = Carbon::now()->setTimezone($tz)->subDays($i)->startOfDay();
            $dayEnd = $dayStart->copy()->endOfDay();

            $dayTotal = Transaction::where('status', 'sale')
                ->whereBetween('transaction_date', [$dayStart, $dayEnd])
                ->sum('subtotal');

            $weeklyData[] = [
                'day' => $dayStart->isoFormat('ddd'),
                'value' => (float) $dayTotal,
            ];
        }

        
        
        $lowStock = Product::with('batches')
            ->whereNull('deleted_at')
            ->get()
            ->filter(fn($p) =>
                $p->batches->whereNull('deleted_at')->sum('stock') <= $p->min_stock &&
                $p->batches->whereNull('deleted_at')->sum('stock') > 0
            )
            ->count();

        
        $nearExpiry = ProductBatch::where('exp_date', '<=', Carbon::now()->setTimezone($tz)->addDays(30)->endOfDay())
            ->where('exp_date', '>=', Carbon::now()->setTimezone($tz)->startOfDay())
            ->where('stock', '>', 0)
            ->whereNull('deleted_at')
            ->count();

        
        $outOfStock = Product::with('batches')
            ->whereNull('deleted_at')
            ->get()
            ->filter(fn($p) => $p->batches->whereNull('deleted_at')->sum('stock') <= 0)
            ->count();

        
        $expired = ProductBatch::where('exp_date', '<', Carbon::now()->setTimezone($tz)->startOfDay())
            ->where('stock', '>', 0)
            ->whereNull('deleted_at')
            ->count();

        
        
        $activeUsers = User::whereIn('role', ['admin', 'cashier'])
            ->where('is_active', true)
            ->count();

        $onlineUsers = User::whereIn('role', ['admin', 'cashier'])
            ->where('is_active', true)
            ->where('last_seen', '>=', $onlineThreshold)
            ->get(['id', 'name', 'role', 'last_seen']);

        
        return response()->json([
            'success' => true,
            'data' => [
                'today' => [
                    'transaction_count' => $todayStats->count ?? 0,
                    'total_amount' => (float) ($todayStats->total ?? 0),
                    'percent_change' => $percentChange,
                ],
                'weekly_sales' => $weeklyData,
                'stock_alerts' => [
                    'low_stock' => $lowStock,
                    'near_expiry' => $nearExpiry,
                    'out_of_stock' => $outOfStock,
                    'expired' => $expired,
                ],
                'users' => [
                    'active_count' => $activeUsers,
                    'online_count' => $onlineUsers->count(),
                    'online_list' => $onlineUsers->map(fn($c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'role' => $c->role,
                        'last_seen' => Carbon::parse($c->last_seen)?->toIso8601String(),
                    ]),
                ],
            ],
        ]);
    }

    public function indexCashier(Request $request)
    {
        $user = Auth::user();
        $tz = config('app.timezone', 'Asia/Jakarta');

        Carbon::setLocale('id');

        
        $todayStart = Carbon::now()->setTimezone($tz)->startOfDay();
        $todayEnd = Carbon::now()->setTimezone($tz)->endOfDay();
        $yesterdayStart = Carbon::now()->setTimezone($tz)->subDay()->startOfDay();
        $yesterdayEnd = Carbon::now()->setTimezone($tz)->subDay()->endOfDay();

        $todayStats = Transaction::where('status', 'sale')
            ->where('user_id', $user->id) 
            ->whereBetween('transaction_date', [$todayStart, $todayEnd])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(subtotal), 0) as total')
            ->first();

        $yesterdayStats = Transaction::where('status', 'sale')
            ->where('user_id', $user->id)
            ->whereBetween('transaction_date', [$yesterdayStart, $yesterdayEnd])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(subtotal), 0) as total')
            ->first();

        $percentChange = $yesterdayStats->total > 0
            ? round((($todayStats->total - $yesterdayStats->total) / $yesterdayStats->total) * 100)
            : ($todayStats->count > 0 ? 100 : 0);

        
        $recentTransactions = Transaction::where('user_id', $user->id)
            ->orderBy('transaction_date', 'desc')
            ->take(5)
            ->get(['id', 'trx_no', 'subtotal', 'transaction_date', 'status']);

        
        $lowStock = Product::with('batches')
            ->whereNull('deleted_at')
            ->get()
            ->filter(fn($p) =>
                $p->batches->whereNull('deleted_at')->sum('stock') <= $p->min_stock &&
                $p->batches->whereNull('deleted_at')->sum('stock') > 0
            )
            ->count();

        $nearExpiry = ProductBatch::where('exp_date', '<=', Carbon::now()->setTimezone($tz)->addDays(30)->endOfDay())
            ->where('exp_date', '>=', Carbon::now()->setTimezone($tz)->startOfDay())
            ->where('stock', '>', 0)
            ->whereNull('deleted_at')
            ->count();

        $outOfStock = Product::with('batches')
            ->whereNull('deleted_at')
            ->get()
            ->filter(fn($p) => $p->batches->whereNull('deleted_at')->sum('stock') <= 0)
            ->count();

        $expired = ProductBatch::where('exp_date', '<', Carbon::now()->setTimezone($tz)->startOfDay())
            ->where('stock', '>', 0)
            ->whereNull('deleted_at')
            ->count();

        
        return response()->json([
            'success' => true,
            'data' => [
                'today' => [
                    'transaction_count' => $todayStats->count ?? 0,
                    'total_amount' => (float) ($todayStats->total ?? 0),
                    'percent_change' => $percentChange,
                ],
                'recent_transactions' => $recentTransactions->map(fn($t) => [
                    'id' => $t->id,
                    'trx_no' => $t->trx_no,
                    'subtotal' => (float) $t->subtotal,
                    'date' => $t->transaction_date,
                    'status' => $t->status,
                ]),
                'stock_alerts' => [
                    'low_stock' => $lowStock,
                    'near_expiry' => $nearExpiry,
                    'out_of_stock' => $outOfStock,
                    'expired' => $expired,
                ],
            ],
        ]);
    }
}

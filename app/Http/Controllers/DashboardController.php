<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function indexAdmin(Request $request)
    {
        // Set timezone konsisten
        $tz = config('app.timezone', 'Asia/Jakarta');

        Carbon::setLocale('id');

        // ─── 1. TODAY'S STATS ───
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

        // ─── 2. WEEKLY SALES (7 days, Mon-Sun) ───
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

        // ─── 3. STOCK ALERTS ───
        // Low stock: total batch stock <= min_stock AND > 0
        $lowStock = Product::with('batches')
            ->whereNull('deleted_at')
            ->get()
            ->filter(fn($p) =>
                $p->batches->whereNull('deleted_at')->sum('stock') <= $p->min_stock &&
                $p->batches->whereNull('deleted_at')->sum('stock') > 0
            )
            ->count();

        // Near expiry: batches expiring within 30 days, stock > 0
        $nearExpiry = ProductBatch::where('exp_date', '<=', Carbon::now()->setTimezone($tz)->addDays(30)->endOfDay())
            ->where('exp_date', '>=', Carbon::now()->setTimezone($tz)->startOfDay())
            ->where('stock', '>', 0)
            ->whereNull('deleted_at')
            ->count();

        // Out of stock: total batch stock = 0
        $outOfStock = Product::with('batches')
            ->whereNull('deleted_at')
            ->get()
            ->filter(fn($p) => $p->batches->whereNull('deleted_at')->sum('stock') <= 0)
            ->count();

        // Expired: exp_date < today, stock > 0
        $expired = ProductBatch::where('exp_date', '<', Carbon::now()->setTimezone($tz)->startOfDay())
            ->where('stock', '>', 0)
            ->whereNull('deleted_at')
            ->count();

        // ─── 4. CASHIER STATUS ───
        // Karena tidak ada last_seen, tampilkan semua cashier aktif sebagai "online"
        $activeCashiers = User::where('role', 'cashier')
            ->where('is_active', true)
            ->count();

        $cashierList = User::where('role', 'cashier')
            ->where('is_active', true)
            ->select('id', 'name')
            ->get()
            ->map(fn($u) => ['id' => $u->id, 'name' => $u->name]);

        // ─── RETURN UNIFIED RESPONSE ───
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
                    'online_list' => $cashierList,
                ],
            ],
        ]);
    }
}

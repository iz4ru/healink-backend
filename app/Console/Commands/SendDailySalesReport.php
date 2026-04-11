<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendDailySalesReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'report:daily-sales';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Kirim ringkasan penjualan harian ke Owner';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $today = Carbon::today('Asia/Jakarta');

        $transactions = Transaction::with('items.product')
            ->where('status', 'sale')
            ->whereDate('transaction_date', $today->toDateString())
            ->get();

        $totalRevenue      = $transactions->sum('total_amount');
        $totalTransactions = $transactions->count();

        $productSales = [];
        foreach ($transactions as $trx) {
            foreach ($trx->items as $item) {
                $name = $item->product_name;
                if (!isset($productSales[$name])) {
                    $productSales[$name] = 0;
                }
                $productSales[$name] += $item->qty;
            }
        }

        arsort($productSales);
        $topProducts = array_slice($productSales, 0, 3, true);

        $formattedDate    = $today->translatedFormat('d F Y');
        $formattedRevenue = 'Rp' . number_format($totalRevenue, 0, ',', '.');

        $title = '📊 Ringkasan Penjualan ' . $formattedDate;

        foreach ($topProducts as $name => $qty) {
            $this->info("  - {$name}: {$qty} pcs");
        }

        if ($totalTransactions === 0) {
            $body = "Tidak ada transaksi hari ini.";
        } else {
            $topProductLines = '';
            $rank = 1;
            foreach ($topProducts as $name => $qty) {
                $topProductLines .= "\n{$rank}. {$name} ({$qty} pcs)";
                $rank++;
            }

        $body = "Total: {$formattedRevenue} dari {$totalTransactions} transaksi.\n\n"
            . "Produk terlaris:\n"
            . trim($topProductLines);
        }

        $owners = User::where('role', 'owner')
            ->where('is_active', true)
            ->get();

        if ($owners->isEmpty()) {
            $this->info('Tidak ada Owner aktif untuk menerima laporan.');
            return Command::SUCCESS;
        }

        foreach ($owners as $owner) {
            NotificationService::sendToUser(
                $owner,
                $title,
                $body,
                'daily_sales_report'
            );
        }

        $this->info("Ringkasan penjualan berhasil dikirim ke {$owners->count()} Owner.");
        return Command::SUCCESS;
    }
}

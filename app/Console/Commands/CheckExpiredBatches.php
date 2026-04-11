<?php

namespace App\Console\Commands;

use App\Jobs\SendBatchExpiryNotificationJob;
use App\Models\Notification;
use App\Models\ProductBatch;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckExpiredBatches extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'batches:check-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Kirim notifikasi peringatan batch produk yang mendekati atau sudah expired';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $recipients = User::whereIn('role', ['admin', 'owner'])
            ->where('is_active', true)
            ->get();

        if ($recipients->isEmpty()) {
            $this->info('Tidak ada penerima notifikasi.');
            return Command::SUCCESS;
        }

        $today = Carbon::today(config('app.timezone'));

        $batches = ProductBatch::with(['product' => fn($q) => $q->select('id', 'product_name')])
            ->whereNull('product_batches.deleted_at')
            ->whereHas('product')
            ->where('stock', '>', 0)
            ->whereDate('exp_date', '<=', $today->copy()->addDays(30))
            ->get();

        if ($batches->isEmpty()) {
            $this->info('Tidak ada batch yang perlu diperingatkan.');
            return Command::SUCCESS;
        }

        $dispatchedCount = 0;
        $skippedCount = 0;

        foreach ($batches as $batch) {
            $expDate = Carbon::parse($batch->exp_date);
            $daysLeft = $today->diffInDays($expDate, false);
            $productName = $batch->product?->product_name ?? 'Produk tidak diketahui';
            $batchNumber = $batch->batch_number ?? 'NO-BATCH';

            if ($daysLeft < 0 || in_array($daysLeft, [30, 7, 1, 0])) {
            } else {
                continue;
            }

            [$type, $title, $body] = $this->resolveMessage($productName, $batchNumber, $daysLeft, $expDate);

            foreach ($recipients as $user) {
                $alreadySent = Notification::where('user_id', $user->id)
                    ->where('type', $type)
                    ->where('body', 'like', "%$batchNumber%")
                    ->whereDate('created_at', $today)
                    ->exists();

                if ($alreadySent) {
                    $skippedCount++;
                    continue;
                }

                SendBatchExpiryNotificationJob::dispatch(
                    $user,
                    $title,
                    $body,
                    $type,
                    $batchNumber
                );

                $dispatchedCount++;
            }
        }

        $this->info("$dispatchedCount job notifikasi berhasil dijadwalkan.");
        $this->info("$skippedCount notifikasi di-skip (sudah dikirim hari ini).");
        
        Log::info('CheckExpiredBatches completed', [
            'batches_found' => $batches->count(),
            'jobs_dispatched' => $dispatchedCount,
            'jobs_skipped' => $skippedCount,
        ]);
        
        return Command::SUCCESS;
    }

    private function resolveMessage(
        string $productName,
        string $batchNumber,
        int $daysLeft,
        Carbon $expDate
    ): array {
        $formattedDate = $expDate->translatedFormat('d F Y');

        if ($daysLeft < 0) {
            // Sudah kadaluarsa
            return [
                'batch_expired',
                '🚨 Produk Sudah Kadaluarsa!',
                "Produk $productName " . " (Batch: $batchNumber) " . " telah melewati tanggal expired pada $formattedDate. Segera tarik dari display!",
            ];
        }

        if ($daysLeft === 0) {
            // Kadaluarsa hari ini
            return [
                'batch_expired',
                '🚨 Produk Kadaluarsa Hari Ini!',
                "Produk $productName (Batch: $batchNumber) kadaluarsa hari ini ($formattedDate). Segera tarik dari display!",
            ];
        }

        if ($daysLeft === 1) {
            // Kadaluarsa besok
            return [
                'batch_expiring_tomorrow',
                '⚠️ Produk Kadaluarsa Besok!',
                "Produk $productName (Batch: $batchNumber) kadaluarsa besok ($formattedDate). Pertimbangkan untuk segera menjualnya.",
            ];
        }

        if ($daysLeft === 7) {
            // H-7
            return [
                'batch_expiring_soon_7',
                '⚠️ Peringatan Kadaluarsa 7 Hari',
                "Produk $productName (Batch: $batchNumber) akan kadaluarsa pada $formattedDate ($daysLeft hari lagi). Segera tindak lanjuti.",
            ];
        }

        // H-30
        return [
            'batch_expiring_soon_30',
            '📦 Peringatan Kadaluarsa 30 Hari',
            "Produk $productName (Batch: $batchNumber) akan kadaluarsa pada $formattedDate ($daysLeft hari lagi).",
        ];
    }
}

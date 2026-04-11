<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Exception\MessagingException;
use Throwable;

class SendBatchExpiryNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public User $user;
    public string $title;
    public string $body;
    public string $type;
    public string $batchNumber;

    public $timeout = 30;
    
    public $tries = 3;
    
    public $backoff = [1, 3, 10];

    /**
     * Create a new job instance.
     */
    public function __construct(User $user, string $title, string $body, string $type, string $batchNumber)
    {
        $this->user = $user;
        $this->title = $title;
        $this->body = $body;
        $this->type = $type;
        $this->batchNumber = $batchNumber;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $user = $this->user->fresh();
        
        if (!$user || !$user->is_active) {
            Log::warning("User tidak aktif atau tidak ditemukan, job di-skip", [
                'user_id' => $this->user->id ?? 'unknown'
            ]);
            return;
        }

        $today = now()->startOfDay();
        $alreadySent = Notification::where('user_id', $user->id)
            ->where('type', $this->type)
            ->where('body', 'like', "%{$this->batchNumber}%")
            ->whereDate('created_at', $today)
            ->exists();

        if ($alreadySent) {
            Log::info("Notif duplikat di-skip", [
                'user_id' => $user->id,
                'batch' => $this->batchNumber,
                'type' => $this->type,
            ]);
            return;
        }

        try {
            NotificationService::sendToUser($user, $this->title, $this->body, $this->type);
            
            Log::info("Notif expiry berhasil dikirim", [
                'user_id' => $user->id,
                'batch' => $this->batchNumber,
                'type' => $this->type,
            ]);
        } catch (MessagingException $e) {
            Log::error("Firebase error", [
                'user_id' => $user->id,
                'batch' => $this->batchNumber,
                'error' => $e->getMessage(),
                'error_code' => $e->errorCode ?? null,
                'error_details' => $e->errors() ?? [],
            ]);
            
            if ($e->errorCode === 'messaging/invalid-registration-token') {
                $errors = $e->errors();
                if (!empty($errors) && isset($errors[0]['reason'])) {
                    $invalidToken = $errors[0]['reason'];
                    $user->fcmTokens()->where('token', $invalidToken)->delete();
                    Log::info("Invalid FCM token dihapus", ['user_id' => $user->id, 'token' => $invalidToken]);
                }
            }
            
            throw $e;
        } catch (Exception $e) {
            Log::error("Gagal kirim notif expiry", [
                'user_id' => $user->id,
                'batch' => $this->batchNumber,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error("Job notif expiry gagal setelah retry", [
            'user_id' => $this->user->id,
            'batch' => $this->batchNumber,
            'error' => $e->getMessage(),
        ]);
    }
}

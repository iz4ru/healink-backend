<?php

namespace App\Observers;

use App\Models\User;
use App\Services\NotificationService;
use Laravel\Sanctum\PersonalAccessToken;

class PersonalAccessTokenObserver
{
    /**
     * Custom function
     */
    private function notifySuspiciousLogin($user): void
    {
        $owners = User::where('role', 'owner')
        ->where('is_active', true)
        ->get();

        foreach ($owners as $owner) {
            NotificationService::sendToUser(
                $owner,
                '🕵 Peringatan Keamanan',
                "Login dari perangkat baru terdeteksi pada akun {$user->name}. Periksa jika mencurigakan.",
                'security'
            );
        }
    }

    /**
     * Handle the PersonalAccessToken "created" event.
     */
    public function created(PersonalAccessToken $personalAccessToken): void
    {
        $user = $personalAccessToken->tokenable;
        $incomingDeviceId = request()->header('X-Device-ID');

        $isNewDevice = !$user->fcmTokens()->where('device_id', $incomingDeviceId)->exists();

        if ($isNewDevice && $incomingDeviceId !== null) {
            $this->notifySuspiciousLogin($user);
        }
    }

    /**
     * Handle the PersonalAccessToken "updated" event.
     */
    public function updated(PersonalAccessToken $personalAccessToken): void
    {
        //
    }

    /**
     * Handle the PersonalAccessToken "deleted" event.
     */
    public function deleted(PersonalAccessToken $personalAccessToken): void
    {
        //
    }

    /**
     * Handle the PersonalAccessToken "restored" event.
     */
    public function restored(PersonalAccessToken $personalAccessToken): void
    {
        //
    }

    /**
     * Handle the PersonalAccessToken "force deleted" event.
     */
    public function forceDeleted(PersonalAccessToken $personalAccessToken): void
    {
        //
    }
}

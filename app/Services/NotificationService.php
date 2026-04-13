<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;

/**
 * Class NotificationService
 * @package App\Services
 */
class NotificationService
{
    public static function sendToUser(User $user, $title, $body, $type = 'info')
    {
        $user->notifications()->create([
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'is_read' => false,
        ]);

        $rawCredentials = config('services.firebase.credentials');

        if (empty($rawCredentials)) {
            Log::error("Firebase Credentials kosong di .env atau config!");
            return;
        }

        $credentials = $rawCredentials;

        if (is_string($credentials) && str_starts_with(trim($credentials), '{')) {
            $credentials = json_decode($credentials, true);
        }
        else {
            
            $credentials = base_path($credentials);

            
            if (!is_file($credentials)) {
                Log::error("File Firebase tidak ditemukan di: " . $credentials);
                return;
            }
        }

        $factory = (new Factory)->withServiceAccount($credentials);
        $messaging = $factory->createMessaging();

        $tokens = $user->fcmTokens()->pluck('token')->toArray();

        foreach ($tokens as $token) {
            $message = CloudMessage::fromArray([
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => 'high_importance_channel',
                        'icon' => 'notification_icon',
                        'color' => '#3A7CF0',
                    ],
                ],
            ]);

            try {
                $messaging->send($message);
            } catch (\Exception $e) {
                continue;
            }
        }
    }
}

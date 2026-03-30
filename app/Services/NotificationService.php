<?php

namespace App\Services;

use App\Models\User;
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

        $factory = (new Factory)->withServiceAccount(json_decode(env('FIREBASE_CREDENTIALS'), true));
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

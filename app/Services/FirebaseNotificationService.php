<?php

namespace App\Services;
 
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;
use Illuminate\Support\Facades\Log;
 
class FirebaseNotificationService
{
    protected $messaging;
 
    public function __construct()
    {
        $factory = (new Factory)
            ->withServiceAccount(
                config('services.firebase.credentials')
            );
 
        $this->messaging = $factory->createMessaging();
    }
 
    /**
     * إرسال إشعار Push لتوكن واحد (مستخدم واحد)
     */
    public function sendToToken(string $fcmToken, string $title, string $body, array $data = []): array
    {
        try {
            Log::info('🔔 Firebase: جاري إرسال الإشعار...', [
                'title' => $title,
                'token' => substr($fcmToken, 0, 20) . '...',
            ]);
 
            // FCM بيرفض أي قيمة مش string جوا data، فعم نحولهن كلهن
            $stringData = array_map('strval', $data);
 
            $message = CloudMessage::new()
                ->toToken($fcmToken)
                ->withNotification(
                    FirebaseNotification::create($title, $body)
                )
                ->withData($stringData);
 
            $response = $this->messaging->send($message);
 
            Log::info('✅ Firebase: تم إرسال الإشعار بنجاح', [
                'title' => $title,
                'firebase_response' => $response,
            ]);
 
            return [
                'success' => true,
                'firebase_response' => $response,
            ];
 
        } catch (\Throwable $e) {
 
            Log::error('❌ Firebase: فشل إرسال الإشعار', [
                'title' => $title,
                'token' => substr($fcmToken, 0, 20) . '...',
                'error' => $e->getMessage(),
            ]);
 
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
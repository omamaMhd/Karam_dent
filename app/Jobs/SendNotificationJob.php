<?php

// namespace App\Jobs;
// use Illuminate\Support\Facades\Http;
// use Illuminate\Contracts\Queue\ShouldQueue;
// use Illuminate\Foundation\Queue\Queueable;
// use App\Models\Invoice;
// use App\Models\User;
// use App\Models\Notification;
// class SendInvoiceNotificationJob implements ShouldQueue
// {
//     use Queueable;

//      protected Invoice $invoice;

//     public function __construct(Invoice $invoice)
//     {
//         $this->invoice = $invoice;
//     }

//     public function handle()
//     {
//         $accountants = User::where('role', 'accountant')->pluck('id');

//         foreach ($accountants as $userId) {
//             Notification::create([
//                 'user_id' => $userId,
//                 'title' => 'فاتورة جديدة',
//                 'body' => "فاتورة جديدة من المورد رقم {$this->invoice->supplier_id}",
//                 'type' => 'invoice',
//                 'data' => json_encode([
//                     'invoice_id' => $this->invoice->id
//                 ])
//             ]);
//         }
//     }
// }

// class SendNotificationJob implements ShouldQueue
// {
//     public $users;
//     public $title;
//     public $body;
//     public $type;
//     public $data;

//     public function __construct($users, $title, $body, $type, $data = [])
//     {
//         $this->users = $users;
//         $this->title = $title;
//         $this->body = $body;
//         $this->type = $type;
//         $this->data = $data;
//     }

    // public function handle()
    // {
    //     // 🧾 DB
    //     foreach ($this->users as $userId) {
    //         Notification::create([
    //             'user_id' => $userId,
    //             'title' => $this->title,
    //             'body' => $this->body,
    //             'type' => $this->type,
    //             'data' => json_encode($this->data)
    //         ]);
    //     }

    //     // 🔔 Firebase
    //     Http::withHeaders([
    //         'Authorization' => 'key=' . config('services.firebase.server_key'),
    //         'Content-Type' => 'application/json',
    //     ])->post('https://fcm.googleapis.com/fcm/send', [
    //         'to' => '/topics/general',
    //         'notification' => [
    //             'title' => $this->title,
    //             'body' => $this->body,
    //         ],
    //         'data' => $this->data
    //     ]);
    // }
//     public function handle()
// {
//     // 1. تسجيل الإشعار في قاعدة البيانات (هذا الجزء ممتاز عندك)
//     foreach ($this->users as $userId) {
//         Notification::create([
//             'user_id' => $userId,
//             'title' => $this->title,
//             'body' => $this->body,
//             'type' => $this->type,
//             'data' => json_encode($this->data)
//         ]);

//         // 2. إرسال الإشعار لـ Firebase (الجزء المصحح)
//         $user = User::find($userId);
//         if ($user && $user->fcm_token) {
//             Http::withHeaders([
//                 'Authorization' => 'key=' . config('services.firebase.server_key'),
//                 'Content-Type' => 'application/json',
//             ])->post('https://fcm.googleapis.com/fcm/send', [
//                 'to' => $user->fcm_token, // هنا نرسل للمستخدم المحدد فقط!
//                 'notification' => [
//                     'title' => $this->title,
//                     'body'  => $this->body,
//                 ],
//                 'data' => $this->data
//             ]);
//         }
//     }
// }

//         // 2. إرسال FCM بالـ HTTP v1 API
//         $user = User::find($userId);
//         if ($user && $user->fcm_token) {
//             $this->sendFcm($user->fcm_token);
//         }
//     }
// }

// private function sendFcm(string $fcmToken): void
// {
//     try {
//         $notifService = app(\App\Services\NotificationTestService::class);
//         $notifService->sendNotification(
//             $fcmToken,
//             $this->title,
//             $this->body,
//             array_merge(['type' => $this->type], $this->data)
//         );
//     } catch (\Throwable $e) {
//         \Illuminate\Support\Facades\Log::error('FCM Error', [
//             'error' => $e->getMessage()
//         ]);
//     }
// }
// } 


// namespace App\Jobs;

// use App\Models\Notification;
// use App\Models\User;
// use App\Services\FirebaseNotificationService;
// use Illuminate\Contracts\Queue\ShouldQueue;
// use Illuminate\Foundation\Queue\Queueable;
// use Illuminate\Support\Facades\Log;
// use Kreait\Firebase\Messaging\CloudMessage;


// class SendNotificationJob implements ShouldQueue
// {
//     use Queueable;

//     public array $userIds;
//     public string $title;
//     public string $body;
//     public string $type;
//     public array $data;

//     public function __construct(
//         array $userIds,
//         string $title,
//         string $body,
//         string $type,
//         array $data = []
//     ) {
//         $this->userIds = $userIds;
//         $this->title = $title;
//         $this->body = $body;
//         $this->type = $type;
//         $this->data = $data;
//     }

//     public function handle(FirebaseNotificationService $firebase)
//     {
//         $users = User::whereIn('id', $this->userIds)->get();

//         foreach ($users as $user) {

//             // 1. حفظ الإشعار في Database
//             Notification::create([
//                 'user_id' => $user->id,
//                 'title' => $this->title,
//                 'body' => $this->body,
//                 'type' => $this->type,
//                 'data' => json_encode($this->data),
//             ]);

//                 Log::info("sending notifications...");
//             // 2. إرسال Push Notification إذا عنده Token
//             if ($user->fcm_token) {
//                 Log::info("sending notifications...");
//                 $message = CloudMessage::new()
//                                 ->toToken( $user->fcm_token)
//                                 ->withNotification(
//                                     Notification::create(
//                                         $this->title,
//                                         $this->body
//                                     )
//                                 )
//                                 ->withData([
//                                     'type' => 'test',
//                                     'timestamp' => now()->toDateTimeString(),
//                                 ]);


//                 // $firebase->sendToToken(
//                 //     $user->fcm_token,
//                 //     $this->title,
//                 //     $this->body,
//                 //     array_merge(
//                 //         ['type' => $this->type],
//                 //         $this->data
//                 //     )
//                 // );
//             }
//         }
//     }
// }

 
namespace App\Jobs;
 
use App\Models\Notification;
use App\Models\User;
use App\Services\FirebaseNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Log;
 
class SendNotificationJob implements ShouldQueue ,ShouldBeUnique
{
    use Queueable;
 
    public array $userIds;
    public string $title;
    public string $body;
    public string $type;
    public array $data;
 
    public function __construct(
        array $userIds,
        string $title,
        string $body,
        string $type,
        array $data = []
    ) {
        $this->userIds = $userIds;
        $this->title = $title;
        $this->body = $body;
        $this->type = $type;
        $this->data = $data;
    }
    
    public function uniqueId(): string
{
    return $this->type . ':' . ($this->data['invoice_id'] ?? md5(
        $this->title . $this->body . json_encode($this->userIds)
    ));
}
 
    public function handle(FirebaseNotificationService $firebase)
    {
        Log::info('📨 SendNotificationJob: بدء المعالجة', [
            'user_ids' => $this->userIds,
            'type' => $this->type,
            'title' => $this->title,
        ]);
 
        $users = User::whereIn('id', $this->userIds)->get();
 
        if ($users->isEmpty()) {
            Log::warning('⚠️ SendNotificationJob: ما في مستخدمين مطابقين للـ IDs المرسلة', [
                'user_ids' => $this->userIds,
            ]);
            return;
        }
 
        foreach ($users as $user) {
 
            // 1. حفظ الإشعار بقاعدة البيانات (يظهر بجرس الإشعارات بالتطبيق)
            Notification::create([
                'user_id' => $user->id,
                'title'   => $this->title,
                'body'    => $this->body,
                'type'    => $this->type,
                'data'    => json_encode($this->data),
            ]);
 
            Log::info("💾 تم حفظ الإشعار بالـ DB للمستخدم #{$user->id}");
 
            // 2. إرسال Push Notification عبر Firebase (إذا عنده fcm_token)
            if ($user->fcm_token) {
 
                $result = $firebase->sendToToken(
                    $user->fcm_token,
                    $this->title,
                    $this->body,
                    array_merge(['type' => $this->type], $this->data)
                );
 
                if ($result['success']) {
                    Log::info("✅ Push وصل بنجاح للمستخدم #{$user->id}");
                } else {
                    Log::warning("⚠️ فشل إرسال Push للمستخدم #{$user->id}", [
                        'error' => $result['error'],
                    ]);
                }
 
            } else {
                Log::info("ℹ️ المستخدم #{$user->id} ما عنده fcm_token مسجل، تم تخطي الـ Push (بس الإشعار محفوظ بالـ DB)");
            }
        }
 
        Log::info('🏁 SendNotificationJob: انتهت المعالجة لكل المستخدمين');
    }
}
 
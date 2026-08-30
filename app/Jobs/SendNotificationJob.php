<?php
 
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
 
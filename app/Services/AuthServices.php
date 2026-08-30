<?php
namespace App\Services;

use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use App\Models\Patient;
use Exception;

class AuthServices {
    protected $repo;
    
    public function __construct(UserRepository $repo) { 
        $this->repo = $repo; 
    }

    // 1. تابع إرسال الواتساب
    private function sendWhatsApp($phone, $otp) {
        $instanceId = config('services.ultramsg.instance');
        $token = config('services.ultramsg.token');
        
        Http::post("https://api.ultramsg.com/$instanceId/messages/chat", [
            'token' => $token,
            'to'    => $phone,
            'body'  => "كود التحقق الخاص بك في تطبيق Karam Dent هو: $otp",
        ]);
    }

    // 2. تسجيل المريض وإرسال الكود
    public function registerPatient($data) {
        $user = User::create([
            'name' => $data['name'],
            'phone_number' => $data['phone_number'],
            'password' => bcrypt($data['password']),
            'is_verified' => false
        ]);
        $user->assignRole('patient');
 // 2️⃣ إنشاء patient مباشرة
        Patient::create([
            'user_id' => $user->id,
            'file_open_date' => now(),
            // باقي الحقول فاضية بالبداية
            'gender' => null,
            'address' => null,
            'occupation' => null,
        ]);
        $otp = rand(1000, 9999); 
        $user->update([
            'otp_code' => $otp, 
            'otp_expires_at' => now()->addMinutes(15),
            'last_otp_sent_at' => now(),
        ]);
        
        $this->sendWhatsApp($user->phone_number, $otp);
        return $user;
    }

    // 3. تابع التحقق من OTP
    public function verifyOtp($phone, $code) {
        $user = $this->repo->findByIdentifier($phone);

       if (!$user || $user->otp_code != $code) {
        return [
            'success' => false,
            'message' => 'كود التحقق غير صحيح.'
        ];
    }

    if (now()->isAfter($user->otp_expires_at)) {
        return [
            'success' => false,
            'message' => 'انتهت صلاحية الكود.'
        ];
    }

        $user->update([
            'is_verified' => true,
            'otp_code' => null,
            'otp_expires_at' => null
        ]);

     return [
        'success' => true,
        'message' => 'تم تفعيل الحساب بنجاح',
        'token'   => $user->createToken('auth_token')->plainTextToken
    ];
    }
    public function resendOtp($phone)
{
    $user = $this->repo->findByIdentifier($phone);

    if (!$user) {
        return [
            'success' => false,
            'message' => "المستخدم غير موجود"
        ];
    }

    // إذا الحساب متفعل أصلاً
    if ($user->is_verified) {
        return [
            'success' => false,
            'message' => "الحساب مفعل بالفعل"
        ];
    }

    // (اختياري) منع السبام - إذا الكود لسا ما انتهى
    if ($user->otp_expires_at && now()->lt($user->otp_expires_at)) {
            return [
                'success' => false,
                'message' => "الكود الحالي ما زال صالح، حاول لاحقاً"
            ];
    }
     
    // توليد كود جديد
    $otp = rand(1000, 9999);

    $user->update([
        'otp_code' => $otp,
        'otp_expires_at' => now()->addMinutes(15),
        'last_otp_sent_at' => now()
    ]);

    // إرسال واتساب
    $this->sendWhatsApp($user->phone_number, $otp);

    return "تم إرسال كود تحقق جديد عبر واتساب";
}

   
public function login($data)
{
    $identifier = $data['login_field'] ?? null;
    $password   = $data['password'] ?? null;
    $role       = $data['role'] ?? null; // اختياري

    $user = $this->repo->findByIdentifier($identifier);

   if (!$user || !Hash::check($password, $user->password)) {
    return [
        'success' => false,
        'message' => 'بيانات الدخول غير صحيحة'
    ];
}
    // إصلاح بيانات قديمة: إذا كان للمستخدم ملف مريض بدون role نربطه تلقائياً.
    if ($user->getRoleNames()->isEmpty() && $user->patient()->exists()) {
        $user->assignRole('patient');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $user = $user->fresh();
    }

    $actualRole = $user->getRoleNames()->first();
    if (!$actualRole) {
        return [
            'success' => false,
            'message' => 'هذا الحساب لا يملك أي role. راجع إنشاء الحساب أو الإسناد.'
        ];
    }

    // تحقق تفعيل المريض
    if ($user->hasRole('patient') && !$user->is_verified) 
        {
        return [
            'success' => false,
            'message' => 'يرجى تفعيل الحساب أولاً.'
        ];
    }

    // 🎯 التحقق من الرول (اختياري)
    if ($role && !$user->hasRole($role))
        {
    return [
        'success' => false,
        'message' => "هذا الحساب ليس {$role}، الدور الصحيح هو {$actualRole}"    ];
}
    // ✅ دخول طبيعي
    $token = $user->createToken('auth_token')->plainTextToken;

    $response = [
        'token'   => $token,
        'role'    => $actualRole,
        'user_id' => $user->id,
    ];

    if ($actualRole === 'patient') {
        $response['patient_id'] = $user->patient?->id;
    }

    if ($actualRole === 'doctor') {
        $response['doctor_id'] = $user->doctor?->id;
    }

    return $response;
}
    public function checkToken(): array
    {
        $user = auth()->user();
        $role = $user->getRoleNames()->first();

        $response = [
            'authenticated' => true,
            'user_id' => $user->id,
            'name' => $user->name,
            'role' => $role,
        ];

        if ($role === 'patient') {
            $response['patient_id'] = $user->patient?->id;
        }

        if ($role === 'doctor') {
            $response['doctor_id'] = $user->doctor?->id;
        }

        return $response;
    }


}
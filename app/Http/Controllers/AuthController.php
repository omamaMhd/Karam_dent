<?php
namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Services\AuthServices;
use Illuminate\Http\Request;
use App\Http\Requests\RegisterRequest;


class AuthController {
    protected $service;
    public function __construct(AuthServices $service) { $this->service = $service; }

    
public function register(RegisterRequest $request)
{
    $data = $request->validated();

    $this->service->registerPatient($data);

    return response()->json([
        'message' => 'تم إرسال كود التحقق بنجاح'
    ]);
}
    // *** المسار الجديد: /verify-otp ***
    public function verify(Request $request) {
        $request->validate([
           'phone_number' => 'required|string|exists:users,phone_number',
            'otp_code'     => 'required|digits:4'
        ]);


         $result = $this->service->verifyOtp(
        $request->phone_number,
        $request->otp_code
    );

    return response()->json(
        $result,
        $result['success'] ? 200 : 422
    );
    }
    public function resendOtp(Request $request)
{
    $request->validate([
        'phone_number' => 'required|string|exists:users,phone_number'
    ]);

    return response()->json([
        'message' => $this->service->resendOtp($request->phone_number)
    ]);
}

    // المسار: /login
    public function login(LoginRequest $request) {
        try {
           // $token = $this->service->login($request->login_field, $request->password, $request->role);
           $result = $this->service->login($request->all());
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 401);
        }
    }
     // 3. تسجيل الخروج
    public function logout(Request $request)
    {
        // حذف التوكن الحالي الذي تم استخدامه للمصادقة
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Successfully logged out']);
    }

    public function checkToken()
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->checkToken(),
        ]);
    }
}
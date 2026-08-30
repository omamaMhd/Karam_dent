<?php
namespace App\Services;
use App\Models\Doctor_Schedule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\User;
use App\Models\Notification;
use App\Events\MaterialRequestCreated;
use App\Models\Doctor;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Factory;
use Illuminate\Support\Facades\Log;

class DoctorService
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

   public function addAvailableTime($data)
{
    $doctor = Auth::user()->doctor;


    if (!$doctor) {
        return [
            'success' => false,
            'message' => "هذا المستخدم ليس دكتور"
        ];
    }

    // 🔥 منع التداخل
    $overlap = Doctor_Schedule::where('doctor_id', $doctor->id)
        ->where('day', $data['day'])
        ->where(function ($q) use ($data) {
            $q->whereBetween('start_time', [$data['start_time'], $data['end_time']])
              ->orWhereBetween('end_time', [$data['start_time'], $data['end_time']]);
        })
        ->exists();

    if ($overlap) {
        return [
            'success' => false,
            'message' => "الفترة تتداخل مع فترة موجودة"
        ];

    }
    if ($data['start_time'] >= $data['end_time']) {
            return [
                'success' => false,
                'message' => "وقت البداية لازم يكون قبل النهاية"
            ];
}

    return Doctor_Schedule::create([
        'doctor_id' => $doctor->id,
        'day' => $data['day'],
        'start_time' => $data['start_time'],
        'end_time' => $data['end_time'],
    ]);
}
 // تعديل فترة
    public function updateAvailableTime($id, $data)
    {
        $doctor = Auth::user()->doctor;

        $schedule = Doctor_Schedule::where('id', $id)
            ->where('doctor_id', $doctor->id)
            ->firstOrFail();

        // ✅ تحقق الوقت
        if ($data['start_time'] >= $data['end_time']) {
            return [
                'success' => false,
                'message' => "وقت البداية لازم يكون قبل النهاية"
            ];
           // throw new \Exception("وقت البداية لازم يكون قبل النهاية");
        }

        $schedule->update([
            'day' => $data['day'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
        ]);

        return $schedule;
    }

    // حذف فترة
    public function deleteAvailableTime($id)
    {
        $doctor = Auth::user()->doctor;

        $schedule = Doctor_Schedule::where('id', $id)
            ->where('doctor_id', $doctor->id)
            ->firstOrFail();

        $schedule->delete();

        return ['message' => 'تم حذف الفترة'];
    }
    //عرض الاوقات المتاحة
    public function getMyAvailableTimes()
{
    $doctor = Auth::user()->doctor;

    if (!$doctor) {
        return [
            'success' => false,
            'message' => "هذا المستخدم ليس دكتور"
        ];
    }
    return Doctor_Schedule::where('doctor_id', $doctor->id)
    ->orderByRaw("FIELD(day, 'sun', 'mon', 'tues', 'wed', 'thy', 'fri', 'sat')")
    ->orderBy('start_time', 'asc')
    ->get();
}

public function getDoctorPatients()
{
    $doctorId = Auth::user()->doctor->id;

    return Patient::where(function ($query) use ($doctorId) {
        $query->whereHas('treatmentPlans', function ($planQuery) use ($doctorId) {
            $planQuery->where('doctor_id', $doctorId)
                ->whereHas('items.sessions', function ($sessionQuery) {
                    $sessionQuery->where('status', 'completed');
                });
        })
        ->orWhereHas('appointments', function ($appointmentQuery) use ($doctorId) {
            $appointmentQuery->where('doctor_id', $doctorId)
                             ->where('status', 'confirmed');
        });
    })
    ->with(['user:id,name,phone_number']) // جلب الاسم ورقم الهاتف فقط من جدول الـ Users
    ->get(['id', 'user_id'])        // جلب الـ ID والـ user_id فقط من جدول المرضى
    ->map(function ($patient) {
        return [
            'id'    => $patient->id,
            'name'  => $patient->user->name,
            'phone' => $patient->user->phone_number, // تأكدي أن اسم الحقل في جدول users هو phone
        ];
    });
}


public function getTodayAppointments()
{
    $doctor = Auth::user()->doctor;

    return Appointment::where('doctor_id', $doctor->id)
        ->whereDate('appointment_date', now()->toDateString())
        ->with('patient.user:id,name') // جلب بيانات المريض
        ->orderBy('appointment_date')
        ->get();
}

public function getUpcomingAppointmentsGrouped()
{
    $doctor = Auth::user()->doctor;

    return Appointment::where('doctor_id', $doctor->id)
        ->whereBetween('appointment_date', [
            now(),
            now()->addDays(10)
        ])
        ->with('patient')
        ->get()
        ->groupBy(function ($item) {
            return \Carbon\Carbon::parse($item->appointment_date)->toDateString();
        });
}

public function searchPatientsByName(string $name)
{
    $user = Auth::user();
   // $doctor = $user?->doctor;

    $query = Patient::with('user')
        ->whereHas('user', function ($q) use ($name) {
            $q->where('name', 'like', "%{$name}%");
        });

    return $query
        ->distinct()
        ->get()
        ->map(function (Patient $patient) {
            return [
                'name' => $patient->user?->name,
                'phone_number' => $patient->user?->phone_number,
            ];
        })
        ->values();
}


public function createRequest(array $data)
{
    return DB::transaction(function () use ($data) {

         $doctor = Doctor::where('user_id', Auth::id())->firstOrFail();
// توليد رقم طلب فريد (REQ-XXXX) مع قفل لمنع التضارب عند الطلبات المتزامنة
$lastId = MaterialRequest::lockForUpdate()->max('id') ?? 0;
$requisitionNumber = 'REQ-' . str_pad((string) ($lastId + 1), 4, '0', STR_PAD_LEFT);
        $request = MaterialRequest::create([
             'requisition_number' => $requisitionNumber, // ✅ أضيف
            'doctor_id' => $doctor->id, // ✅ هون الصح
             'requested_by' => Auth::id(),  
            'notes' => $data['notes'] ?? null,
        ]);

        foreach ($data['items'] as $item) {

            MaterialRequestItem::create([
                'material_request_id' => $request->id,
                'item_id' => $item['item_id'],
                 'quantity_requested'  => $item['quantity'],
            ]);
        }

       
 event(new MaterialRequestCreated($request));


        return $request->load('items');
    });
}

    public function getDoctors()
        {
            return Doctor::with(['user', 'specialization'])
                ->get()
                ->map(function ($doctor) {
                    return [
                        'doctor_id' => $doctor->id,
                        'user_id' => $doctor->user_id,
                        'name' => $doctor->user?->name,
                        'phone_number' => $doctor->user?->phone_number,
                        'email' => $doctor->user?->email,
                        'specialization_id' => $doctor->specialization_id,
                        'specialization' => $doctor->specialization?->name,
                        'percentage' => $doctor->percentage,
                    ];
                });
        }

    }
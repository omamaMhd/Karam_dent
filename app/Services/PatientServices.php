<?php
namespace App\Services;
use App\Models\Doctor_Schedule;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class PatientServices
{

public function getAllPatients()
{
    return Patient::with('user')
        ->orderBy('id')
        ->paginate(20)
        ->through(function (Patient $patient) {
            return [
                'patient_id'   => $patient->id,            
                'user_id'      => $patient->user_id,
                'name' => $patient->user?->name,
                'phone_number' => $patient->user?->phone_number,
            ];
        });
}
 
public function getAvailableSlotsForDays($doctorId)
{
    $doctor = Doctor::where('id', $doctorId)
        ->where('is_active', true)
         ->firstOrFail();

    // if (!$doctor) {
    //     return [];
    // }
    $daysToCheck = 10;
    $result = [];

    for ($i = 0; $i < $daysToCheck; $i++) {

        $date = Carbon::today()->addDays($i);
        $day = $this->normalizeDay($date);

        $schedules = Doctor_Schedule::where('doctor_id', $doctor->id)
            ->where('day', $day) ->get();


        // $schedules = $query->get();

        $slots = [];

        foreach ($schedules as $schedule) {
            $start = Carbon::parse($schedule->start_time);
            $end = Carbon::parse($schedule->end_time);

            while ($start < $end) {
                $slots[] = $start->format('H:i');
                $start->addMinutes(30);
            }
        }

        // 🔥 حذف الأوقات الماضية لليوم الحالي
       if ($i == 0) {
    $now = Carbon::now();

    $slots = array_filter($slots, function ($slot) use ($now, $date) {
        $slotDateTime = Carbon::parse($date->toDateString() . ' ' . $slot);
        return $slotDateTime->gt($now);
    });
}

        // جلب المحجوز
        $booked = Appointment::where('doctor_id', $doctor->id)
            ->whereDate('appointment_date', $date)
            ->whereIn('status', ['scheduled', 'confirmed', 'completed'])
            ->pluck('appointment_date')
            ->map(fn($t) => Carbon::parse($t)->format('H:i'))
            ->toArray();

        // حذف المحجوز
        $available = array_values(array_diff($slots, $booked));

        // 👉 حتى لو فاضي خليه موجود (ليعرض "لا يوجد")
        $result[] = [
            'date' => $date->toDateString(),
            'day' => $day,
            'slots' => $available
        ];
    }

    return $result;
}


//   public function bookAppointment($userId, $data)
// {
//     // 1️⃣ نجيب المريض
   
//    $patient = Auth::user()->patient;
//     // 2️⃣ نجيب الدكتور
//     $doctor = Doctor::where('specialization_id', $data['specialization_id'])
//         ->where('is_active', true)
//         ->firstOrFail();

//     // 3️⃣ نركب datetime
//     $appointmentDateTime = Carbon::parse($data['date'] . ' ' . $data['time']);

//     // ❌ منع الحجز بالماضي
//     if ($appointmentDateTime->lt(Carbon::now())) {
//         throw new \Exception("لا يمكن حجز موعد في الماضي");
//     }

//     // 4️⃣ تأكد الوقت ضمن الدوام
//     $day = $this->normalizeDay($appointmentDateTime);

//     $hasSchedule = Doctor_Schedules::where('doctor_id', $doctor->id)
//         ->where('day', $day)
//         ->exists();

//     if (!$hasSchedule) {
//         throw new \Exception("الدكتور لا يعمل بهذا اليوم");
//     }

//     // 5️⃣ تأكد الوقت مو محجوز
//     $exists = Appointment::where('doctor_id', $doctor->id)
//         ->where('appointment_date', $appointmentDateTime)
//         ->exists();

//     if ($exists) {
//         throw new \Exception("هذا الموعد محجوز مسبقاً");
//     }

//     // 6️⃣ إنشاء الموعد
//     return Appointment::create([
//         'patient_id' => $patient->id,
//         'doctor_id' => $doctor->id,
//         'appointment_date' => $appointmentDateTime,
//         'status' => 'scheduled'
//     ]);
//     // 👇 هون
// app(NotificationService::class)->send(
//     $reception,
//     'موعد جديد',
//     'تم حجز موعد جديد بانتظار التأكيد',
//     'appointment',
//     $appointment->id
// );
// }
    public function bookAppointment($patientId, $data)
    {
        $doctor = Doctor::where('id', $data['doctor_id'])
            ->where('is_active', true)
            ->firstOrFail();

        $appointmentDateTime = Carbon::parse($data['date'] . ' ' . $data['time']);

        if ($appointmentDateTime->lt(Carbon::now())) {
                return [
                    'success' => false,
                    'message' => "لا يمكن حجز موعد في الماضي"
                ];
            //throw new \Exception("لا يمكن حجز موعد في الماضي");
        }

        $day = $this->normalizeDay($appointmentDateTime);

        $hasSchedule = Doctor_Schedule::where('doctor_id', $doctor->id)
            ->where('day', $day)
            ->exists();

        if (!$hasSchedule) {
                return [
                    'success' => false,
                    'message' => "الدكتور لا يعمل بهذا اليوم"
                ];
           // throw new \Exception("الدكتور لا يعمل بهذا اليوم");
        }

        $exists = Appointment::where('doctor_id', $doctor->id)
            ->where('appointment_date', $appointmentDateTime)
            ->whereIn('status', [ 'confirmed', 'completed'])
            ->exists();

        if ($exists) {
                return [
                    'success' => false,
                    'message' => "هذا الموعد محجوز مسبقاً"
                ];
            //throw new \Exception("هذا الموعد محجوز مسبقاً");
        }

        $appointment = Appointment::create([
            'patient_id' => $patientId,
            'doctor_id' => $doctor->id,
            'appointment_date' => $appointmentDateTime,
            'status' => 'scheduled',
             'day' => $day
        ]);

        // notification (لازم قبل return)
        app(NotificationService::class)->send(
            $doctor,
            'موعد جديد',
            'تم حجز موعد جديد',
            'appointment',
            $appointment->id
        );

        return $appointment;
    }

    private function normalizeDay(Carbon $date): string
    {
        return match ($date->dayOfWeek) {
            Carbon::SUNDAY => 'sun',
            Carbon::MONDAY => 'mon',
            Carbon::TUESDAY => 'tues',
            Carbon::WEDNESDAY => 'wed',
            Carbon::THURSDAY => 'thy',
            Carbon::FRIDAY => 'fri',
            Carbon::SATURDAY => 'sat',
        };
    }



}
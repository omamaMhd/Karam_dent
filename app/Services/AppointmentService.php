<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Doctor_Schedule;
use App\Models\Patient;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class AppointmentService
{

 // الحالات "النشطة" التي يُطبَّق عليها فلتر النافذة الزمنية (قريبة من الحاضر)
    private const ACTIVE_STATUSES = ['scheduled', 'confirmed'];

    // فترة السماح بالدقائق: كم دقيقة بعد وقت الموعد يبقى معروضاً كـ"نشط"
    private const GRACE_PERIOD_MINUTES = 60;

    public function getAllDoctorsForSecretary(): array
    {
        return Doctor::with(['user', 'specialization'])
            ->orderBy('id')
            ->where('is_active', true)
            ->get()
            ->map(function (Doctor $doctor) {
                return [
                    'id' => $doctor->id,
                    'name' => $doctor->user?->name,
                    'specialization_name' => $doctor->specialization?->name,
                ];
            })
            ->values()
            ->all();
    }

    public function getActiveDoctorsBySpecialization(int $specializationId): array
    {
        return Doctor::with('user')
            ->where('specialization_id', $specializationId)
            ->where('is_active', true)
            ->get()
            ->map(function (Doctor $doctor) {
                return [
                    'id' => $doctor->id,
                    'name' => $doctor->user?->name,
                ];
            })
            ->values()
            ->all();
    }
// للحذف لاحقاً
    public function getAvailableSlotsForDoctorId(int $doctorId, int $daysToCheck = 10): array
    {
        $doctor = Doctor::where('id', $doctorId)
            ->where('is_active', true)
            ->firstOrFail();

        return $this->getAvailableSlotsForDoctor($doctor, $daysToCheck);
    }

    public function getSecretaryAppointmentsByStatus(
        string $status,
        ?string $date = null,
        ?string $fromDate = null,
        ?string $toDate = null,
        int $limit = 500
    ): array {
        $query = Appointment::with(['patient.user', 'doctor.user'])
            ->where('status', $status)
            ->orderBy('appointment_date');

        if ($date) { 
            $query->whereDate('appointment_date', $date);
        } else {
            if ($fromDate) {
                $query->whereDate('appointment_date', '>=', $fromDate);
            } else {
                $query->whereDate('appointment_date', '>=', Carbon::today());
            }

            if ($toDate) {
                $query->whereDate('appointment_date', '<=', $toDate);
            }
        }

        return $query->limit($limit)->get()->map(function (Appointment $appointment) {
            return [
                'id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'patient_name' =>
                    $appointment->patient?->trashed()
                        ? $appointment->patient->user->name . ' (محذوف)'
                        : $appointment->patient?->user?->name,
                'patient_phone' => $appointment->patient?->user?->phone_number,
                'doctor_id' => $appointment->doctor_id,
                'doctor_name' =>
                    $appointment->doctor?->trashed()
                        ? $appointment->doctor->user->name . ' (محذوف)'
                        : $appointment->doctor?->user?->name,
                'day' => $appointment->day,
                'appointment_date' => optional($appointment->appointment_date)->toDateTimeString(),
                'status' => $appointment->status,
            ];
        })->values()->all();
    }

    public function resolveDoctorForSpecialization(int $specializationId, ?int $doctorId = null): Doctor
    {
        $query = Doctor::where('specialization_id', $specializationId)
            ->where('is_active', true);

        if ($doctorId) {
            $query->where('id', $doctorId);
        }

        return $query->firstOrFail();
    }

   /*
    public function getAvailableSlotsForDoctor(Doctor $doctor, int $daysToCheck = 10): array
    {
        $result = [];

        for ($i = 0; $i < $daysToCheck; $i++) {
            $date = Carbon::today()->addDays($i);
            $day = $this->normalizeDay($date);

            $schedules = Doctor_Schedules::where('doctor_id', $doctor->id)
                ->where('day', $day)
                ->get();

            $slots = [];

            foreach ($schedules as $schedule) {
                $start = Carbon::parse($schedule->start_time);
                $end = Carbon::parse($schedule->end_time);

                while ($start < $end) {
                    $slots[] = $start->format('H:i');
                    $start->addMinutes(30);
                }
            }

            if ($i === 0) {
                $now = Carbon::now();
                $slots = array_filter($slots, function ($slot) use ($now, $date) {
                    $slotDateTime = Carbon::parse($date->toDateString() . ' ' . $slot);
                    return $slotDateTime->gt($now);
                });
            }

            $booked = Appointment::where('doctor_id', $doctor->id)
                ->whereDate('appointment_date', $date)
                ->whereIn('status', ['scheduled', 'confirmed', 'completed'])
                ->pluck('appointment_date')
                ->map(fn($t) => Carbon::parse($t)->format('H:i'))
                ->toArray();

            $available = array_values(array_diff($slots, $booked));

            $result[] = [
                'date' => $date->toDateString(),
                'day' => $day,
                'slots' => $available,
            ];
        }

        return $result;
    }*/

   public function getAvailableSlotsForDoctor(Doctor $doctor, int $daysToCheck = 10): array
    {
        $result = [];

        for ($i = 0; $i < $daysToCheck; $i++) {

            $date = Carbon::today()->addDays($i);
            $day = $this->normalizeDay($date);

            $schedules = Doctor_Schedule::where('doctor_id', $doctor->id)
                ->where('day', $day)
                ->get();

            // تجاهل الأيام التي لا يوجد فيها دوام
            if ($schedules->isEmpty()) {
                continue;
            }

            $slots = [];

            foreach ($schedules as $schedule) {

                $start = Carbon::parse($schedule->start_time);
                $end = Carbon::parse($schedule->end_time);

                while ($start < $end) {

                    $slots[] = $start->format('H:i');
                    $start->addMinutes(30);
                }
            }

            // حذف الأوقات الماضية لليوم الحالي
            if ($i === 0) {

                $now = Carbon::now();

                $slots = array_filter($slots, function ($slot) use ($now, $date) {

                    $slotDateTime = Carbon::parse(
                        $date->toDateString() . ' ' . $slot
                    );

                    return $slotDateTime->gt($now);
                });
            }

            // جلب المواعيد المحجوزة
            $booked = Appointment::where('doctor_id', $doctor->id)
                ->whereDate('appointment_date', $date)
                ->whereIn('status', ['scheduled', 'confirmed', 'completed'])
                ->pluck('appointment_date')
                ->map(fn($t) => Carbon::parse($t)->format('H:i'))
                ->toArray();

            // حذف المحجوز من المتاح
            $available = array_values(array_diff($slots, $booked));

            $result[] = [
                'date' => $date->toDateString(),
                'day' => $day,
                'slots' => $available,
            ];
        }

        return $result;
    }
    public function bookPatientAppointment(int $patientId, array $data): Appointment
    {
        $doctor = $this->resolveDoctorForSpecialization(
            $data['specialization_id'],
            $data['doctor_id'] ?? null
        );

        $appointmentDateTime = Carbon::parse($data['date'] . ' ' . $data['time']);

        $this->ensureSlotAvailable($doctor, $appointmentDateTime);

        return $this->createAppointment($patientId, $doctor->id, $appointmentDateTime, 'scheduled');
    }

    public function bookSecretaryAppointment(array $data): Appointment
    {
        return DB::transaction(function () use ($data) {
        $doctor = Doctor::where('id', $data['doctor_id'])
            ->where('is_active', true)
            ->firstOrFail();

        $patient = $this->findOrCreatePatient($data['patient_name'], $data['phone_number'] ?? null);
        $appointmentDateTime = Carbon::parse($data['date'] . ' ' . $data['time']);

        $this->ensureSlotAvailable($doctor, $appointmentDateTime);
                // يوجد موعد مؤكد أو مكتمل لنفس الدكتور ونفس الوقت؟
        $alreadyTaken = Appointment::where('doctor_id', $doctor->id)
            ->where('appointment_date', $appointmentDateTime)
            ->whereIn('status', ['confirmed', 'completed'])
            ->exists();

        if ($alreadyTaken) {
            throw new \Exception(
                'لا يمكن حجز هذا الموعد لأن الوقت محجوز مسبقاً'
            );
        }
                // إنشاء موعد السكرتارية كمؤكد مباشرة
        $appointment = $this->createAppointment(
            $patient->id,
            $doctor->id,
            $appointmentDateTime,
            'confirmed'
        );

        // إلغاء جميع الحجوزات المجدولة الأخرى
        // لنفس الدكتور ونفس الوقت
        Appointment::where('doctor_id', $doctor->id)
            ->where('appointment_date', $appointmentDateTime)
            ->where('id', '!=', $appointment->id)
            ->where('status', 'scheduled')
            ->update([
                'status' => 'cancelled'
            ]);

        return $appointment;
    });
}

    public function confirmAppointmentBySecretary(int $appointmentId): Appointment
    {
        return DB::transaction(function () use ($appointmentId) {
        $appointment = Appointment::with([
            'patient.user',
            'doctor.user'
        ])->findOrFail($appointmentId);

        if (
            $appointment->patient?->user?->trashed() ||
            $appointment->doctor?->user?->trashed()
        ) {
            throw new \Exception(
                'لا يمكن تأكيد الموعد لأن الطبيب أو المريض محذوف'
            );
        }

        if ($appointment->status !== 'scheduled') {

            throw new \Exception('لا يمكن تأكيد هذا الموعد');
        }

        $appointment->status = 'confirmed';
        $appointment->save();
        // إلغاء جميع الحجوزات المجدولة الأخرى
        // لنفس الطبيب ونفس الوقت
        Appointment::where('doctor_id', $appointment->doctor_id)
            ->where('appointment_date', $appointment->appointment_date)
            ->where('id', '!=', $appointment->id)
            ->where('status', 'scheduled')
            ->update([
                'status' => 'cancelled'
            ]);
        return $appointment->fresh([
            'patient.user',
            'doctor.user'
        ]);
    });
}

    //private function ensureSlotAvailable(Doctor $doctor, Carbon $appointmentDateTime)
    public function cancelAppointmentBySecretary(int $appointmentId): Appointment
    {
        $appointment = Appointment::findOrFail($appointmentId);

        if ($appointment->status !== 'scheduled') {
            throw new \Exception('لا يمكن إلغاء هذا الموعد إلا إذا كان مجدولاً');
        }

        $appointment->status = 'cancelled';
        $appointment->save();

        return $appointment;
    }

    private function ensureSlotAvailable(Doctor $doctor, Carbon $appointmentDateTime)
    {
        // 1. التحقق من الوقت في الماضي
        if ($appointmentDateTime->lt(Carbon::now())) {
            throw new \Exception("لا يمكن حجز موعد في الماضي");
        }

        $day = $this->normalizeDay($appointmentDateTime);

        // 2. التحقق من وجود جدول دوام في هذا اليوم
        $schedules = Doctor_Schedule::where('doctor_id', $doctor->id)
            ->where('day', $day)
            ->get();

        if ($schedules->isEmpty()) {
            throw new \Exception("الطبيب لا يعمل في هذا اليوم");
        }

        $isInSchedule = false;

        // 3. التحقق من مطابقة الوقت لـ Slots الدوام
        foreach ($schedules as $schedule) {
            $start = Carbon::parse($appointmentDateTime->toDateString() . ' ' . $schedule->start_time);
            $end = Carbon::parse($appointmentDateTime->toDateString() . ' ' . $schedule->end_time);

            // إذا كان الموعد قبل البداية أو يساوي/بعد نهاية الدوام
            if ($appointmentDateTime->lt($start) || $appointmentDateTime->gte($end)) {
                continue;
            }

            // التأكد أن الموعد يطابق تقسيم الـ 30 دقيقة
            $minutesDiff = $start->diffInMinutes($appointmentDateTime);
            if ($minutesDiff % 30 === 0) {
                $isInSchedule = true;
                break;
            }
        }

        if (!$isInSchedule) {
            throw new \Exception("الوقت المختار غير متاح ضمن دوام الطبيب");
        }

        // 4. التحقق من أن الموعد غير محجوز مسبقاً
        $exists = Appointment::where('doctor_id', $doctor->id)
            ->where('appointment_date', $appointmentDateTime)
            ->whereIn('status', ['scheduled', 'confirmed', 'completed'])
            ->exists();

        if ($exists) {
            throw new \Exception("هذا الموعد محجوز مسبقاً، الرجاء اختيار موعد آخر");
        }
    }


    private function createAppointment(int $patientId, int $doctorId, Carbon $appointmentDateTime, string $status): Appointment
    {
        return Appointment::create([
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'day' => $this->normalizeDay($appointmentDateTime),
            'appointment_date' => $appointmentDateTime,
            'status' => $status,
        ]);
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

    private function findOrCreatePatient(string $patientName, ?string $phoneNumber = null): Patient
    {
        if (!$phoneNumber) {
            
            throw new \Exception('رقم الهاتف مطلوب لإنشاء ملف مريض جديد');
        }

        $user = null;

        $user = User::where('phone_number', $phoneNumber)->first();

        if (!$user) {
            $user = User::create([
                'name' => $patientName,
                'phone_number' => $phoneNumber,
                'password' => Hash::make('11111111'),
                'is_verified' => true,
            ]);

            $user->assignRole('patient');
        }

        if ($user->patient) {
            return $user->patient;
        }

        return Patient::create([
            'user_id' => $user->id,
        ]);
    }
    public function getDoctorSchedulesRaw(?int $paramDoctorId = null)
    {
        $user = Auth::user();
        $doctorId = null;

        // 1. تحقق صريح: إذا كان المستخدم الحالي يحمل رول "doctor"
        if ($user->hasRole('doctor')) {           
            
            // الطبيب يستعرض مواعيده هو فقط تلقائياً
            $doctorId = $user->doctor->id;

        // 2. إذا كان المستخدم يحمل رول "secretary" (سكرتارية)
        } elseif ($user->hasRole('secretary')) {
            
            // السكرتارية مجبرة على إرسال الـ doctor_id
            if (!$paramDoctorId) {
                throw ValidationException::withMessages([
                    'doctor_id' => ['يجب تحديد معرف الطبيب المطلوب (doctor_id).']
                ]);
            }
            
            $doctorId = $paramDoctorId;

        // 3. حماية إضافية في حال تم الدخول من رول آخر بالخطأ مستقبلاً
        } else {
            throw ValidationException::withMessages([
                'error' => ['ليس لديك الصلاحية للوصول إلى هذه البيانات.']
            ]);
        }

        $dayOrder = ['sat', 'sun', 'mon', 'tues', 'wed', 'thy', 'fri'];

        return Doctor_Schedule::where('doctor_id', $doctorId)
            ->get()
            ->unique('day')
            ->sortBy(fn($schedule) => array_search($schedule->day, $dayOrder))
            ->values();
}

// ==================================================
    // ============ الدوال الجديدة - رول المريض ============
    // ==================================================

    /**
     * الموعد القادم للمريض (أقرب موعد ضمن الحالات النشطة scheduled/confirmed)
     */
    public function getNextAppointmentForPatient(int $patientId): ?array
    {
        $query = Appointment::with(['doctor.user'])
            ->where('patient_id', $patientId)
            ->whereIn('status', self::ACTIVE_STATUSES);

        $this->applyActiveWindowFilter($query, 'confirmed');

        $appointment = $query->orderBy('appointment_date')->first();

        return $appointment ? $this->formatPatientAppointment($appointment) : null;
    }

    /**
     * قائمة مواعيد المريض حسب الحالة (confirmed / scheduled / cancelled / completed)
     * الحالات النشطة (scheduled, confirmed) تُفلتَر بفترة سماح زمنية،
     * أما الحالات الأرشيفية (cancelled, completed) فتُعرض كاملة بدون قيد وقت
     */
    public function getPatientAppointmentsByStatus(int $patientId, string $status): array
    {
        $query = Appointment::with(['doctor.user'])
            ->where('patient_id', $patientId)
            ->where('status', $status);

        $this->applyActiveWindowFilter($query, $status);

        return $query->orderBy('appointment_date', 'desc')
            ->get()
            ->map(fn (Appointment $a) => $this->formatPatientAppointment($a))
            ->values()
            ->all();
    }

    /**
     * يطبّق فلتر النافذة الزمنية (فترة سماح) فقط إذا كانت الحالة من الحالات النشطة
     */
    private function applyActiveWindowFilter($query, string $status)
    {
        if (in_array($status, self::ACTIVE_STATUSES, true)) {
            $query->where('appointment_date', '>=', now()->subMinutes(self::GRACE_PERIOD_MINUTES));
        }

        return $query;
    }

    private function formatPatientAppointment(Appointment $appointment): array
    {
        return [
            'id' => $appointment->id,
            'doctor_id' => $appointment->doctor_id,
            'doctor_name' => $appointment->doctor?->user?->name,
            'appointment_date' => optional($appointment->appointment_date)->toDateTimeString(),
            'status' => $appointment->status,
        ];
    }
}
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\AppointmentService;
use Illuminate\Support\Facades\Auth;

class AppointmentController extends Controller
{
	public function __construct(private AppointmentService $service)
	{
	}

	

	public function bookBySecretary(Request $request)
	{
		$data = $request->validate([
			'patient_name' => 'nullable|string|min:2|max:255',
			'phone_number' => 'required|string|min:10',
			'doctor_id' => 'required|exists:doctors,id',
			'date' => 'required|date',
			'time' => 'required',
		]);

		$appointment = $this->service->bookSecretaryAppointment($data);

		return response()->json($appointment);
	}

	public function listAllDoctorsForSecretary()
	{
		$doctors = $this->service->getAllDoctorsForSecretary();

		return response()->json($doctors);
	}

	public function listDoctorsBySpecialization(Request $request)
	{
		$data = $request->validate([
			'specialization_id' => 'required|exists:specializations,id',
		]);

		$doctors = $this->service->getActiveDoctorsBySpecialization((int) $data['specialization_id']);

		return response()->json($doctors);
	}

	public function availableSlotsByDoctor(int $doctorId, Request $request)
	{
		$data = $request->validate([
			'days' => 'nullable|integer|min:1|max:30',
		]);

		$days = (int) ($data['days'] ?? 10);
		$slots = $this->service->getAvailableSlotsForDoctorId($doctorId, $days);

		return response()->json($slots);
	}
	
// للحذف لاحقاً
	public function availableSlotsByDoctor7Days(int $doctorId)
	{
		$slots = $this->service->getAvailableSlotsForDoctorId($doctorId, 7);

		return response()->json($slots);
	}

	public function listScheduledBySecretary(Request $request)
	{
		$data = $request->validate([
			'date' => 'required|date',
			'limit' => 'nullable|integer|min:1|max:500',
		]);

		$appointments = $this->service->getSecretaryAppointmentsByStatus(
			'scheduled',
			$data['date'],
			null,
			null,
			(int) ($data['limit'] ?? 500)
		);

		return response()->json($appointments);
	}

	public function listConfirmedBySecretary(Request $request)
	{
		$data = $request->validate([
			'date' => 'required|date',
			'limit' => 'nullable|integer|min:1|max:500',
		]);

		$appointments = $this->service->getSecretaryAppointmentsByStatus(
			'confirmed',
			$data['date'],
			null,
			null,
			(int) ($data['limit'] ?? 200)
		);

		return response()->json($appointments);
	}

	public function confirmBySecretary(int $appointmentId)
	{
		try {

			$appointment = $this->service
				->confirmAppointmentBySecretary($appointmentId);

			return response()->json([
				'success' => true,
				'message' => 'تم تأكيد الموعد بنجاح.',
				'data' => $appointment,
			]);

		} catch (\Exception $e) {

			return response()->json([
				'success' => false,
				'message' => $e->getMessage(),
			], 422);
		}
	}

	public function cancelBySecretary(int $appointmentId)
	{
		$this->service->cancelAppointmentBySecretary($appointmentId);

		return response()->json([
			'message' => 'تم إلغاء الموعد بنجاح'
		]);
	}

	public function getAvailableSlots(Request $request)
    {
        $request->validate([
            'doctor_id' => 'nullable|integer',
        ]);

        // جلب المواعيد الخام من السيرفس
        $schedules = $this->service->getDoctorSchedulesRaw($request->query('doctor_id'));

        // إرجاع المصفوفة مباشرة لتكون النتيجة مطابقة تماماً للـ JSON المطلوب
        return response()->json($schedules, 200);
    }
	
public function myNextAppointment(Request $request)
{
    $patientId = Auth::user()->patient->id;
    $appointment = $this->service->getNextAppointmentForPatient($patientId);

    return response()->json([
        'status' => 'success',
        'data' => $appointment,
    ]);
}

public function myConfirmedAppointments(Request $request)
{
    $patientId = Auth::user()->patient->id;
    $appointments = $this->service->getPatientAppointmentsByStatus($patientId, 'confirmed');

    return response()->json(['status' => 'success', 'data' => $appointments]);
}

public function myPendingAppointments(Request $request)
{
    $patientId = Auth::user()->patient->id;
    $appointments = $this->service->getPatientAppointmentsByStatus($patientId, 'scheduled');

    return response()->json(['status' => 'success', 'data' => $appointments]);
}

public function myCancelledAppointments(Request $request)
{
    $patientId = Auth::user()->patient->id;
    $appointments = $this->service->getPatientAppointmentsByStatus($patientId, 'cancelled');

    return response()->json(['status' => 'success', 'data' => $appointments]);
}


}

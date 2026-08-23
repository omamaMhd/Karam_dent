<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\SecretaryService;

class SecretaryController extends Controller
{
    public function __construct(private SecretaryService $service)
    {
    }

    public function doctorPatients(int $doctorId)
    {
        return response()->json(
            $this->service->getDoctorPatients($doctorId)
        );
    }

    public function doctorTodayAppointments(int $doctorId)
    {
        return response()->json(
            $this->service->getDoctorTodayAppointments($doctorId)
        );
    }

    
    public function createPatient(Request $request)
    {
        $data = $request->validate([
            'name' => 'nullable|string',
            'phone_number' => 'required|string|unique:users,phone_number',
        ]);

        return response()->json(
            $this->service->createPatientBySecretary(
                $data['name'],
                $data['phone_number']
            )
        );
    }

}

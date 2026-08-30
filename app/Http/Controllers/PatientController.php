<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\PatientServices;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Auth;

class PatientController extends Controller
{
    protected $service;

    public function __construct(PatientServices $service)
    {
        $this->service = $service;
    }
    public function listAllPatients()
{
    return response()->json(
        $this->service->getAllPatients()
    );
}
    public function getAvailableSlotsForDays($doctorId)
{
    return response()->json(
        $this->service->getAvailableSlotsForDays($doctorId)
    );
}

// حجز موعد
 public function bookAppointment(Request $request)
{
    
    $data = $request->validate([
        // 'specialization_id' => 'required|exists:specializations,id',
          'doctor_id' => 'required|exists:doctors,id',
        'date' => 'required|date',
        'time' => 'required'
    ]);
     $patientId = Auth::user()->patient->id;

    $appointment = $this->service->bookAppointment(
       // auth()->id(),
       $patientId ,
        $data
    );

    return response()->json($appointment);
}

}

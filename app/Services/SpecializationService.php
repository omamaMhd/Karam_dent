<?php
namespace App\Services;

use App\Models\Specialization;
use App\Models\Doctor;

class SpecializationService
{

    public function getAllSpecializations()
    {
        return Specialization::select('id', 'name')->get();
    }


    public function getSpecializationDetails($id)
    {
        $specialization = Specialization::findOrFail($id);

        // جلب الدكتور مع اليوزر
        $doctors = Doctor::with('user')
            ->where('specialization_id', $id)
            ->where('is_active', true)
               ->get();

        return [
            'id' => $specialization->id,
            'name' => $specialization->name,
            'description' => $specialization->description,

            'doctors' => $doctors->map(function ($doctor) {
                return [
                    'id' => $doctor->id,
                    'name' => $doctor->user->name,
                ];
            })
        ];
    }
    public function getDoctorsBySpecialization($id)
    {
         $specialization = Specialization::findOrFail($id);
        $doctors = Doctor::with('user')
            ->where('specialization_id', $id)
            ->where('is_active', true)
            ->get();
        return $doctors->map(function ($doctor) 
        {
                return [
                    'id' => $doctor->id,
                    'name' => $doctor->user->name,
                ];
            });
            
    }


    public function getActiveDoctor($specializationId)
    {
        $doctor = Doctor::with('user')
            ->where('specialization_id', $specializationId)
            ->where('is_active', true)
            ->first();

        return $doctor ? [
            'id' => $doctor->id,
            'name' => $doctor->user->name,
        ] : null;
    }



    public function getSpecializationsWithDoctors()
    {
        return Specialization::with(['doctors.user'])->get()->map(function ($spec) {

            return [
                'id' => $spec->id,
                'name' => $spec->name,

                'doctors' => $spec->doctors
                    ->where('is_active', true)
                    ->map(function ($doctor) {
                        return [
                            'id' => $doctor->id,
                            'name' => $doctor->user->name,
                        ];
                    })->values()
            ];
        });
    }
    
    
}

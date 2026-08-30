<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Services\SpecializationService;
class SpecializationController extends Controller
{
     protected $service;

    public function __construct(SpecializationService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        return response()->json(
            $this->service->getAllSpecializations()
        );
    }

    public function show($id)
    {
        return response()->json(
            $this->service->getSpecializationDetails($id)
        );
    }

    public function getDoctorsBySpecialization($id)
    {
        return response()->json(
            $this->service->getDoctorsBySpecialization($id)
        );
     }
}

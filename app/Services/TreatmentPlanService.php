<?php

namespace App\Services;

use App\Models\Plan_Item;
use App\Models\Treatment_Plan;
use App\Models\Treatment_Session;
use App\Models\Doctor_Earning;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TreatmentPlanService
{
    public function __construct(private ExchangeRateService $exchangeRateService)
    {
    }

    public function createPlan(array $data)
    {
        $doctor = Auth::user()->doctor;

        if (!$doctor) {
            return ['success' => false, 'message' => "هذا المستخدم ليس دكتور"];
        }

        $usdProvided = !empty($data['price_usd']);
        $sypProvided = !empty($data['price_syp']);

        if (!$usdProvided && !$sypProvided) {
            return ['success' => false, 'message' => 'يجب إدخال سعر الخطة بالدولار أو الليرة'];
        }

        $rateRecord = $this->exchangeRateService->getCurrentUsdToSypRate();
        $rate = (float) $rateRecord->rate;

        if ($usdProvided && !$sypProvided) {
            $data['price_syp'] = round((float) $data['price_usd'] * $rate, 2);
        } elseif (!$usdProvided && $sypProvided && $rate > 0) {
            $data['price_usd'] = round((float) $data['price_syp'] / $rate, 2);
        }

        return DB::transaction(function () use ($data, $doctor, $rateRecord) {
            $plan = Treatment_Plan::create([
                'patient_id'       => $data['patient_id'],
                'doctor_id'        => $doctor->id,
                'name'             => $data['name'],
                'start_date'       => $data['start_date'] ?? now(),
                'notes'            => $data['notes'] ?? null,
                'exchange_rate_id' => $rateRecord->id,
                'price_usd'        => $data['price_usd'],
                'price_syp'        => $data['price_syp'],
                'target_teeth'     => $data['target_teeth'] ?? null,
            ]);

            $invoice = app(\App\Services\InvoiceService::class)
                ->createPatientInvoice([
                    'patient_id' => $plan->patient_id,
                    'plan_id'    => $plan->id,
                    'amount_usd' => $plan->price_usd,
                ]);

            if ($invoice->type !== 'patient') {
                throw new \Exception('Invoice type must be patient');
            }

            return $plan->load('items.category', 'items.sessions')
                ->makeHidden('doctor')
                ->setRelation('invoice', $invoice);
        });
    }

    public function updatePlan(int $planId, array $data)
    {
        $doctor = Auth::user()->doctor;

        if (!$doctor) {
            throw new \Exception('هذا المستخدم ليس دكتور');
        }

        $plan = Treatment_Plan::where('id', $planId)
            ->where('doctor_id', $doctor->id)
            ->firstOrFail();

        if ($plan->status === 'completed' || $plan->is_locked) {
            throw new \DomainException('الخطة مكتملة ومقفلة ولا يمكن تعديلها');
        }

        return DB::transaction(function () use ($plan, $data) {
            $updates = [];

            foreach (['name', 'start_date', 'target_teeth'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[$field] = $data[$field];
                }
            }

            $usdProvided = array_key_exists('price_usd', $data) && !is_null($data['price_usd']);
            $sypProvided = array_key_exists('price_syp', $data) && !is_null($data['price_syp']);

            if ($usdProvided || $sypProvided) {
                $rateRecord = $this->exchangeRateService->getCurrentUsdToSypRate();
                $rate = (float) $rateRecord->rate;

                $updates['exchange_rate_id'] = $rateRecord->id;
                $updates['price_usd'] = $usdProvided ? $data['price_usd'] : round((float) $data['price_syp'] / $rate, 2);
                $updates['price_syp'] = $sypProvided ? $data['price_syp'] : round((float) $data['price_usd'] * $rate, 2);
            }

            if ($updates) {
                $plan->update($updates);
                $this->syncDoctorEarning($plan->fresh());
            }

            return $plan->load('items.category', 'items.sessions')
                ->makeHidden('doctor');
        });
    }

    public function searchPlans(array $filters)
    {
        $scope = $this->resolvePlanAccessScope();
        $query = $this->scopedPlansQuery($scope);

        if ($scope['role'] === 'doctor' && !empty($filters['phone_number'])) {
            $query->whereHas('patient.user', function ($q) use ($filters) {
                $q->where('phone_number', $filters['phone_number']);
            });
        }

        $name = $filters['patient_name'] ?? $filters['name'] ?? null;
        if ($scope['role'] === 'doctor' && !empty($name)) {
            $query->whereHas('patient.user', function ($q) use ($name) {
                $q->where('name', 'like', "%{$name}%");
            });
        }

        return $query
            ->orderByDesc('start_date')
            ->get()
            ->map(fn(Treatment_Plan $plan) => [
                'id'               => $plan->id,
                'name'             => $plan->name,
                'status'           => $plan->status,
                'progress_percent' => $plan->progress_percent,
            ])
            ->values();
    }


    public function getPatientPlans(int $patientId)
    {
        $this->authorizePatientAccess($patientId);

        return Treatment_Plan::where('patient_id', $patientId)
            ->orderByDesc('start_date')
            ->get()
            ->map(fn(Treatment_Plan $plan) => [
                'id'               => $plan->id,
                'name'             => $plan->name,
                'status'           => $plan->status,
                'progress_percent' => $plan->progress_percent,
            ])
            ->values();
    }
    public function getPlanDetails(int $planId)
    {
        $plan = Treatment_Plan::with('items.category')
            ->findOrFail($planId);

        $this->authorizePatientAccess($plan->patient_id);

        return [
            'id'               => $plan->id,
            'name'             => $plan->name,
            'status'           => $plan->status,
            'price_usd'        => $plan->price_usd,
            'price_syp'        => $plan->price_syp,
            'target_teeth'     => $plan->target_teeth,
            'start_date'       => $plan->start_date,
            'progress_percent' => $plan->progress_percent,

            'items' => $plan->items->map(fn(Plan_Item $item) => [
                'id'            => $item->id,
                'category_name' => $item->category?->name,
                'status'        => $item->status,
            ])->values(),
        ];
    }
    public function getPlanItemDetails(int $itemId)
    {
        $item = Plan_Item::with('category', 'sessions', 'plan')
            ->findOrFail($itemId);

        $this->authorizePatientAccess($item->plan->patient_id);

        return [
            'id'            => $item->id,
            'category_id'   => $item->category_id,
            'category_name' => $item->category?->name,
            'notes'         => $item->notes,
            'status'        => $item->status,

            'sessions' => $item->sessions->map(
                fn(Treatment_Session $session) => [
                    'id'     => $session->id,
                    'name'   => $session->name,
                    'status' => $session->status,
                ]
            )->values(),
        ];
    }
    public function getSessionDetails(int $sessionId)
    {
        $session = Treatment_Session::with(
            'appointment',
            'planItem.plan'
        )->findOrFail($sessionId);

        $this->authorizePatientAccess(
            $session->planItem->plan->patient_id
        );

        return [
            'id'           => $session->id,
            'name'         => $session->name,
            'status'       => $session->status,
            'session_date' => $session->session_date,

            'appointment' => $session->appointment ? [
                'id'               => $session->appointment->id,
                'appointment_date' => $session->appointment->appointment_date,
                'status'           => $session->appointment->status,
            ] : null,
        ];
    }


    public function addPlanItem(int $planId, array $data)
    { 
        $doctor = Auth::user()->doctor;

        if (!$doctor) {
            throw new \Exception('هذا المستخدم ليس دكتور');
        }

        $plan = Treatment_Plan::where('id', $planId)
            ->where('doctor_id', $doctor->id)
            ->firstOrFail();

        if ($plan->is_locked) {
            throw new \DomainException(
                'الخطة مكتملة ومقفلة ولا يمكن إضافة مراحل جديدة'
            );
        }
        $item = Plan_Item::create([
            'plan_id'     => $plan->id,
            'category_id' => $data['category_id'],
            'notes'       => $data['notes'] ?? null,
            'status'      => 'in_progress',
        ]);

        return $item->load('category', 'sessions');
    }

    public function updatePlanItem(int $planId, int $itemId, array $data)
    {
        $doctor = Auth::user()->doctor;

        if (!$doctor) {
            throw new \Exception('هذا المستخدم ليس دكتور');
        }

        $plan = Treatment_Plan::where('id', $planId)
            ->where('doctor_id', $doctor->id)
            ->firstOrFail();

            if ($plan->is_locked) {
            throw new \DomainException(
                'الخطة مكتملة ومقفلة ولا يمكن تعديل المراحل'
            );
        }

        $item = Plan_Item::where('plan_id', $plan->id)
            ->where('id', $itemId)
            ->firstOrFail();

        $updates = [];

        foreach (['category_id', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        if ($updates) {
            $item->update($updates);
        }

        return $item->fresh()->load('category', 'sessions');
    }

    public function deletePlanItem(int $planId, int $itemId)
    {
        $doctor = Auth::user()->doctor;

        if (!$doctor) {
                return [
                        'success' => false,
                        'message' => "هذا المستخدم ليس دكتور"
                    ];
        }

        $plan = Treatment_Plan::where('id', $planId)
            ->where('doctor_id', $doctor->id)
            ->firstOrFail();

        if ($plan->is_locked) {
            throw new \DomainException(
                'الخطة مكتملة ومقفلة ولا يمكن حذف المراحل'
            );
        }

        $item = Plan_Item::where('plan_id', $plan->id)
            ->where('id', $itemId)
            ->firstOrFail();

        $item->delete();

        return ['message' => 'تم حذف المرحلة بنجاح'];
    }

    private function applyItemExchangeRate(array $data): array
    {
        $hasPriceContext = array_key_exists('price_usd', $data) || array_key_exists('price_syp', $data);

        if (!$hasPriceContext) {
            return $data;
        }

        $usdProvided = array_key_exists('price_usd', $data) && !is_null($data['price_usd']);
        $sypProvided = array_key_exists('price_syp', $data) && !is_null($data['price_syp']);

        if (!$usdProvided && !$sypProvided) {
            return $data;
        }

        $rateRecord = $this->exchangeRateService->getCurrentUsdToSypRate();
        $rate = (float) $rateRecord->rate;

        if ($usdProvided && !$sypProvided) {
            $data['price_syp'] = round(((float) $data['price_usd']) * $rate, 2);
        }

        if (!$usdProvided && $sypProvided && $rate > 0) {
            $data['price_usd'] = round(((float) $data['price_syp']) / $rate, 2);
        }

        return $data;
    }

    private function resolvePlanAccessScope(): array
    {
        $user = Auth::user();

        if ($user->hasRole('secretary')) {
            return ['role' => 'secretary', 'column' => null, 'id' => null];
        }

        if ($user->hasRole('doctor')) {
            $doctor = $user->doctor;
            if (!$doctor) {
                throw new \Exception('هذا المستخدم ليس دكتور');
            }
            return ['role' => 'doctor', 'column' => 'doctor_id', 'id' => $doctor->id];
        }

        if ($user->hasRole('patient')) {
            $patient = $user->patient;
            if (!$patient) {
                throw new \Exception('هذا المستخدم ليس مريض');
            }
            return ['role' => 'patient', 'column' => 'patient_id', 'id' => $patient->id];
        }

        throw new \Exception('غير مصرح');
    }



    private function authorizePatientAccess(int $patientId): void
    {
        $user = Auth::user();

        if ($user->hasRole('secretary')) {
            return;
        }

        if ($user->hasRole('patient')) {
            if ($user->patient?->id !== $patientId) {
                throw new \Exception('لا تملك صلاحية الوصول');
            }
            return;
        }

        if ($user->hasRole('doctor')) {
            $doctorId = $user->doctor?->id;
            if (!$doctorId) {
                throw new \Exception('هذا المستخدم ليس دكتور');
            }

            $hasAccess = Treatment_Plan::where('doctor_id', $doctorId)
                ->where('patient_id', $patientId)
                ->exists();

            if (!$hasAccess) {
                throw new \Exception('هذا المريض ليس من مرضاك');
            }
            return;
        }

        throw new \Exception('غير مصرح');
    }
    private function syncDoctorEarning(Treatment_Plan $plan): void
    {
        if ($plan->status !== 'completed') {
            Doctor_Earning::where('treatment_plans_id', $plan->id)->delete();
            return;
        }

        $plan->loadMissing('doctor', 'exchangeRate');
        $doctor = $plan->doctor;

        if (!$doctor) {
            return;
        }

        $percentage = (float) ($doctor->percentage ?? 0);
        $amountUsd = !is_null($plan->price_usd)
            ? round(((float) $plan->price_usd) * $percentage / 100, 2)
            : 0;

        $exchangeRate = $plan->exchangeRate ?? ($plan->exchange_rate_id ? $plan->exchangeRate()->first() : null);
        if (!$exchangeRate) {
            $exchangeRate = $this->exchangeRateService->getCurrentUsdToSypRate();
        }

        $amountSyp = !is_null($plan->price_syp)
            ? round(((float) $plan->price_syp) * $percentage / 100, 2)
            : null;

        if (is_null($amountSyp) && $exchangeRate) {
            $amountSyp = round($amountUsd * (float) $exchangeRate->rate, 2);
        }

        Doctor_Earning::updateOrCreate(
            ['treatment_plans_id' => $plan->id],
            [
                'doctor_id'        => $doctor->id,
                'exchange_rate_id' => $exchangeRate?->id,
                'percentage'       => $percentage,
                'amount_usd'       => $amountUsd,
                'amount_syp'       => $amountSyp,
                'earning_date'     => $plan->start_date ?? now(),
            ]
        );
    }
}
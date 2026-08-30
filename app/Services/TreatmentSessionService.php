<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Doctor_Earning;
use App\Models\Plan_Item;
use App\Models\Treatment_Plan;
use App\Models\Treatment_Session;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class TreatmentSessionService
{
    public function __construct(private ExchangeRateService $exchangeRateService)
    {
        
    }

    public function createSession(array $data)
    {
        $doctor = Auth::user()->doctor;

        if (!$doctor) {
            throw new \Exception('هذا المستخدم ليس دكتور');
        }

        $planItem = Plan_Item::with('plan')
            ->where('id', $data['plan_item_id'])
            ->firstOrFail();

        if ($planItem->plan->status === 'completed' || $planItem->plan->is_locked) {
            throw new \DomainException('الخطة مكتملة ومقفلة ولا يمكن إضافة جلسة');
        }

        if ($planItem->plan->doctor_id !== $doctor->id) {
            throw new \Exception('لا تملك صلاحية إنشاء جلسة لهذا العنصر');
        }

        $session = Treatment_Session::create([
            'plan_item_id'   => $planItem->id,
            'appointment_id' => null,
            'name'           => $data['name'] ?? null,
            'session_date'   => null,
            'status'         => 'in_progress',
        ]);

        $this->syncStatuses($planItem);

        return $session;
    }

    public function updateSession(int $sessionId, array $data)
    {
        $doctor = Auth::user()->doctor;

        if (!$doctor) {
            throw new \Exception('هذا المستخدم ليس دكتور');
        }

        $session = Treatment_Session::with('planItem.plan')
            ->where('id', $sessionId)
            ->firstOrFail();

        if ($session->planItem->plan->doctor_id !== $doctor->id) {
            throw new \Exception('لا تملك صلاحية تعديل هذه الجلسة');
        }

        if ($session->planItem->plan->status === 'completed' || $session->planItem->plan->is_locked) {
            throw new \DomainException('الخطة مكتملة ومقفلة ولا يمكن تعديل الجلسات');
        }

        if ($session->status === 'completed') {
            throw new \DomainException('لا يمكن تعديل الجلسة بعد إنهائها');
        }

        $updates = [];

        if (array_key_exists('name', $data)) {
            $updates['name'] = $data['name'];
        }

        if ($updates) {
            $session->update($updates);
        }

        return $session->fresh();
    }

    public function completeSession(int $sessionId)
    {
        $doctor = Auth::user()->doctor;

        if (!$doctor) {
            return [
                'success' => false,
                'message' => "هذا المستخدم ليس دكتور"
            ];
           // throw new \Exception('هذا المستخدم ليس دكتور');
        }

        $session = Treatment_Session::with('planItem.plan')
            ->where('id', $sessionId)
            ->firstOrFail();

        if ($session->planItem->plan->doctor_id !== $doctor->id) {
                return [
                    'success' => false,
                    'message' => "لا تملك صلاحية إنهاء هذه الجلسة"
                ];
           // throw new \Exception('لا تملك صلاحية إنهاء هذه الجلسة');
        }

        if ($session->status === 'completed') {
                return [
                    'success' => false,
                    'message' => "هذه الجلسة منتهية مسبقا"
                ];
            //throw new \DomainException('هذه الجلسة منتهية مسبقا');
            throw new \DomainException('هذه الجلسة منتهية مسبقاً');
        }

        $appointment = $this->findConfirmedAppointmentForToday($session->planItem);

        $session->update([
            'status'         => 'completed',
            'appointment_id' => $appointment?->id,
            'session_date'   => $appointment
                ? $appointment->appointment_date->toDateString()
                : now()->toDateString(),
        ]);

        if ($appointment) {
            $appointment->update([
                'status' => 'completed'
            ]);
        }

        $this->syncStatuses($session->planItem);

        return $session->fresh();
    }

    private function applyExchangeRate(array $data): array
    {
        $hasPriceContext = array_key_exists('rprice_usd', $data) || array_key_exists('rprice_syp', $data);

        if (!$hasPriceContext) {
            return $data;
        }

        $usdProvided = array_key_exists('rprice_usd', $data) && !is_null($data['rprice_usd']);
        $sypProvided = array_key_exists('rprice_syp', $data) && !is_null($data['rprice_syp']);

        if (!$usdProvided && !$sypProvided) {
            return $data;
        }

        $rateRecord = $this->exchangeRateService->getCurrentUsdToSypRate();
        $rate = (float) $rateRecord->rate;
        $data['exchange_rate_id'] = $rateRecord->id;

        if ($usdProvided && !$sypProvided) {
            $data['rprice_syp'] = round(((float) $data['rprice_usd']) * $rate, 2);
        }

        if (!$usdProvided && $sypProvided && $rate > 0) {
            $data['rprice_usd'] = round(((float) $data['rprice_syp']) / $rate, 2);
        }

        return $data;
    }

    private function syncDoctorEarning(Treatment_Plan $plan): void
    {
        // Keep earnings table consistent with the plan completion state.
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
            ? round(((float) $plan->price_usd * $percentage) / 100, 2)
            : 0;

        $exchangeRate = $plan->exchangeRate ?? ($plan->exchange_rate_id ? $plan->exchangeRate()->first() : null);
        if (!$exchangeRate) {
            $exchangeRate = $this->exchangeRateService->getCurrentUsdToSypRate();
        }

        $amountSyp = !is_null($plan->price_syp)
            ? round(((float) $plan->price_syp * $percentage) / 100, 2)
            : null;

        if (is_null($amountSyp) && $exchangeRate) {
            $amountSyp = round($amountUsd * (float) $exchangeRate->rate, 2);
        }

        Doctor_Earning::updateOrCreate(
            ['treatment_plans_id' => $plan->id],
            [
                'doctor_id' => $doctor->id,
                'exchange_rate_id' => $exchangeRate?->id,
                'percentage' => $percentage,
                'amount_usd' => $amountUsd,
                'amount_syp' => $amountSyp,
                'earning_date' => $plan->start_date ?? now(),
            ]
        );
    }


    private function syncStatuses(Plan_Item $planItem): void
    {
        $hasOpen = Treatment_Session::where('plan_item_id', $planItem->id)
            ->where('status', '!=', 'completed')
            ->exists();

        $hasSessions = Treatment_Session::where('plan_item_id', $planItem->id)->exists();

        $planItem->update([
            'status' => ($hasSessions && !$hasOpen) ? 'completed' : 'in_progress',
        ]);

        $plan = $planItem->plan;

        $hasOpenItems = Plan_Item::where('plan_id', $plan->id)
            ->where('status', '!=', 'completed')
            ->exists();

        $planStatus = $plan->is_locked ? 'completed' : ($hasOpenItems ? 'in_progress' : 'completed');

        $plan->update([
            'status' => $planStatus,
            'is_locked' => $plan->is_locked || !$hasOpenItems,
        ]);

        $this->syncDoctorEarning($plan->fresh());
    }

    private function findConfirmedAppointmentForToday(Plan_Item $planItem): ?Appointment
    {
        $appointments = Appointment::where('patient_id', $planItem->plan->patient_id)
            ->where('doctor_id', $planItem->plan->doctor_id)
            ->whereDate('appointment_date', Carbon::today())
            ->where('status', 'confirmed')
            ->orderBy('appointment_date')
            ->get(['id', 'appointment_date', 'status']);

        foreach ($appointments as $appointment) {
            $isLinked = Treatment_Session::where('appointment_id', $appointment->id)->exists();
            if (!$isLinked) {
                return $appointment;
            }
        }

        return null;
    }
}
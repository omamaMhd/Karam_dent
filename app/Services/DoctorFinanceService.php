<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\Doctor_Earning;
use App\Models\Doctor_Payment;
use Illuminate\Support\Facades\Auth;
use App\Models\Treatment_Plan;

class DoctorFinanceService
{
    public function __construct(private ExchangeRateService $exchangeRateService)
    {
    }

    public function recordPayment(int $doctorId, array $data)
    {
        $doctor = Doctor::findOrFail($doctorId);

        $amountUsdProvided = array_key_exists('amount_usd', $data) && !is_null($data['amount_usd']);
        $amountSypProvided = array_key_exists('amount_syp', $data) && !is_null($data['amount_syp']);

        if (!$amountUsdProvided && !$amountSypProvided) {
            
            throw new \InvalidArgumentException('يجب إرسال مبلغ بالدولار أو بالليرة على الأقل');
        }

        $amountUsd = $amountUsdProvided ? (float) $data['amount_usd'] : null;
        $amountSyp = $amountSypProvided ? (float) $data['amount_syp'] : null;
        $rateRecord = $this->exchangeRateService->getCurrentUsdToSypRate();
        $rate = (float) $rateRecord->rate;

        if (($amountUsdProvided && !$amountSypProvided) || (!$amountUsdProvided && $amountSypProvided)) {
            if ($amountUsdProvided && !$amountSypProvided) {
                $amountSyp = round($amountUsd * $rate, 2);
            }

            if (!$amountUsdProvided && $amountSypProvided && $rate > 0) {
                $amountUsd = round($amountSyp / $rate, 2);
            }
        }

        if (!is_null($amountUsd) && $amountUsd <= 0) {
            throw new \DomainException('المبلغ يجب أن يكون أكبر من صفر');
        }

        $remainingUsd = $this->getRemainingUsd($doctorId);

        if (!is_null($amountUsd) && ($amountUsd - $remainingUsd) > 0.01) {
            $remainingLabel = $this->formatMoney($remainingUsd);
            throw new \DomainException("المتبقي للدكتور بالدولار فقط {$remainingLabel}");
        }

        $payment = Doctor_Payment::create([
            'doctor_id' => $doctor->id,
            'exchange_rate_id' => $rateRecord->id,
            'amount_usd' => $amountUsd,
            'amount_syp' => $amountSyp ?? round($amountUsd * $rate, 2),
            'payment_date' => $data['payment_date'] ?? now(),
        ]);

        return [
            'id' => $payment->id,
            'doctor_id' => $payment->doctor_id,
            'doctor_name' => $doctor->user?->name,
            'exchange_rate_id' => $payment->exchange_rate_id,
            'exchange_rate' => $rateRecord->rate,
            'amount_usd' => $payment->amount_usd,
            'amount_syp' => $payment->amount_syp,
            'payment_date' => $payment->payment_date,
            'updated_at' => $payment->updated_at,
            'created_at' => $payment->created_at,
        ];
    }

    private function getRemainingUsd(int $doctorId): float
    {
        $totalDueUsd = (float) Doctor_Earning::where('doctor_id', $doctorId)->sum('amount_usd');
        $totalPaidUsd = (float) Doctor_Payment::where('doctor_id', $doctorId)->sum('amount_usd');

        return max($totalDueUsd - $totalPaidUsd, 0);
    }

    private function formatMoney(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }

    public function getDoctorSummary(int $doctorId)
    {
        Doctor::withTrashed()->findOrFail($doctorId);

        $totalDueUsd = (float) Doctor_Earning::where('doctor_id', $doctorId)->sum('amount_usd');
        $totalPaidUsd = (float) Doctor_Payment::where('doctor_id', $doctorId)->sum('amount_usd');

        $totalDueSyp = (float) Doctor_Earning::where('doctor_id', $doctorId)->sum('amount_syp');
        $totalPaidSyp = (float) Doctor_Payment::where('doctor_id', $doctorId)->sum('amount_syp');

        // معالجة الـ earning اللي ما عندها syp
        $missingDueSyp = Doctor_Earning::where('doctor_id', $doctorId)
            ->whereNull('amount_syp')
            ->with('exchangeRate')
            ->get();

        foreach ($missingDueSyp as $earning) {
            $rate = $earning->exchangeRate?->rate;
            if ($rate) {
                $totalDueSyp += round((float) $earning->amount_usd * (float) $rate, 2);
            }
        }

        // معالجة الـ payment اللي ما عندها syp
        $missingPaidSyp = Doctor_Payment::where('doctor_id', $doctorId)
            ->whereNull('amount_syp')
            ->with('exchangeRate')
            ->get();

        foreach ($missingPaidSyp as $payment) {
            $rate = $payment->exchangeRate?->rate;
            if ($rate) {
                $totalPaidSyp += round((float) $payment->amount_usd * (float) $rate, 2);
            }
        }

        $rateRecord = $this->exchangeRateService->getCurrentUsdToSypRate();
        $rate = (float) $rateRecord->rate;

        $remainingUsd = max($totalDueUsd - $totalPaidUsd, 0);

        return [
            'doctor_id' => $doctorId,
            'totals' => [
                'due_usd'       => $totalDueUsd,
                'due_syp'       => $totalDueSyp,
                'paid_usd'      => $totalPaidUsd,
                'paid_syp'      => $totalPaidSyp,
                'remaining_usd' => $remainingUsd,
                'remaining_syp' => round($remainingUsd * $rate, 2),
            ],
        ];
    }

    public function getMySummary()
    {
        $doctor = Auth::user()->doctor;

        if (!$doctor) {
            return [
                'success' => false,
                'message' => "هذا المستخدم ليس دكتور"
            ];
            //throw new \Exception('هذا المستخدم ليس دكتور');
        }

        return $this->getDoctorSummary($doctor->id);
    }

    public function getDoctorPlansDues(int $doctorId)
    {
        $doctor = Auth::user()->doctor;

        $plans = Treatment_Plan::with('patient')
            ->where('doctor_id', $doctorId)
            ->orderByDesc('created_at') // الأحدث أولاً
            ->get();

        return $plans->map(function ($plan) {

            return [
                'plan_id' => $plan->id,
                'name' => $plan->name,

                'patient' => [
                    'id' => $plan->patient?->id,
                    'name' => $plan->patient?->user?->name,
                ],

                'price_usd' => $plan->price_usd,
                'price_syp' => $plan->price_syp,

                'status' => $plan->status,
            ];
        });
    }

    public function getPaymentForPdf(int $paymentId): array
    {
        $payment = Doctor_Payment::with(['doctor' => function($query) {
            $query->withTrashed()->with(['user' => function($q) {
            $q->withTrashed();
        }]);
        }, 'exchangeRate'])->findOrFail($paymentId);

        return [
            'id'            => $payment->id,
            'doctor_name'   => $payment->doctor?->user?->name,
            'exchange_rate' => $payment->exchangeRate?->rate,
            'amount_usd'    => $payment->amount_usd,
            'amount_syp'    => $payment->amount_syp,
            'payment_date'  => $payment->payment_date,
        ];
    }

    public function getCenterDoctorsSummary(): array
    {
        $totalDueUsd = (float) Doctor_Earning::sum('amount_usd');
        $totalPaidUsd = (float) Doctor_Payment::sum('amount_usd');

        $totalDueSyp = (float) Doctor_Earning::sum('amount_syp');
        $totalPaidSyp = (float) Doctor_Payment::sum('amount_syp');

        $remainingUsd = max($totalDueUsd - $totalPaidUsd, 0);
        $remainingSyp = max($totalDueSyp - $totalPaidSyp, 0);

        return [
            'totals' => [
                'due_usd'       => round($totalDueUsd, 2),
                'due_syp'       => round($totalDueSyp, 2),

                'paid_usd'      => round($totalPaidUsd, 2),
                'paid_syp'      => round($totalPaidSyp, 2),

                'remaining_usd' => round($remainingUsd, 2),
                'remaining_syp' => round($remainingSyp, 2),
            ],
        ];
    }


}

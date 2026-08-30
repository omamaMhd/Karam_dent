<?php
namespace App\Services;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Treatment_Plan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Events\InvoiceApproved; 
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
class InvoiceService
{

    public function getAll()
{
  return Invoice::where('type', 'supplier')
    ->with(['items', 'supplier'])
    ->orderByDesc('created_at')
    ->paginate(20);  
}

//عرض فواتير المرضى
public function getAllPatientInvoices()
{
    return Invoice::where('type', 'patient')
        ->with([
            'payments',     // الدفعات
            'plans'          // الخطة (اختياري)
        ])
        ->orderByDesc('created_at')
         ->paginate(20);        
}

//اعتماد الفاتورة بس للموردين
public function approve($id)
{
    $invoice = Invoice::findOrFail($id);

    if ($invoice->type !== 'supplier') {
        return [
            'success' => false,
            'message' => "Only supplier invoices can be approved"
        ];
   // throw new \Exception("Only supplier invoices can be approved");
}
    if ($invoice->status !== 'draft') {
        return [
            'success' => false,
            'message' => "Only draft invoices can be approved"
        ];
      //  throw new \Exception("Only draft invoices can be approved");
    }

    $invoice->update([
        'status' => 'issued'
    ]);
     event(new InvoiceApproved($invoice)); 

    return $invoice;
}

public function payInvoice($invoiceId, $amount , string $idempotencyKey)
{
    // 🔑 أول شي: هل هاد المفتاح انعالج قبل؟ إذا إيه رجّعي نفس النتيجة القديمة
    $existing = Payment::where('idempotency_key', $idempotencyKey)->first();
    if ($existing) {
        return [
            'message'   => 'Payment already recorded (duplicate request ignored)',
            'data'      => $existing->invoice->load('payments'),
            'duplicate' => true, // فيدك تعرفي بالفرونت إنه هاد رد مكرر مش دفعة جديدة
        ];
    }

    return DB::transaction(function () use ($invoiceId, $amount, $idempotencyKey) {
        $invoice = Invoice::with('payments')->findOrFail($invoiceId);
    
        // ✅ هون بس — فاتورة المورد لازم تكون issued قبل ما تنقبل دفعات
        if ($invoice->type === 'supplier' && $invoice->status === 'draft') {
            return [
                'success' => false,
                'message' => 'لا يمكن إضافة دفعة لفاتورة مورد بحالة مسودة، يجب أن يوافق عليها الأدمن أولاً',
            ];
        }
    
        $total = $invoice->total_amount_USD_after_discount > 0
            ? $invoice->total_amount_USD_after_discount
            : $invoice->total_amount_USD;
    
        if ($invoice->paid_amount >= $total) {
            return [
                'success' => false,
                'message' => "Invoice already fully paid"
            ];
        }
    
        if (($invoice->paid_amount + $amount) > $total) {
            return [
                'success' => false,
                'message' => "Payment exceeds remaining amount"
            ];
        }
    $invoice = Invoice::with('payments')->findOrFail($invoiceId);
    
// ✅ هون بس — فاتورة المورد لازم تكون issued قبل ما تنقبل دفعات
    if ($invoice->type === 'supplier' && $invoice->status === 'draft') {
        return [
            'success' => false,
            'message' => 'لا يمكن إضافة دفعة لفاتورة مورد بحالة مسودة، يجب أن يوافق عليها الأدمن أولاً',
        ];
    }

    $total = $invoice->total_amount_USD_after_discount > 0
        ? $invoice->total_amount_USD_after_discount
        : $invoice->total_amount_USD;

    if ($invoice->paid_amount >= $total) {
        return [
            'success' => false,
            'message' => "Invoice already fully paid"
        ];
    }

    if (($invoice->paid_amount + $amount) > $total) {
        return [
            'success' => false,
            'message' => "Payment exceeds remaining amount"
        ];
    }
    $rate = app(ExchangeRateService::class)->getCurrentUsdToSypRate();

    Payment::create([
        'invoice_id' => $invoice->id,
        'idempotency_key' => $idempotencyKey, // حفظ المفتاح
        'amount' => $amount,
        'method' => 'cash',
        'exchange_rate' => $rate->rate,
        'created_by' => Auth::id(),
    ]);

    $invoice->refresh();

    $invoice->paid_amount = $invoice->payments()->sum('amount');

    $this->updateStatus($invoice, $total);

    $invoice->total_amount_SYP = $invoice->total_amount_USD * $invoice->exchange_rate;

    $invoice->save();

    $remaining = $total - $invoice->paid_amount;

    return [
        'message' => 'Payment recorded successfully',
        'data' => $invoice->load('payments'),
        'remaining' => $remaining
    ];
     });
}


public function getById($id)
{
    return Invoice::with([
        'items',
        'supplier',
        'patient.user',
        'plans',
         'payments' 
    ])->findOrFail($id);
}


private function updateStatus($invoice, $total)
{
    if ($invoice->paid_amount == 0) {
        $invoice->status = 'issued';
    } elseif ($invoice->paid_amount < $total) {
        $invoice->status = 'partial';
    } else {
        $invoice->status = 'paid';
    }
}

public function applyDiscount($invoiceId, $discount)
{
    $invoice = Invoice::findOrFail($invoiceId);

    if (!in_array($invoice->type, ['supplier', 'patient'])) {
            return [
                'success' => false,
                'message' => "Discount not allowed for this invoice type"
            ];
    }

    $invoice->discount = $discount;

    $totalBefore = $invoice->total_amount_USD;

    $discountValue = ($totalBefore * $discount) / 100;
    $totalAfter = $totalBefore - $discountValue;
    if ($invoice->paid_amount > $totalAfter) {
            return [
                'success' => false,
                'message' => "Discount invalid: paid amount exceeds total after discount"
            ];
    }
    $invoice->total_amount_USD_after_discount = $totalAfter;
    $invoice->total_amount_SYP_after_discount = $totalAfter * $invoice->exchange_rate;

    $this->updateStatus($invoice, $totalAfter);

    $invoice->save();
    $remaining = $totalAfter - $invoice->paid_amount;

    return [
        'invoice' => $invoice,
        'remaining' => $remaining
    ];
}


public function createPatientInvoice(array $data)
{
    return DB::transaction(function () use ($data) {
        $patient = Patient::findOrFail($data['patient_id']);
        $plan = Treatment_Plan::findOrFail($data['plan_id']);

        $rate = app(ExchangeRateService::class)->getCurrentUsdToSypRate();
        
        $totalUsd = $data['total_amount'] ?? $plan->price_usd; 

        $invoice = Invoice::create([
            'type' => 'patient',
            'patient_id' => $patient->id,
            'plan_id' => $plan->id,
            'status' => 'draft',
            'paid_amount' => 0,
            'total_amount_USD' => $totalUsd,
            'total_amount_SYP' => $totalUsd * $rate->rate,
            'exchange_rate' => $rate->rate,
            'invoice_number' => 'INV-' . date('Ymd') . '-' . strtoupper(uniqid()),
            'issued_at' => now(),
            'created_by' => Auth::id(),
        ]);

        return $invoice;
    });
}

public function addSession($invoice, $session)
{
    $amount = $session->rprice_usd;

    $invoice->items()->create([
        'treatment_session_id' => $session->id,
        'description' => $session->name,
        'quantity' => 1,
        'unit_price' => $amount,
        'subtotal' => $amount,
    ]);

    $this->recalculate($invoice);

    return $invoice;
}

public function recalculate($invoice)
{
    $invoice->total_amount_USD = $invoice->items()->sum('subtotal');
    $invoice->total_amount_SYP = $invoice->total_amount_USD * $invoice->exchange_rate;
    $invoice->status = $this->status($invoice);

    $invoice->save();

    return $invoice;
}

private function status($invoice)
{
    if ($invoice->paid_amount == 0) return 'issued';
    if ($invoice->paid_amount < $invoice->total_amount_USD) return 'partial';
    return 'paid';
}

public function getMonthlyStatusStats($year = null)
{
    $year = $year ?? date('Y');

    $results = \App\Models\Invoice::where('type', 'patient')
        ->whereYear('created_at', $year)
        ->whereIn('status', ['draft', 'issued', 'partial', 'paid'])
        ->selectRaw('
            MONTH(created_at) as month,
            SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN status IN ("draft", "issued", "partial") THEN 1 ELSE 0 END) as pending_count
        ')
        ->groupBy('month')
        ->get()
        ->keyBy('month'); // ← فهرسة النتائج برقم الشهر عشان نلاقيها بسرعة

    $stats = [];
    for ($month = 1; $month <= 12; $month++) {
        if ($results->has($month)) {
            $stats[] = [
                'month'           => $month,
                'completed_count' => (int) $results[$month]->completed_count,
                'pending_count'   => (int) $results[$month]->pending_count,
            ];
        } else {
            $stats[] = [
                'month'           => $month,
                'completed_count' => 0,
                'pending_count'   => 0,
            ];
        }
    }

    return $stats;
}

public function getMonthlySupplierStatusStats($year = null)
{
    $year = $year ?? date('Y');

    $results = \App\Models\Invoice::where('type', 'supplier')
        ->whereYear('created_at', $year)
        ->whereIn('status', ['draft', 'issued', 'partial', 'paid'])
        ->selectRaw('
            MONTH(created_at) as month,
            SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN status IN ("draft", "issued", "partial") THEN 1 ELSE 0 END) as pending_count
        ')
        ->groupBy('month')
        ->get()
        ->keyBy('month');

    $stats = [];
    for ($month = 1; $month <= 12; $month++) {
        if ($results->has($month)) {
            $stats[] = [
                'month'           => $month,
                'completed_count' => (int) $results[$month]->completed_count,
                'pending_count'   => (int) $results[$month]->pending_count,
            ];
        } else {
            $stats[] = [
                'month'           => $month,
                'completed_count' => 0,
                'pending_count'   => 0,
            ];
        }
    }

    return $stats;
}

public function getMonthlyRevenueStats($year = null)
{
    $year = $year ?? date('Y');

    $stats = \App\Models\Payment::whereHas('invoice', function ($query) {
            $query->where('type', 'patient');
        })
        ->whereYear('created_at', $year)
        ->selectRaw('
            MONTH(created_at) as month,
            SUM(amount) as total_collected_usd
        ')
        ->groupBy('month')
        ->get()
        ->keyBy('month');

    $months = collect(range(1, 12))->map(function ($month) use ($stats) {
        return [
            'month'             => $month,
            'total_collected_usd' => (float) ($stats[$month]['total_collected_usd'] ?? 0),
        ];
    });

    return [
        'months'    => $months,                                          // شهر شهر
        'yearly_total' => $months->sum('total_collected_usd'),           // مجموع السنة
    ];
}
//الايرادات لشهر محدد 
public function getMonthlyRevenueBySpecificMonth($month, $year = null)
{
    $year = $year ?? date('Y');

    return \App\Models\Payment::whereHas('invoice', function ($query) {
            $query->where('type', 'patient');
        })
        ->whereYear('created_at', $year)
        ->whereMonth('created_at', $month) // الفلترة حسب الشهر هنا
        ->sum('amount'); // نستخدم sum مباشرة للحصول على الرقم النهائي
}


public function sendPaymentReminders()
{
    $overdueInvoices = $this->getOverdueInvoices();

    foreach ($overdueInvoices as $invoice) {

        if (
            $invoice->last_reminder_sent_at &&
            $invoice->last_reminder_sent_at
                ->greaterThanOrEqual(now()->subDays(7))
        ) {
            continue;
        }

        $user = $invoice->patient?->user;

        if (!$user) {
            continue;
        }

        dispatch(new \App\Jobs\SendNotificationJob(
            [$user->id],
            'تذكير بدفعة مستحقة',
            'عزيزي المريض، يرجى مراجعة المركز لإتمام الدفعة المستحقة على خطتك العلاجية.',
            'payment_reminder',
            [
                'invoice_id' => $invoice->id,
            ]
        ));

        $invoice->update([
            'last_reminder_sent_at' => now(),
        ]);
    }
}

public function getOverdueInvoices($fromDate = null, $toDate = null)
{
    $startDate = $fromDate ? Carbon::parse($fromDate) : now()->subMonths(12);
    $endDate   = $toDate   ? Carbon::parse($toDate)->endOfDay() : now();

    return Invoice::where('type', 'patient')
        ->where('status', '!=', 'paid')
        ->where('status', '!=', 'cancelled')
        ->whereBetween('created_at', [$startDate, $endDate])
        ->with(['patient.user', 'plans'])
        ->get()
        ->filter(function ($invoice) {
            if (!$invoice->plans) return false;

            $progress = $invoice->plans->progress_percent;
            $paid     = $invoice->paid_percent;
            $case1 = ($progress == 100 && $paid < 100);
            $case2 = ($progress > $paid + 20);

            return $case1 || $case2;
        });
}
}
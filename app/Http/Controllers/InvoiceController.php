<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Services\InvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Mpdf\Mpdf;
use App\Models\Invoice;
use App\Models\Payment;

class InvoiceController extends Controller
{
    private InvoiceService $service;

    public function __construct(InvoiceService $service)
    {
        $this->service = $service;
    }
    
    public function show($id)
{
    $invoice = $this->service->getById($id);

    return response()->json([
        'success' => true,
        'data' => $invoice
    ]);
}

public function applyDiscount(Request $request, $id)
    {
        // 1. التحقق من المدخلات
        $request->validate([
            'discount' => 'required|numeric|min:0|max:100',
        ]);

        try {
            // 2. استدعاء الخدمة
            $result = $this->service->applyDiscount($id, $request->discount);

            // 3. التحقق مما إذا كانت الخدمة قد أعادت مصفوفة خطأ (بما أنكِ تستخدمين return مصفوفة في حال الخطأ)
            if (is_array($result) && isset($result['success']) && $result['success'] === false) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], 422);
            }

            // 4. في حال النجاح
            return response()->json([
                'success' => true,
                'message' => 'تم تطبيق الخصم بنجاح',
                'data' => $result
            ], 200);

        } catch (\Exception $e) {
            // في حال حدوث خطأ غير متوقع (مثل ID غير موجود)
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء تطبيق الخصم: ' . $e->getMessage()
            ], 500);
        }
    }


//عرض فواتير المورد
   public function index()
{
    $invoices = $this->service->getAll();

    return response()->json($invoices);
} 

//عرض فواتير المرضى
  public function indexs()
{
    $invoices = $this->service->getAllPatientInvoices();

    return response()->json($invoices);
} 

//اعتماد الفاتورة
public function approve($id)
{
    $invoice = $this->service->approve($id);

    return response()->json([
        'message' => 'Invoice approved',
        'data' => $invoice
    ]);
}
// دفع الفاتورة
public function pay(Request $request, $id)
{
    $request->validate([
        'amount' => 'required|numeric|min:0.01',
        'idempotency_key' => 'required|string|max:100', // ← جديد، إجباري
    ]);

    $invoice = $this->service->payInvoice($id, $request->amount,$request->idempotency_key);

    return response()->json([
        'message' => 'Payment recorded successfully',
        'data' => $invoice
    ]);
}



public function print($id)
{
    $invoice = $this->service->getById($id);

    $supplierName = $invoice->supplier?->name ?? '-';
   $patientName = $invoice->patient?->user?->name ?? ($invoice->patient?->user?->name ?? 'غير معروف');

    $label = ($invoice->type === 'patient') ? 'المريض' : 'المورد';
    $partyName = ($invoice->type === 'patient') ? $patientName : $supplierName;

    $total = $invoice->total_amount_USD_after_discount > 0
        ? $invoice->total_amount_USD_after_discount
        : $invoice->total_amount_USD;

    $remaining = $total - $invoice->paid_amount;

    $html = '
    <html dir="rtl">
    <head>
        <meta charset="utf-8">
        <style>
            body { font-family: dejavusans; direction: rtl; text-align: right; line-height: 1.6; }
            .section { margin-top: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
            h3 { margin: 5px 0; }
            .signature-box { margin-top: 60px; display: flex; justify-content: space-between; }
        </style>
    </head>
    <body>

        <h2 style="text-align:center;">فاتورة رقم ' . ($invoice->invoice_number ?? 'INV-' . $invoice->id) . '</h2>
    
        <div class="section">
            <p><strong>النوع:</strong> ' . ($invoice->type === 'patient' ? 'مريض' : 'مورد') . '</p>
            <p><strong>' . $label . ':</strong> ' . $partyName . '</p>
            <p><strong>الحالة:</strong> ' . $invoice->status . '</p>
            <p><strong>التاريخ:</strong> ' . $invoice->issued_at . '</p>
            <p><strong>سعر الصرف:</strong> ' . number_format($invoice->exchange_rate, 2) . '</p>
        </div>
    ';

    if ($invoice->type === 'patient') {
        $html .= '<div class="section"><p><strong>الخطة العلاجية:</strong> ' . ($invoice->plans?->name ?? '-') . '</p></div>';
    }

    $html .= '<div class="section">';
    
    if (!empty($invoice->discount) && $invoice->discount > 0) {
        $html .= '
            <h3>الإجمالي قبل الخصم: ' . number_format($invoice->total_amount_USD, 2) . ' USD (' . number_format($invoice->total_amount_SYP, 2) . ' SYP)</h3>
            <h3>نسبة الخصم: ' . $invoice->discount . ' %</h3>
            <h3>الإجمالي بعد الخصم: ' . number_format($invoice->total_amount_USD_after_discount, 2) . ' USD (' . number_format($invoice->total_amount_SYP_after_discount, 2) . ' SYP)</h3>
        ';
    } else {
        $html .= '
            <h3>الإجمالي: ' . number_format($invoice->total_amount_USD, 2) . ' USD (' . number_format($invoice->total_amount_SYP, 2) . ' SYP)</h3>
        ';
    }

    $html .= '</div>';

    $html .= '
        <div class="section">
            <h3>المدفوع: ' . number_format($invoice->paid_amount, 2) . ' USD</h3>
            <h3 style="color: ' . ($remaining > 0 ? 'red' : 'green') . ';">المتبقي: ' . number_format($remaining, 2) . ' USD</h3>
        </div>
    ';
    // قائمة الدفعات (أرقام فقط بدون تفاصيل)
    $payments = $invoice->payments;
    if ($payments->count() > 0) {
        $html .= '<div class="section"><h3>سجل الدفعات</h3><table style="width:100%; border-collapse: collapse;">';
        $html .= '<tr><th style="border:1px solid #ccc; padding:5px;">#</th><th style="border:1px solid #ccc; padding:5px;">التاريخ</th><th style="border:1px solid #ccc; padding:5px;">المبلغ (USD)</th></tr>';

        foreach ($payments as $index => $payment) {
            $html .= '<tr>
                <td style="border:1px solid #ccc; padding:5px; text-align:center;">' . ($index + 1) . '</td>
                <td style="border:1px solid #ccc; padding:5px; text-align:center;">' . $payment->created_at->format('Y-m-d') . '</td>
                <td style="border:1px solid #ccc; padding:5px; text-align:center;">' . number_format($payment->amount, 2) . '</td>
            </tr>';
        }

        $html .= '</table></div>';
    }

    // قسم التوقيع
    $html .= '
        <div class="signature-box">
            <div style="text-align: center; width: 40%; border-top: 1px solid #000; padding-top: 10px;">
                <p>توقيع المحاسب</p>
            </div>
            <div style="text-align: center; width: 40%; border-top: 1px solid #000; padding-top: 10px;">
                <p>توقيع المستلم</p>
            </div>
        </div>
    ';

    $html .= '</body></html>';

    $mpdf = new \Mpdf\Mpdf();
    $mpdf->WriteHTML($html);

    return response($mpdf->Output('invoice_' . $invoice->id . '.pdf', 'I'), 200)
        ->header('Content-Type', 'application/pdf');
}
//'طباعة وصل الدفع
public function printReceipt($paymentId)
{
    // جلب الدفعة مع علاقاتها
    $payment = \App\Models\Payment::with(['invoice.supplier', 'invoice.patient.user', 'user'])->findOrFail($paymentId);
    $invoice = $payment->invoice;
// حساب الإجمالي النهائي (بعد الخصم إذا وجد)
    $finalTotal = ($invoice->total_amount_USD_after_discount > 0) 
                  ? $invoice->total_amount_USD_after_discount 
                  : $invoice->total_amount_USD;

    $remaining = $finalTotal - $invoice->paid_amount;
    // 1. تحديد النصوص بناءً على نوع الفاتورة
    if ($invoice->type === 'patient') {
        $partyLabel = 'المريض';
        $partyName = $invoice->patient->user->name ?? 'غير معروف';
        $actionLabel = 'تم استلام المبلغ من المريض';
        $transactionDetail = 'بواسطة المحاسب: ' . ($payment->user->name ?? 'نظام');
    } else {
        $partyLabel = 'المورد';
        $partyName = $invoice->supplier->name ?? 'غير معروف';
        $actionLabel = 'تم دفع المبلغ إلى المورد';
        $transactionDetail = 'بواسطة المحاسب: ' . ($payment->user->name ?? 'نظام');
    }

    // 3. بناء الـ HTML
    $html = '
    <html dir="rtl">
    <head>
        <meta charset="utf-8">
        <style>
            body { font-family: dejavusans; text-align: right; line-height: 1.6; }
            .receipt-box { border: 1px solid #ccc; padding: 20px; width: 85%; margin: auto; }
            .header { text-align: center; border-bottom: 2px solid #000; margin-bottom: 20px; }
        </style>
    </head>
    <body>
        <div class="receipt-box">
            <div class="header">
                <h2>سند قبض / دفع</h2>
            </div>
            <p><strong>التاريخ:</strong> ' . $payment->created_at->format('Y-m-d') . '</p>
            <p><strong>' . $partyLabel . ':</strong> ' . $partyName . '</p>
            <p><strong>المبلغ المدفوع في هذه الدفعة:</strong> ' . number_format($payment->amount, 2) . ' USD</p>
            <p><strong>طريقة الدفع:</strong> ' . $payment->method . '</p>
            <div style="margin-top: 15px; border-top: 1px dashed #999; padding-top: 10px;">
                <p style="font-size: 14px;"><strong>إجمالي قيمة الفاتورة:</strong> ' . number_format($finalTotal, 2) . ' USD</p>
                <p style="font-size: 14px;"><strong>إجمالي المدفوع حتى الآن:</strong> ' . number_format($invoice->paid_amount, 2) . ' USD</p>
            </div>
            <div style="margin-top: 20px; border-top: 1px solid #000; padding-top: 10px;">
                <p style="font-size: 14px;">
                    ' . $actionLabel . ': 
                    <span style="font-weight: bold; text-decoration: underline;">' . e($partyName) . '</span>
                </p>
                <p style="font-size: 14px;">
                    ' . $transactionDetail . '
                </p>

                <div style="margin-top: 10px; border-top: 1px dashed #666; padding-top: 10px;">
                    <p style="font-size: 16px; color: #333;">
                        <strong>المبلغ المتبقي على الفاتورة:</strong> 
                        <span style="color: #d9534f;">' . number_format($remaining, 2) . ' USD</span>
                    </p>
                </div>
            </div>
            
            <div style="margin-top: 40px;">
                <p>توقيع المستلم: _______________</p>
            </div>
        </div>
    </body>
    </html>';

    $mpdf = new \Mpdf\Mpdf();
    $mpdf->WriteHTML($html);
    return response($mpdf->Output('receipt_' . $payment->id . '.pdf', 'I'), 200)
        ->header('Content-Type', 'application/pdf');
}

// إحصائيات حالة الفواتير المرضى
public function getStatusStats(Request $request)
    {
        $year = $request->input('year', date('Y'));
        return response()->json([
            'success' => true,
            'data' => $this->service->getMonthlyStatusStats($year)
        ]);
    }
    // إحصائيات حالة فواتير الموردين
    public function getSupplierStatusStats(Request $request)
{
    $year = $request->input('year', date('Y'));
    return response()->json([
        'success' => true,
        'data' => $this->service->getMonthlySupplierStatusStats($year)
    ]);
}
    
    // إحصائيات الإيرادات لسنة
public function getRevenueStats(Request $request)
    {
        $year = $request->input('year', date('Y'));
        return response()->json([
            'success' => true,
            'data' => $this->service->getMonthlyRevenueStats($year)
        ]);
    }
    // الايرادات لشهر محدد 
public function getRevenue(Request $request)
{
    $month = $request->input('month', date('m')); // الافتراضي هو الشهر الحالي
    $year = $request->input('year', date('Y'));
    
    $revenue = $this->service->getMonthlyRevenueBySpecificMonth($month, $year);

    return response()->json([
        'success' => true,
        'month' => $month,
        'revenue' => $revenue
    ]);
}
// عرض الفواتير المتأخرة
 public function getOverdue(Request $request)
    {
        $data = $this->service->getOverdueInvoices(
            $request->input('from'), 
            $request->input('to')
        );

        return response()->json([
            'success' => true,
            'data' => $data->values()
        ]);
    }


}

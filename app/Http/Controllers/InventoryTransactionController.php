<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\InventoryTransaction;
use App\Services\InventoryTransactionService;
use App\Models\MaterialRequest;
use App\Models\InventoryAudit;
use App\Models\AuditItem;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Disposal;
use App\Models\DisposalItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;


class InventoryTransactionController extends Controller
{
    private InventoryTransactionService $service;

    public function __construct(InventoryTransactionService $service)
    {
        $this->service = $service;
    }

    public function purchase(Request $request)
{
    $data = $request->validate([
        'supplier_id' => 'required|exists:suppliers,id',

        'items' => 'required|array|min:1',
        'items.*.supplier_item_id' => 'required|exists:supplier_items,id',
        'items.*.quantity' => 'required|integer|min:1',
        'items.*.purchase_price' => 'required|numeric|min:0',

        'items.*.batch_number' => 'nullable|string|max:100',
        'items.*.expiry_date' => 'nullable|date',
        'items.*.storage_location' => 'nullable|string|max:255',
       // 'items.*.unit_cost' => 'nullable|numeric|min:0',

        //'issued_at' => 'nullable|date',
        'notes' => 'nullable|string',
    ]);
    $data['issued_at'] = now();

    $invoice = $this->service->purchaseBulk($data);

    return response()->json([
        'message' => 'Purchase & Invoice created successfully',
        'data' => $invoice
    ]);
}

////انشاء طلب موادمن الدكتور
    public function storee(Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',

            'items.*.item_id' =>
                'required|exists:items,id',

            'items.*.quantity' =>
                'required|integer|min:1',

            'notes' => 'nullable|string',
        ]);

        // الدكتور من التوكين
        $doctorId = Auth::user()->doctor->id;

        $materialRequest = $this->service->create(
            $doctorId,
            $data
        );
        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء الطلب',
            'data' => $materialRequest
        ], 201);
    }

/**
 * الموافقة على طلب مواد بالكامل وصرفه
 */
public function approveRequest(Request $request, int $requestId)
{
    try {
        // يتم استدعاء الخدمة مباشرة دون الحاجة لمصفوفة تعديل الكميات
        $result = $this->service->approveMaterialRequest($requestId);

        return response()->json([
            'success' => true,
            'message' => 'تمت الموافقة على الطلب بالكامل وصرف المواد من المخزن بنجاح',
            'data' => $result
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 400);
    }
}

 /**
     * إنشاء جرد جديد
     */
    
    public function audit(Request $request)
    {
        $validated = $request->validate([
            'audit_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $audit = InventoryAudit::create([
            'audit_number' => 'AUDIT-' . date('YmdHis'),
            'audit_date' => $validated['audit_date'],
            'status' => 'pending',
            'notes' => $validated['notes'] ?? null,
            'started_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء الجرد',
            'data' => $audit,
        ], 201);
    }

    /**
     * عرض كل الجردات
     */
    public function shows()
    {
        $audits = InventoryAudit::with('items')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $audits,
        ]);
    }

    /**
 * عرض جرد واحد مجمع (تظهر كل مادة مرة واحدة فقط بمجموع كمياتها المدخلة)
 */
public function showss(int $id)
{
    $audit = InventoryAudit::with('items.item')->findOrFail($id);

    // تجميع العناصر بناءً على id المادة
    $aggregatedItems = $audit->items->groupBy('item_id')->map(function ($group) {
        $firstItem = $group->first();
        
        // مجموع الكميات الفعلية التي تم عدّها وإدخالها على دفعات
        $totalActual = $group->sum('actual_quantity');

        // الكمية المتوقعة الفعلية الحالية بالمستودع
        $expectedQuantity = Inventory::where('item_id', $firstItem->item_id)
            ->where('is_active', true)
            ->sum('quantity');

        return [
            'item_id' => $firstItem->item_id,
            'item' => $firstItem->item,
            'expected_quantity' => $expectedQuantity,
            'actual_quantity' => $totalActual,
            'variance' => $totalActual - $expectedQuantity,
            // دمج مبررات الفروقات من كافة الدفعات إن وجدت في مصفوفة واحدة
            'variance_reasons' => $group->pluck('variance_reason')->filter()->values()->all(),
        ];
    })->values();

    // تجهيز البيانات لإرسالها بالـ Response
    $auditData = $audit->toArray();
    $auditData['items'] = $aggregatedItems; // استبدال القائمة المكررة بالقائمة المجمّعة

    return response()->json([
        'success' => true,
        'data' => $auditData,
    ]);
}

   
//اضافة مواد للجرد
public function addItem(Request $request, int $auditId)
{
    $validated = $request->validate([
        'item_id' => 'required|exists:items,id',
        'quantity_actual' => 'required|integer|min:0',
    ]);

    $audit = InventoryAudit::findOrFail($auditId);
    
    // تسجيل الإدخال كـ "سجل خام" (سجل عدّ)
    $entry = AuditItem::create([
        'inventory_audit_id' => $auditId,
        'item_id' => $validated['item_id'],
        'quantity_actual' => $validated['quantity_actual'],
    ]);

    return response()->json([
        'success' => true,
        'message' => 'تم تسجيل الكمية بنجاح'
    ]);
}

//عرض نتيجة الجرد
public function getAuditResult($auditId)
{
    // 1. جلب البيانات المجمعة لكل مادة، مع تجميع أسباب النقص المسجّلة لها
    $results = AuditItem::where('inventory_audit_id', $auditId)
        ->select('item_id', DB::raw('SUM(quantity_actual) as total_actual'))
        ->groupBy('item_id')
        ->get();

    $itemsReport = [];
    $grandTotalVariance = 0; // متغير لحساب النقص أو الفائض الكلي

    foreach ($results as $row) {
        $expected = Inventory::where('item_id', $row->item_id)->sum('quantity');
        $variance = $row->total_actual - $expected;

        // تراكم الفارق الكلي
        $grandTotalVariance += $variance;

       // جلب سبب النقص المسجّل لهذه المادة ضمن هذا الجرد
        $reason = AuditItem::where('inventory_audit_id', $auditId)
            ->where('item_id', $row->item_id)
            ->whereNotNull('variance_reason')
            ->value('variance_reason');

        $itemsReport[] = [
            'item_id' => $row->item_id,
            'item_name' => \App\Models\Item::find($row->item_id)->name ?? 'غير معروف',
            'total_actual' => (int) $row->total_actual,
            'total_expected' => (int) $expected,
            'variance' => (int) $variance,
            'variance_reason' => $reason ?? 'لا يوجد سبب مسجل',
        ];
    }

    return response()->json([
        'success' => true,
        'details' => $itemsReport, // تفاصيل كل مادة
        'summary' => [
            'total_items_count' => count($itemsReport),
            'grand_total_variance' => (int) $grandTotalVariance,
            'status' => $grandTotalVariance === 0 ? 'مطابق' : ($grandTotalVariance > 0 ? 'فائض' : 'عجز')
        ]
    ]);
}


public function updateVarianceReason(Request $request, $auditId, $itemId) 
{
    $request->validate(['reason' => 'required|string']);
    
    // تحديث كل السجلات التي تنتمي لهذه المادة في هذا الجرد بالتحديد
    $updatedCount = AuditItem::where('inventory_audit_id', $auditId)
        ->where('item_id', $itemId)
        ->update([
            'variance_reason' => $request->reason,
            //'is_resolved' => true
        ]);
    
    if ($updatedCount === 0) {
        return response()->json(['message' => 'لم يتم العثور على سجلات لهذه المادة في هذا الجرد'], 404);
    }
    
    return response()->json(['message' => 'تم حفظ التبرير بنجاح لجميع سجلات المادة']);
}


    public function complete(int $auditId)
{
    $audit = InventoryAudit::findOrFail($auditId);

    if ($audit->status !== 'pending') {
        return response()->json([
            'success' => false,
            'message' => 'الجرد مكتمل مسبقاً'
        ], 400);
    }

    // 1. احسبي الإجماليات أولاً
    $auditItems = AuditItem::where('inventory_audit_id', $auditId)->get();
    
    $totalItems = $auditItems->groupBy('item_id')->count();
    
    // حساب الفارق الكلي بناءً على منطقنا السابق
    $totalVariance = $auditItems->groupBy('item_id')->map(function ($items) {
        $actual = $items->sum('quantity_actual');
        $expected = Inventory::where('item_id', $items->first()->item_id)->sum('quantity');
        return $actual - $expected;
    })->sum();

    // 2. حدثي الـ Audit مع القيم المحسوبة
    $audit->update([
        'status' => 'waiting_approval',
        'completed_date' => now(),
        'completed_by' => Auth::id(),
        'total_items' => $totalItems,       // <-- هاد اللي كان ناقصك
        'total_variance' => $totalVariance, // <-- هاد اللي كان ناقصك
    ]);

    return response()->json([
        'success' => true,
        'message' => 'تم إنهاء الجرد وبانتظار الموافقة',
        'data' => $audit, // الآن سيظهر لكِ القيم المحدثة
    ]);
}


public function getPendingAuditsReport()
{
    $pendingAudits = InventoryAudit::where('status', 'waiting_approval')->get();

    $report = $pendingAudits->map(function ($audit) {
        
        // 1. جلب البيانات مع جلب عمود السبب (reason)
        $results = AuditItem::where('inventory_audit_id', $audit->id)
            ->select('item_id', 'variance_reason', DB::raw('SUM(quantity_actual) as total_actual'))
            ->groupBy('item_id', 'variance_reason') // تجميع حسب المادة والسبب
            ->get();

        $itemsReport = [];
        $grandTotalVariance = 0;

        foreach ($results as $row) {
            $expected = Inventory::where('item_id', $row->item_id)->sum('quantity');
            $variance = $row->total_actual - $expected;
            
            $grandTotalVariance += $variance;

            $itemsReport[] = [
                'item_id' => $row->item_id,
                'item_name' => \App\Models\Item::find($row->item_id)->name ?? 'غير معروف',
                'total_actual' => (int) $row->total_actual,
                'total_expected' => (int) $expected,
                'variance' => (int) $variance,
                'variance_reason' => $row->variance_reason ?? 'لا يوجد سبب مسجل' // هنا عرضنا السبب
            ];
        }

        return [
            'inventory_audit_id' => $audit->id,
            'audit_number' => $audit->audit_number,
            'items' => $itemsReport,
            'summary' => [
                'total_items_count' => count($itemsReport),
                'grand_total_variance' => (int) $grandTotalVariance,
                'status' => $grandTotalVariance === 0 ? 'مطابق' : ($grandTotalVariance > 0 ? 'فائض' : 'عجز')
            ]
        ];
    });

    return response()->json([
        'success' => true,
        'data' => $report
    ]);
}
    /**
     * موافقة المدير على الجرد + تنفيذ التسوية
     */
    public function approved(int $auditId)
    {
        $audit = $this->service->approveAudit($auditId);

        return response()->json([
            'success' => true,
            'message' => 'تمت الموافقة على الجرد وتسوية الفروقات',
            'data' => $audit,
        ]);
    }


/**
     * موافقة الأدمن على طلب الإتلاف المعلق (النظام أو اليدوي)
     */
    public function approve(int $id)
    {
        try {
            $disposal = $this->service->executeDisposal($id);

            return response()->json([
                'success' => true,
                'message' => 'تمت الموافقة وتصفير كميات المواد التالفة من المخازن نهائياً وبأمان',
                'data' => $disposal
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * عرض جميع طلبات الإتلاف المعلقة لمراجعتها باللوحة
     */
    public function getPendingDisposals()
    {
        $pending = Disposal::with('items.item')
            ->where('status', 'pending')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $pending
        ]);
    }
/**
 * إنشاء وإتلاف المواد يدوياً فوراً (خاص بأمينة المستودع)
 * POST /api/disposals/manual-immediate
 */
public function storeManualImmediate(Request $request)
{
    $validated = $request->validate([
        'reason' => 'required|in:broken,damaged,spoiled,other',
        'reason_notes' => 'required|string',
        'items' => 'required|array|min:1',
        'items.*.inventory_id' => 'required|exists:inventories,id',
        'items.*.item_id' => 'required|exists:items,id',
        'items.*.quantity' => 'required|integer|min:1',
    ]);

    try {
        // استدعاء العملية الفورية وتمرير معرف أمينة المستودع الحالية
        $disposal = $this->service->executeImmediateManualDisposal($validated, Auth::id());

        return response()->json([
            'success' => true,
            'message' => 'تم تنفيذ الإتلاف الفوري وتحديث المخازن في نفس اللحظة بنجاح ✅',
            'data' => $disposal
        ], 201);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 400);
    }
}
/**
 * جلب كافة الدفعات المتاحة لمادة معينة (تستخدم عند إضافة مادة للجرد أو طلب المواد)
 */
public function getByItem($item_id)
    {
        // جلب الدفعات المتاحة لهذه المادة فقط
        $batches = Inventory::where('item_id', $item_id)
            ->where('quantity', '>', 0) // لا نحتاج عرض دفعات نفدت كميتها
            ->where('is_active', true)  // التأكد أنها مفعلة
            ->orderBy('expiry_date', 'asc') // ترتيب الدفعات حسب تاريخ الانتهاء (الأقدم أولاً)
            ->get();

        if ($batches->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'لا توجد دفعات متاحة لهذه المادة'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $batches
        ]);
    }

public function getExpiredItems()
{
    return Inventory::where('is_active', false)
        ->where('quantity', '>', 0)
        ->whereNotNull('expiry_date')
        // هنا التعديل: استثني فقط الحالات التي أصبحت 'completed'
        // أي مادة لديها طلب 'pending' ستستمر بالظهور، وهذا هو المنطق السليم!
        ->where(function ($query) {
            $query->whereDoesntHave('disposalItems.disposal') // المادة التي ليس لها أي طلب إتلاف
                  ->orWhereHas('disposalItems.disposal', function ($q) {
                      $q->where('status', '!=', 'completed'); // أو لديها طلب لم يكتمل بعد
                  });
        })
        ->with('item')
        ->get();
}

//عرض جميع المواد التي تم اتلافها للادمن 
public function getDisposedItemsHistory()
{
    // عرض كل المواد التي تمت الموافقة على إتلافها
    return DisposalItem::whereHas('disposal', function($query) {
        $query->where('status', 'completed');
    })
    ->with(['item', 'disposal'])
    ->get();
}
// عرض المواد التي وصلت إلى حدها الأدنى من المخزون (تحت أو يساوي الحد الأدنى)
public function getLowStockItems()
{
    return Item::where('is_active', true)
        ->whereColumn('current_stock', '<=', 'minimum_stock')
        ->get();
}
// عرض طلبات الأطباء المعلقة
public function getPendingDoctorRequests()
{
    $data = $this->service->getPendingDoctorRequests();

    return response()->json([
        'success' => true,
        'data'    => $data,
    ]);
}

// عرض تفاصيل طلب محدد
public function getDoctorRequestDetails($id)
{
    $data = $this->service->getDoctorRequestDetails($id);

    return response()->json([
        'success' => true,
        'data'    => $data,
    ]);
}

public function getAvailableItemsForDoctor()
{
    $data = $this->service->getAvailableItemsForDoctor();

    return response()->json([
        'success' => true,
        'data'    => $data,
    ]);
}

// تعديل بيانات مادة محددة بالمخزن (الاسم، الحد الأدنى، الحد الأعلى، الوحدة...)
public function updateItem(Request $request, $itemId)
{
    $item = Item::findOrFail($itemId);

    $validated = $request->validate([
        'name'           => 'sometimes|string|max:255',
        'code'           => 'sometimes|string|max:255|unique:items,code,' . $item->id,
        'unit'           => 'sometimes|string|max:50',
        'minimum_stock'  => 'sometimes|integer|min:0',
        'max_stock'      => 'sometimes|integer|min:0',
        'is_active'      => 'sometimes|boolean',
    ]);

    // التحقق المنطقي: الحد الأدنى يجب أن يبقى أقل من أو يساوي الحد الأعلى
    $newMin = $validated['minimum_stock'] ?? $item->minimum_stock;
    $newMax = $validated['max_stock'] ?? $item->max_stock;

    if ($newMin > $newMax) {
        return response()->json([
            'success' => false,
            'message' => 'الحد الأدنى لا يمكن أن يكون أكبر من الحد الأعلى للمخزون.',
        ], 422);
    }

    // التحقق المنطقي الثاني: الكمية الحالية لا يجوز أن تتجاوز الحد الأعلى الجديد
    if ($item->current_stock > $newMax) {
        return response()->json([
            'success' => false,
            'message' => "لا يمكن تخفيض الحد الأعلى إلى {$newMax} لأن الكمية الحالية بالمخزن ({$item->current_stock}) تتجاوزه. قم بتصريف أو إتلاف الفائض أولاً.",
        ], 422);
    }

    $item->update($validated);

    return response()->json([
        'success' => true,
        'message' => 'تم تعديل بيانات المادة بنجاح',
        'data'    => $item,
    ]);
}

}
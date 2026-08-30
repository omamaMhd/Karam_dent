<?php
namespace App\Services;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\SupplierItem;
use App\Models\InventoryTransaction;
use App\Models\Inventory;
use App\Models\Invoice;
use App\Models\Invoice_Item;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use Illuminate\Support\Facades\DB;
use App\Services\ExchangeRateService;
use App\Events\InvoiceCreated;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\Disposal;
use App\Models\InventoryAudit;
use App\Models\DisposalItem;
use App\Models\AuditItem;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;


class InventoryTransactionService
{
 
      public function __construct(
        private ExchangeRateService $exchangeService,
        
    ) {}

public function purchaseBulk(array $data)
{
    return DB::transaction(function () use ($data) {

        $supplier = Supplier::findOrFail($data['supplier_id']);

        $rateModel = $this->exchangeService->getCurrentUsdToSypRate();
        $exchangeRate = $rateModel->rate;

        $invoiceNumber = 'INV-' . date('Ymd') . '-' . strtoupper(uniqid());

        $invoice = Invoice::create([
            'invoice_number' => $invoiceNumber,
            'type' => 'supplier',
            'supplier_id' => $supplier->id,
            'discount' => 0,
            'exchange_rate' => $exchangeRate,
            'status' => 'draft',
            'paid_amount' => 0,
            'issued_at' => $data['issued_at'] ?? now(),
        ]);

        if ($invoice->type !== 'supplier') {
            return [
                'success' => false,
                'message' => 'Invoice type must be supplier'
            ];
        }
        $total = 0;

        foreach ($data['items'] as $row) {

            $supplierItem = SupplierItem::where('supplier_id', $supplier->id)
                ->where('id', $row['supplier_item_id'])
                ->firstOrFail();

            if (!$supplierItem->item_id) {

                $item = Item::firstOrCreate(
                    ['name' => $supplierItem->name],
                    [
                        'code' => strtoupper(uniqid('ITEM_')),
                        'unit' => $supplierItem->unit ?? 'unit',
                        'minimum_stock' => 0,
                        'max_stock' => 0,
                        'is_active' => true,
                    ]
                );
                $supplierItem->update([
                    'item_id' => $item->id
                ]);

            } else {

                $item = $supplierItem->item;
            }
    $maxStock = $item->max_stock;

    if ($maxStock > 0) {
        $requestedQuantity = $row['quantity'];
        $currentStock = $item->current_stock;
        
        if (($currentStock + $requestedQuantity) > $maxStock) {
            throw new \Exception(
                "خطأ: لا يمكن إتمام العملية. كمية المادة '{$item->name}' ستتجاوز الحد الأعلى المسموح به (" . 
                $maxStock . "). المخزون الحالي: " . $currentStock
            );
        }
    }
            $inventory = Inventory::where('item_id', $item->id)
                ->where('batch_number', $row['batch_number'] ?? null)
                ->first();

            if ($inventory) {

                $inventory->increment('quantity', $row['quantity']);

                $inventory->update([
                    'expiry_date' => $row['expiry_date'] ?? null,
                    'storage_location' => $row['storage_location'] ?? null,
                    'unit_cost' => $row['unit_cost'] ?? $row['purchase_price'],
                    'supplier_id' => $supplier->id,
                    'is_active' => true,
                ]);

            } else {

                $inventory = Inventory::create([
                    'item_id' => $item->id,
                    'batch_number' => $row['batch_number'] ?? null,
                    'quantity' => $row['quantity'],
                    'quantity_reserved' => 0,

                    'expiry_date' => $row['expiry_date'] ?? null,
                    'storage_location' => $row['storage_location'] ?? null,

                    'unit_cost' => $row['unit_cost'] ?? $row['purchase_price'],

                    'supplier_id' => $supplier->id,

                    'received_date' => now()->toDateString(),

                    'is_active' => true,
                ]);
            }

            $item->increment('current_stock', $row['quantity']);

            $subtotal = $row['quantity'] * $row['purchase_price'];

            $total += $subtotal;

            Invoice_Item::create([
                'invoice_id' => $invoice->id,
                'item_id' => $item->id,
                'description' => $item->name,
                'quantity' => $row['quantity'],
                'unit_price' => $row['purchase_price'],
                'subtotal' => $subtotal,
            ]);

            InventoryTransaction::create([
                'item_id' => $item->id,
                'inventory_id' => $inventory->id,
                'supplier_id' => $supplier->id,
                'batch_number' => $inventory->batch_number,
                'quantity' => $row['quantity'],
                'purchase_price' => $row['purchase_price'],
                'type' => 'in',
                'issued_at' => now(),
                'notes' => 'شراء من المورد #' . $supplier->id,
            ]);
        }

        $invoice->update([
            'total_amount_USD' => $total,
            'total_amount_SYP' => $total * $exchangeRate,
        ]);

        event(new InvoiceCreated($invoice));

        return $invoice->load(
            'items.item',
            'supplier'
        );
    });
}

//إنشاء طلب مواد من الدكتور
    public function create(int $doctorId,array $data) {
       
        return DB::transaction(function () use ($doctorId,$data) {
 $title = 'طلب مواد جديد';
$body  = 'تم إنشاء طلب مواد جديد في النظام.';
            $request = MaterialRequest::create([
                'doctor_id' => $doctorId,
'requested_by' => Auth::id(),
                'requisition_number' =>
                    'REQ-' . date('YmdHis'),
                'status' => 'pending',
                'notes' => $data['notes'] ?? null,
            ]);
            foreach ($data['items'] as $item) {
                MaterialRequestItem::create([
                    'material_request_id' => $request->id,

                    'item_id' => $item['item_id'],

                    'quantity_requested' =>
                        $item['quantity'],
                ]); }


            event(new \App\Events\MaterialRequestCreated($request));

            return $request->load('items.item');
        });
    }

public function approveMaterialRequest(int $requestId): MaterialRequest
    {
        // استخدام معاملات قاعدة البيانات لضمان تنفيذ كل العمليات أو تراجعها بالكامل في حال حدوث خطأ
        return DB::transaction(function () use ($requestId) {

            $request = MaterialRequest::with([
                'items.item',
                'doctor'
            ])->findOrFail($requestId);

            if ($request->status !== 'pending') {
                throw new \Exception('تمت معالجة هذا الطلب مسبقاً.');
            }
            // 1. المرحلة الأولى: التحقق من توفر كامل الكميات لجميع المواد
            foreach ($request->items as $requestItem) {
                $requestedQty = (int) $requestItem->quantity_requested;

                $available = (int) Inventory::where('item_id', $requestItem->item_id)
                    ->where('quantity', '>', 0)
                    ->where('is_active', true)
                    ->sum('quantity');

                if ($available < $requestedQty) {
                    throw new \Exception("المخزون غير كافٍ للمادة ({$requestItem->item->name}). المتوفر: {$available}، والمطلوب: {$requestedQty}. تم إلغاء عملية الصرف.");
                }
            }

            foreach ($request->items as $requestItem) {
                $requestedQty = (int) $requestItem->quantity_requested;
                $remaining = $requestedQty;

                $batches = Inventory::where('item_id', $requestItem->item_id)
                    ->where('quantity', '>', 0)
                    ->where('is_active', true)
                    ->orderBy('expiry_date', 'ASC')
                    ->orderBy('received_date', 'ASC')
                    ->lockForUpdate() // قفل السطور لمنع التداخل بين العمليات المتزامنة
                    ->get();

                foreach ($batches as $batch) {
                    if ($remaining <= 0) {
                        break;
                    }

                    if ($batch->quantity <= 0) {
                        continue;
                    }

                    $takeQty = min($remaining, $batch->quantity);
                    $before = $batch->quantity;
                    $newQty = $before - $takeQty;

                    $batch->update([
                        'quantity' => $newQty,
                        'is_active' => $newQty > 0
                    ]);

                    Item::where('id', $requestItem->item_id)
                        ->decrement('current_stock', $takeQty);

$updatedItem = Item::find($requestItem->item_id);
 $title = 'صرف مواد ';
$body  = 'تم خفض المادة عن الحد الأدنى';
if ($updatedItem->current_stock <= $updatedItem->minimum_stock) {

     event(new \App\Events\LowStockDetected($updatedItem));
}
                    InventoryTransaction::create([
                        'movement_type' => 'withdrawal',
                        'item_id' => $requestItem->item_id,
                        'inventory_id' => $batch->id,
                        'batch_number' => $batch->batch_number,
                        'quantity_change' => -$takeQty,
                        'quantity' => $takeQty,
                        'quantity_before' => $before,
                        'quantity_after' => $newQty,
                        'reference_type' => 'MaterialRequest',
                        'reference_id' => $request->id,
                        'reference_number' => $request->requisition_number,
                        'doctor_id' => $request->doctor_id,
                        'storage_location' => $batch->storage_location,
                        'notes' => "صرف كامل الكمية للطبيب {$request->doctor->name}",
                        'recorded_by' => Auth::id(),
                    ]);

                    $remaining -= $takeQty;
                }
                $requestItem->quantity_withdrawn = $requestedQty;
                $requestItem->save(); 
            }
            $request->update([
                'status' => 'approved',
                'withdrawn_date' => now(),
            ]);

            return $request->refresh()->load(['items.item']);
        });
    }

public function approveAudit(int $auditId)
{
    return DB::transaction(function () use ($auditId) {
        $audit = InventoryAudit::findOrFail($auditId);

        if ($audit->status !== 'waiting_approval') {
            throw new \Exception('الجرد غير قابل للموافقة');
        }
        $itemsToAdjust = AuditItem::where('inventory_audit_id', $auditId)
            ->select('item_id', DB::raw('SUM(quantity_actual) as total_actual'))
            ->groupBy('item_id')
            ->get();

        foreach ($itemsToAdjust as $record) {
    $itemId = $record->item_id;
    $totalActual = (float) $record->total_actual; 

    $expectedQuantity = (float) Inventory::where('item_id', $itemId)
        ->where('is_active', true)
        ->sum('quantity');

    $totalVariance = $totalActual - $expectedQuantity;

    Log::info("Item $itemId: Actual=$totalActual, Expected=$expectedQuantity, Variance=$totalVariance");

    if ($totalVariance != 0) {
        $this->adjustInventoryForItem($itemId, $totalVariance, $audit->id);
    }
}
        $audit->update([
            'status' => 'approved',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'تمت التسوية بنجاح']);
    });
}


public function adjustInventoryForItem(int $itemId, $variance, int $auditId)
{
    if ($variance == 0) {
        return;
    }

    $batches = Inventory::where('item_id', $itemId)
        ->where('is_active', true)
        ->orderBy('expiry_date', 'ASC')
        ->orderBy('received_date', 'ASC')
        ->get();

    if ($variance > 0) {
        $batch = $batches->first();
        if (!$batch) return; 

        $batch->increment('quantity', $variance);
        Item::where('id', $itemId)->increment('current_stock', $variance);

        InventoryTransaction::create([
            'type' => 'in', 
            'item_id' => $itemId,
            'quantity' => $variance, // هنا نستخدم الزيادة كاملة
            'notes' => 'تسوية جرد زيادة',
            'inventory_id' => $batch->id,
            'recorded_by' => Auth::id(),
            'reference_type' => 'Audit',
            'reference_id' => $auditId
        ]);

    } 
    else {
        $remaining = abs($variance);

        foreach ($batches as $batch) {
            if ($remaining <= 0) break;

            $take = min($remaining, $batch->quantity);
            
            $batch->decrement('quantity', $take);
            Item::where('id', $itemId)->decrement('current_stock', $take);

            if ($batch->fresh()->quantity <= 0) {
                $batch->update(['is_active' => false]);
            }

            InventoryTransaction::create([
                'type' => 'out',
                'item_id' => $itemId,
                'quantity' => $take, // استخدام $take هو الصح!
                'notes' => 'تسوية جرد نقص',
                'inventory_id' => $batch->id,
                'recorded_by' => Auth::id(),
                'reference_type' => 'Audit',
                'reference_id' => $auditId
            ]);

            $remaining -= $take;
        }
    }
}
   
public function executeDisposal(int $disposalId)
{
    return DB::transaction(function () use ($disposalId) {

        $disposal = Disposal::with('items')->findOrFail($disposalId);

        if ($disposal->status === 'completed') {
            throw new \Exception('تمت معالجة أو تنفيذ هذا الإتلاف مسبقاً.');
        }


        foreach ($disposal->items as $item) {
    $inventory = Inventory::lockForUpdate()->findOrFail($item->inventory_id);

    if ($item->quantity > $inventory->quantity) {
        throw new \Exception("خطأ في البيانات: المادة ({$item->item_id}) لم تعد متوفرة بالكمية المطلوبة في المخزن.");
    }

    $newQty = $inventory->quantity - $item->quantity;

    $inventory->update([
        'quantity' => $newQty,
        'is_active' => $newQty > 0
    ]);

    Item::where('id', $item->item_id)
        ->where('current_stock', '>=', $item->quantity) // شرط أمان إضافي
        ->decrement('current_stock', $item->quantity);

        InventoryTransaction::create([
                'item_id'          => $item->item_id,
                'inventory_id'     => $inventory->id, // الربط الجديد
                'type'             => 'out',
                'quantity'         => $item->quantity,
                'transaction_date' => now(),
                'notes'            => 'إتلاف معتمد من الإدارة - رقم الإتلاف: ' . $disposal->disposal_number,
            ]);
        }

        $disposal->update([
            'status'      => 'completed', // الحالة النهائية
            'approved_by' => (string)Auth::id(), // تخزين الـ ID كـ string حسب جدولك
        ]);

        return $disposal->fresh()->load('items.item');
    });
}
    

public function executeImmediateManualDisposal(array $data, int $userId)
{
    return DB::transaction(function () use ($data, $userId) {
        
        $totalQty = collect($data['items'])->sum('quantity');

        $disposal = Disposal::create([
            'disposal_number' => 'MAN-DISP-' . date('YmdHis'),
            'disposal_date'   => now(),
            'reason'          => $data['reason'], // السبب العام
            'notes'           => $data['reason_notes'], // ملاحظات الإتلاف
            'status'          => 'completed', // الحالة الافتراضية في جدولك
            'total_quantity'  => $totalQty,
            'created_by'      => (string)$userId,
            'executed_by'     => (string)$userId,
        ]);

        foreach ($data['items'] as $item) {
            $inventory = Inventory::lockForUpdate()->findOrFail($item['inventory_id']);

            if ($item['quantity'] > $inventory->quantity) {
                throw new \Exception("الكمية غير متوفرة في الدفعة: " . $inventory->batch_number);
            }

            $inventory->update([
                'quantity' => $inventory->quantity - $item['quantity'],
                'is_active' => ($inventory->quantity - $item['quantity']) > 0
            ]);

            Item::where('id', $item['item_id'])->decrement('current_stock', $item['quantity']);

            InventoryTransaction::create([
                'item_id'          => $item['item_id'],
                'inventory_id'     => $inventory->id, // قمنا بإضافته سابقاً
                'type'             => 'out',
                'quantity'         => $item['quantity'],
                'notes'            => 'إتلاف فوري: ' . $data['reason_notes'],
                'transaction_date' => now(),
            ]);

            DisposalItem::create([
                'disposal_id'    => $disposal->id,
                'item_id'        => $item['item_id'],
                'inventory_id'   => $item['inventory_id'],
                'batch_number'   => $inventory->batch_number,
                'quantity'       => $item['quantity'],
                'expiry_date'    => $inventory->expiry_date, // موجود في جدولك
                'reason_details' => $data['reason_notes'],
            ]);
        }

        return $disposal->fresh()->load('items.item');
    });
}

public function getPendingDoctorRequests()
{
    return MaterialRequest::with(['doctor.user', 'doctor.specialization', 'items.item'])
        ->where('status', 'pending')
        ->orderBy('requested_date', 'asc')
        ->get()
        ->map(function ($req) {
            return [
                'id'                 => $req->id,
                'requisition_number' => $req->requisition_number,
                'status'             => $req->status,
                'requested_date'     => $req->requested_date,
                'notes'              => $req->notes,
                'doctor' => [
                    'id'             => $req->doctor->id,
                    'name'           => $req->doctor->user->name ?? 'غير معروف',
                    'specialization' => $req->doctor->specialization->name ?? 'غير محدد',
                ],
                'items_count' => $req->items->count(),
            ];
        });
}

public function getDoctorRequestDetails(int $id): array
{
    $req = MaterialRequest::with(['doctor.user', 'doctor.specialization', 'items.item'])
        ->findOrFail($id);

    $items = $req->items->map(function ($requestItem) {
        $item = $requestItem->item;

        $batches = Inventory::where('item_id', $item->id)
            ->where('quantity', '>', 0)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expiry_date')
                  ->orWhere('expiry_date', '>', now()->toDateString());
            })
            ->orderBy('expiry_date', 'ASC')
            ->orderBy('received_date', 'ASC')
            ->get();

        $availableQty = $batches->sum('quantity');
        $canFulfill   = $availableQty >= $requestItem->quantity_requested;

        return [
            'item_id'            => $item->id,
            'item_name'          => $item->name,
             'requested_date'     => $item->requested_date,  
            'quantity_requested' => $requestItem->quantity_requested,
            'available_quantity' => $availableQty,
            'can_fulfill'        => $canFulfill,
            'fifo_message'       => $canFulfill
                ? "متوفر — سيُصرف من {$batches->count()} دفعة/دفعات"
                : 'لا توجد دفعات نشطة أو صالحة بالمخزن',
        ];
    });

    return [
        'id'                 => $req->id,
        'requisition_number' => $req->requisition_number,
        'status'             => $req->status,
        'requested_date'     => $req->requested_date,
        'notes'              => $req->notes,
        'doctor' => [
            'id'             => $req->doctor->id,
            'name'           => $req->doctor->user->name ?? 'غير معروف',
            'specialization' => $req->doctor->specialization->name ?? 'غير محدد',
        ],
        'items' => $items,
    ];
}

public function getAvailableItemsForDoctor()
{
    return Item::where('is_active', true)
        ->get(['id', 'name', 'unit']);
}

}
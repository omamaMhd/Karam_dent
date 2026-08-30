<?php

namespace App\Http\Controllers;
use App\Services\SupplierService;
use Illuminate\Http\Request;
use App\Models\Supplier;

class SupplierItemsController extends Controller
{
      protected $service;
  public function __construct(SupplierService $service)
    {
        $this->service = $service;
    }


public function store(Request $request)
{
    $data = $request->validate([
        'name' => 'required|string',
        'phone' => ['nullable', 'regex:/^09[0-9]{8}$/'],
        'notes' => 'nullable|string',
        'items' => 'required|array',
        
        // التحقق من الحقول المضافة
        'items.*.item_id' => 'nullable|exists:items,id',
        'items.*.name' => 'required_without:items.*.item_id|string', // اسم المادة مطلوب إذا لم تكن موجودة
        'items.*.code' => 'nullable|string',
        'items.*.unit' => 'nullable|string',
        'items.*.minimum_stock' => 'nullable|integer', // أضفنا هذه
        'items.*.max_stock' => 'nullable|integer',     // أضفنا هذه
    ]);

    $supplier = $this->service->createSupplierWithItems($data);

    return response()->json([
        'message' => 'Supplier created successfully',
        'data' => $supplier
    ]);
}

    //تثبيت مواد كدفعة وحدة
    public function stores(Request $request)
{
    $data = $request->validate([
        
        'items' => 'required|array',
        'items.*.name' => 'required|string|max:255',
        'items.*.code' => 'required|string|max:50|unique:items,code',
        'items.*.unit' => 'required|string|max:50',
        'items.*.minimum_stock' => 'required|integer|min:0',
        'items.*.max_stock' => 'required|integer|min:0',

    ]);

    $items = $this->service->createBulkItems($data['items']);

    return response()->json([
        'message' => 'Items created successfully',
        'data' => $items
    ]);
}

//عرض المواد المتاحة
public function availableItems()
{
    $items = $this->service->getAvailableItems();

    return response()->json([
        'message' => 'Available items retrieved successfully',
        'data' => $items
    ]);
}


public function getAllSuppliers()
{
    return Supplier::orderBy('name', 'ASC')->get();
}

public function show($id)
{
    $supplier = $this->service->getSupplierDetails($id);

    return response()->json([
        'status' => 'success',
        'data' => $supplier
    ]);
}


//تعديل مورد
public function update(Request $request, $id)
    {
        // 1. التحقق من البيانات المرسلة
        $request->validate([
            'action' => '|in:add,remove,update_details',
            'name'   => 'sometimes|string',
            // يمكن إضافة قواعد تحقق أخرى هنا
        ]);

        try {
            // 2. تمرير الطلب للسيرفيس
            $result = $this->service->update($id, $request->all());

            return response()->json([
                'status' => 'success',
                'message' => 'تمت العملية بنجاح',
                'data' => $result
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'حدث خطأ أثناء التعديل: ' . $e->getMessage()
            ], 500);
        }
    }
}


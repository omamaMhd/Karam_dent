<?php
namespace App\Services;

use App\Models\Supplier;
use App\Models\Item;
use App\Models\SupplierItem;
use Illuminate\Support\Facades\DB;

class SupplierService
{

public function getSupplierDetails(int $id)
{
    return Supplier::with([
        'supplierItems',
        'invoices' => function ($query) {
            $query->orderBy('created_at', 'desc');
        }
    ])->findOrFail($id);
}

//تثبيت المواد كدفعة وحدة
public function createBulkItems(array $items)
{
    return DB::transaction(function () use ($items) {

        $created = [];

        foreach ($items as $item) {
            $created[] = Item::create([
                'name' => $item['name'],
                'code' => $item['code'],
                'unit' => $item['unit'],
                'minimum_stock' => $item['minimum_stock'],
                'max_stock' => $item['max_stock'],

                'is_active' => true,
            ]);
        }

        return $created;
    });
}


public function createSupplierWithItems(array $data)
{
    return DB::transaction(function () use ($data) {
        $supplier = Supplier::create([
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        foreach ($data['items'] as $row) {

            if (!empty($row['item_id'])) {
                // ✔ مادة موجودة مسبقاً
                $item = Item::findOrFail($row['item_id']);
            } else {
                // 🆕 مادة جديدة تماماً - يتم إنشاؤها فوراً بحدودها المخزنية
                $item = Item::create([
                    'name' => $row['name'],
                    'code' => $row['code'] ?? strtoupper(uniqid('ITEM_')),
                    'unit' => $row['unit'] ?? 'unit',
                    'minimum_stock' => $row['minimum_stock'] ?? 0, // 💡 هنا تم الاستقبال
                    'max_stock' => $row['max_stock'] ?? 0,         // 💡 هنا تم الاستقبال
                    'is_active' => true,
                ]);
            }

            // ربط المادة بالمورد (الآن item_id لن يكون null أبداً)
            SupplierItem::create([
                'supplier_id' => $supplier->id,
                'item_id' => $item->id,
                'name' => $item->name,
                'unit' => $item->unit,
                'code' => $item->code,
            ]);
        }

        return $supplier->load('supplierItems');
    });
}
    //عرض المواد الموجودة 
    public function getAvailableItems()
{
    return Item::select([
            'id',
            'name',
            'code',
            'unit',
            'minimum_stock',
            'max_stock',
           'current_stock'
        ])
        ->where('is_active', true)
        ->orderBy('name')
        ->get();
}
public function addItemToSupplier(int $supplierId, array $data)
{
    // 1. إذا كان الـ item_id موجوداً (إضافة مادة موجودة)، نجلب بياناتها من جدول Items
    if (isset($data['item_id'])) {
        $item = \App\Models\Item::findOrFail($data['item_id']);
        
        return SupplierItem::create([
            'supplier_id' => $supplierId,
            'item_id'     => $item->id,
            'name'        => $item->name, // نأخذ الاسم من الداتابيس مباشرة
            'unit'        => $data['unit'] ?? $item->unit,
            'code'        => $data['code'] ?? $item->code,
        ]);
    }

    // 2. إذا لم يكن هناك item_id، فهذه مادة جديدة، ننشئها (كما فعلنا سابقاً)
    $newItem = \App\Models\Item::create([
        'name'          => $data['name'],
        'code'          => $data['code'] ?? 'CODE-' . uniqid(),
        'unit'          => $data['unit'] ?? 'قطعة',
        'minimum_stock' => $data['minimum_stock'] ?? 0,
        'max_stock'     => $data['max_stock'] ?? 0,
    ]);

    return SupplierItem::create([
        'supplier_id' => $supplierId,
        'item_id'     => $newItem->id,
        'name'        => $newItem->name,
        'unit'        => $newItem->unit,
        'code'        => $newItem->code,
    ]);
}
public function removeItemFromSupplier(int $supplierId, int $itemId)
{
    // حذف المادة المحددة فقط لهذا المورد
    return SupplierItem::where('supplier_id', $supplierId)
                       ->where('item_id', $itemId)
                       ->delete();
}


public function update(int $supplierId, array $data)
{
    return DB::transaction(function () use ($supplierId, $data) {
        
        // 1. تحديث المورد إذا كان الاسم موجوداً في الطلب
        if (isset($data['name'])) {
            $supplier = Supplier::findOrFail($supplierId);
            $supplier->update([
                'name' => $data['name'],
                'phone' => $data['phone'] ?? $supplier->phone,
            ]);
        }

        // 2. تحديث قائمة المواد بناءً على الـ action
        if (isset($data['action'])) {
            // استخراج بيانات المادة إذا كانت موجودة داخل item
            $itemData = $data['item'] ?? $data; 

            switch ($data['action']) {
                case 'add':
                    return $this->addItemToSupplier($supplierId, $itemData);
                case 'remove':
                    return $this->removeItemFromSupplier($supplierId, $data['item_id']);
                case 'update_details':
                    return $this->updateItemDetails($supplierId, $data['item_id'], $itemData);
            }
        }
    });
}


}
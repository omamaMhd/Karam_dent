<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class MaterialRequestItem extends Model
{
    use FixJsonDateFormat;

    protected $table = 'material_request_items';
      protected $fillable = [
        'material_request_id',
        'item_id',
        //'quantity',
        'quantity_requested', 
        //'quantity_fulfilled', 
        'batch_number_used', 
        'inventory_id'
        // 'approved_quantity',
        // 'status'
    ];

    // 🔗 الطلب
    public function request()
    {
        return $this->belongsTo(MaterialRequest::class, 'material_request_id');
    }

    // 📦 المادة
    public function item()
    {
        return $this->belongsTo(Item::class);
    }
    ///////////////////////
    public function inventory()
     { 
        return $this->belongsTo(Inventory::class);
         }
}

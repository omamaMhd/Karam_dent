<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;



class SupplierItem extends Model
{
    use FixJsonDateFormat;

    protected $table = 'supplier_items';
    protected $fillable = [
        'supplier_id',
        'item_id',
        'name',
        'unit',
    ];
    public function item()
{
    return $this->belongsTo(Item::class);
}

public function supplier()
{
    return $this->belongsTo(Supplier::class);
}
}

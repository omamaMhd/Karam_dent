<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class Invoice_Item extends Model
{
    use FixJsonDateFormat;
    protected $table = 'invoice_items';
    protected $fillable = [
        'invoice_id',
        'item_id',
        'treatment_session_id',
        'description',
        'quantity',
        'unit_price',
        'subtotal'
    ];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
     public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
    public function treatmentSession()
    {
        return $this->belongsTo(Treatment_Session::class);
    }
}

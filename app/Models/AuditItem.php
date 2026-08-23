<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class AuditItem extends Model implements Auditable  
{
    use FixJsonDateFormat;
    use \OwenIt\Auditing\Auditable;
    protected $table = 'audit_items';
     protected $fillable = [
        'inventory_audit_id',
         'item_id', 
         'batch_number', 
         'quantity_expected',
          'quantity_actual', 
          'variance_reason', 
          'notes'];
    
    public function audit()
     { return $this->belongsTo(InventoryAudit::class); }
    public function item()
    { return $this->belongsTo(Item::class); }
}

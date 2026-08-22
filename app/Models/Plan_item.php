<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class Plan_Item extends Model 
{
    use FixJsonDateFormat;


    protected $table = 'plan_items';

    protected $fillable = [
        'plan_id',
        'category_id',
        'notes',
        'status',
    ];

    public function plan()
    {
        return $this->belongsTo(Treatment_Plan::class, 'plan_id');
    }

    public function category()
    {
        return $this->belongsTo(Treatment_Category::class, 'category_id');
    }

    public function sessions()
    {
        return $this->hasMany(Treatment_Session::class, 'plan_item_id');
    }
 /*   public function invoices()
    {
        return $this->hasMany(Invoice::class, 'plan_id');
    }*/
}
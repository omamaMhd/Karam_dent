<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class Treatment_Category extends Model
{
    use FixJsonDateFormat;

    protected $table = 'treatment_categories';

    protected $fillable = [
        'name',
        'price_usd',
    ];

    public function planItems()
    {
        return $this->hasMany(Plan_Item::class, 'category_id');
    }
    
}
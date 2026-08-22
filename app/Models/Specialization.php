<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;



class Specialization extends Model
{
    use FixJsonDateFormat;

    protected $table = 'specializations';
    protected $fillable = ['name','description'];

    public function doctors()
    {
        return $this->hasMany(Doctor::class);
    }
    
public function activeDoctor()
{
    return $this->hasOne(Doctor::class)
        ->where('is_active', true);
}
}

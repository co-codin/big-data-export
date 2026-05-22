<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Manufacturer extends Model
{
    protected $table = 'manufacturer';
    protected $primaryKey = 'manufacturer_id';
    public $timestamps = false;
    protected $fillable = ['manufacturer_name'];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'manufacturer_id', 'manufacturer_id');
    }
}

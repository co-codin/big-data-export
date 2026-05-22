<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $table = 'product';

    protected $primaryKey = 'product_id';

    public $timestamps = false;

    protected $fillable = ['product_name', 'category_id', 'manufacturer_id'];

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class, 'manufacturer_id', 'manufacturer_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class, 'product_id', 'product_id');
    }
}

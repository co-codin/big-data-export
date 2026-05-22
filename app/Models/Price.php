<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Price extends Model
{
    protected $table = 'price';
    protected $primaryKey = 'price_id';
    public $timestamps = false;
    protected $fillable = ['product_id', 'price', 'price_date'];

    protected $casts = [
        'price_date' => 'date',
        'price' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}

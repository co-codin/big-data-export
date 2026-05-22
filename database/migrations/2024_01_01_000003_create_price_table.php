<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price', function (Blueprint $table) {
            $table->bigIncrements('price_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('price', 14, 2);
            $table->date('price_date');

            $table->foreign('product_id')
                ->references('product_id')
                ->on('product')
                ->cascadeOnDelete();

            $table->index(['product_id', 'price_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price');
    }
};

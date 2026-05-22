<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product', function (Blueprint $table) {
            $table->bigIncrements('product_id');
            $table->string('product_name');
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('manufacturer_id');

            $table->foreign('manufacturer_id')
                ->references('manufacturer_id')
                ->on('manufacturer')
                ->cascadeOnDelete();

            $table->index('category_id');
            $table->index('manufacturer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturer', function (Blueprint $table) {
            $table->bigIncrements('manufacturer_id');
            $table->string('manufacturer_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manufacturer');
    }
};

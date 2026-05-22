<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('process_status', function (Blueprint $table) {
            $table->bigIncrements('ps_id');
            $table->string('ps_name');
        });

        DB::table('process_status')->insert([
            ['ps_id' => 1, 'ps_name' => 'Запуск'],
            ['ps_id' => 2, 'ps_name' => 'Завершен'],
            ['ps_id' => 3, 'ps_name' => 'Ошибка'],
        ]);

        // Keep the sequence ahead of seeded ids so later inserts don't collide.
        DB::statement("SELECT setval(pg_get_serial_sequence('process_status', 'ps_id'), (SELECT MAX(ps_id) FROM process_status))");
    }

    public function down(): void
    {
        Schema::dropIfExists('process_status');
    }
};

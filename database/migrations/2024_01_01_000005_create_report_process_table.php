<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_process', function (Blueprint $table) {
            $table->bigIncrements('rp_id');
            $table->integer('rp_pid');
            $table->dateTime('rp_start_datetime');
            $table->integer('rp_exec_time')->nullable()->comment('Execution time in milliseconds');
            $table->unsignedBigInteger('ps_id');
            $table->string('rp_file_save_path')->nullable();

            $table->foreign('ps_id')
                ->references('ps_id')
                ->on('process_status');

            $table->index('rp_start_datetime');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_process');
    }
};

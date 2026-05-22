<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the process_status lookup table. The three known
 * statuses are exposed type-safely as App\Enums\ProcessStatusId — use
 * that enum when comparing or assigning ps_id values.
 */
class ProcessStatus extends Model
{
    protected $table = 'process_status';
    protected $primaryKey = 'ps_id';
    public $timestamps = false;
    protected $fillable = ['ps_name'];
}

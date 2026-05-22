<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessStatus extends Model
{
    public const STARTED   = 1;
    public const COMPLETED = 2;
    public const ERROR     = 3;

    protected $table = 'process_status';
    protected $primaryKey = 'ps_id';
    public $timestamps = false;
    protected $fillable = ['ps_name'];
}

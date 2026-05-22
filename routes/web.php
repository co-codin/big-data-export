<?php

use App\Http\Controllers\ProcessController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ProcessController::class, 'index'])->name('processes.index');
Route::get('/processes/{id}/download', [ProcessController::class, 'download'])
    ->whereNumber('id')
    ->name('processes.download');

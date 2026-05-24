<?php

use App\Http\Controllers\ProcessController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ProcessController::class, 'index'])->name('processes.index');

Route::post('/reports', [ReportController::class, 'store'])->name('reports.store');

Route::get('/processes/{id}/download', [ProcessController::class, 'download'])
    ->whereNumber('id')
    ->name('processes.download');

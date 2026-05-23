<?php

namespace App\Http\Controllers;

use App\Models\ReportProcess;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ProcessController
{
    public function index()
    {
        // Drop rows that claim a saved file but whose file is no longer on
        // disk (e.g. storage was wiped between runs). Запуск and Ошибка rows
        // — which never set rp_file_save_path — pass through untouched.
        $processes = ReportProcess::with('status')
            ->orderByDesc('rp_id')
            ->get()
            ->filter(fn ($p) => $p->rp_file_save_path === null
                || Storage::disk('local')->exists($p->rp_file_save_path))
            ->values();

        return view('processes.index', [
            'processes' => $processes,
        ]);
    }

    public function download(int $id): Response
    {
        $process = ReportProcess::findOrFail($id);

        if (! $process->rp_file_save_path
            || ! Storage::disk('local')->exists($process->rp_file_save_path)) {
            abort(404);
        }

        return Storage::disk('local')->download(
            $process->rp_file_save_path,
            basename($process->rp_file_save_path)
        );
    }
}

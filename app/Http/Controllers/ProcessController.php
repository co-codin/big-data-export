<?php

namespace App\Http\Controllers;

use App\Models\ReportProcess;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ProcessController
{
    public function index()
    {
        $processes = ReportProcess::with('status')
            ->orderByDesc('rp_id')
            ->get();

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

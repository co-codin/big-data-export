<?php

namespace App\Http\Controllers;

use App\Repositories\ReportProcessRepository;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ProcessController
{
    public function __construct(private readonly ReportProcessRepository $repo) {}

    public function index()
    {
        return view('processes.index', [
            'processes' => $this->repo->listForControlPage(),
        ]);
    }

    public function download(int $id): Response
    {
        $process = $this->repo->findOrFail($id);

        if (! $this->repo->hasDownloadableFile($process)) {
            abort(404);
        }

        return Storage::disk('local')->download(
            $process->rp_file_save_path,
            basename($process->rp_file_save_path),
        );
    }
}

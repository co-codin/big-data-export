<?php

namespace App\Repositories;

use App\Enums\ProcessStatusId;
use App\Models\ReportProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Data access + visibility rules for ReportProcess rows.
 * Keeps Storage/filesystem checks out of the controller so the controller
 * can stay focused on HTTP orchestration.
 */
class ReportProcessRepository
{
    /**
     * Listing for the control page: hides completed rows whose file is no
     * longer on disk (e.g. storage wiped between runs). Запуск and Ошибка
     * rows — which never set rp_file_save_path — pass through untouched.
     */
    public function listForControlPage(): Collection
    {
        return ReportProcess::with('status')
            ->orderByDesc('rp_id')
            ->get()
            ->filter(fn (ReportProcess $p) => $p->rp_file_save_path === null
                || Storage::disk('local')->exists($p->rp_file_save_path))
            ->values();
    }

    public function findOrFail(int $id): ReportProcess
    {
        return ReportProcess::findOrFail($id);
    }

    /**
     * True when the row's saved CSV is still readable from local storage.
     * False for Запуск / Ошибка rows (no path) and for completed rows whose
     * file was removed out-of-band.
     */
    public function hasDownloadableFile(ReportProcess $process): bool
    {
        return $process->rp_file_save_path !== null
            && Storage::disk('local')->exists($process->rp_file_save_path);
    }

    /**
     * Insert a fresh "Запуск" row at the start of dispatching one
     * (manufacturer, category) report — the producer creates one of these
     * per manufacturer before queuing the chunk jobs.
     */
    public function createStarted(int $pid, Carbon $startedAt): ReportProcess
    {
        return ReportProcess::create([
            'rp_pid' => $pid,
            'rp_start_datetime' => $startedAt,
            'ps_id' => ProcessStatusId::Started,
        ]);
    }

    /**
     * Spec: "при запуске процесса добавить запись". Records an Ошибка row
     * when dispatching gets short-circuited because the category has no
     * products — so the rejected attempt is still visible on the control
     * page.
     */
    public function recordEmptyAttempt(int $pid, Carbon $startedAt, int $execMs): ReportProcess
    {
        return ReportProcess::create([
            'rp_pid' => $pid,
            'rp_start_datetime' => $startedAt,
            'rp_exec_time' => $execMs,
            'ps_id' => ProcessStatusId::Error,
        ]);
    }
}

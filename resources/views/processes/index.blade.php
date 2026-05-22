<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Контроль выполнения процессов</title>
    <style>
        :root { color-scheme: light; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            margin: 24px;
            background: #f7f7f9;
            color: #222;
        }
        h1 { font-size: 22px; margin-bottom: 16px; }
        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06);
        }
        th, td {
            padding: 10px 12px;
            border-bottom: 1px solid #e5e5e9;
            text-align: left;
            font-size: 14px;
            vertical-align: top;
        }
        th { background: #f0f0f4; font-weight: 600; }
        tr.status-error { background: #fde8e8; }
        tr.status-error td { color: #8b1a1a; }
        a.download { color: #1a56db; text-decoration: none; }
        a.download:hover { text-decoration: underline; }
        .muted { color: #888; font-style: italic; }
        .empty { padding: 16px; background: #fff; border: 1px dashed #ccc; color: #666; }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }
    </style>
</head>
<body>
    <h1>Контроль выполнения процессов</h1>

    @if ($processes->isEmpty())
        <div class="empty">
            Записей пока нет. Сформируйте отчёт командой
            <code>php artisan report:generate {category_id}</code>.
        </div>
    @else
        <table>
            <thead>
                <tr>
                    <th>Дата процесса</th>
                    <th>Время выполнения (мс)</th>
                    <th>PID</th>
                    <th>Статус</th>
                    <th>Файл</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($processes as $process)
                    @php
                        $isError = (int) $process->ps_id === \App\Models\ProcessStatus::ERROR;
                        $isCompleted = (int) $process->ps_id === \App\Models\ProcessStatus::COMPLETED;
                    @endphp
                    <tr class="{{ $isError ? 'status-error' : '' }}">
                        <td>{{ optional($process->rp_start_datetime)->format('Y-m-d H:i:s') }}</td>
                        <td>{{ $process->rp_exec_time !== null ? $process->rp_exec_time : '—' }}</td>
                        <td>{{ $process->rp_pid }}</td>
                        <td>{{ $process->status?->ps_name ?? '—' }}</td>
                        <td>
                            @if ($isCompleted && $process->rp_file_save_path)
                                <a class="download"
                                   href="{{ route('processes.download', ['id' => $process->rp_id]) }}">
                                    {{ basename($process->rp_file_save_path) }}
                                </a>
                            @elseif ($process->rp_file_save_path)
                                <span class="muted">{{ basename($process->rp_file_save_path) }}</span>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>

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

        form.dispatch {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #fff;
            padding: 12px 16px;
            border: 1px solid #e5e5e9;
            border-radius: 4px;
            margin-bottom: 16px;
        }
        form.dispatch label { font-size: 14px; }
        form.dispatch input[type="number"] {
            width: 100px;
            padding: 6px 8px;
            border: 1px solid #ccc;
            border-radius: 3px;
            font-size: 14px;
        }
        form.dispatch button {
            padding: 6px 14px;
            background: #1a56db;
            color: #fff;
            border: 0;
            border-radius: 3px;
            font-size: 14px;
            cursor: pointer;
        }
        form.dispatch button:hover { background: #144aba; }

        .flash {
            padding: 10px 14px;
            border-radius: 4px;
            margin-bottom: 12px;
            font-size: 14px;
        }
        .flash-success { background: #e8f4e6; color: #1d6029; border: 1px solid #b6dbb1; }
        .flash-error { background: #fde8e8; color: #8b1a1a; border: 1px solid #f1bcbc; }
    </style>
</head>
<body>
    <h1>Контроль выполнения процессов</h1>

    @if (session('success'))
        <div class="flash flash-success">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="flash flash-error">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form class="dispatch" method="POST" action="{{ route('reports.store') }}">
        @csrf
        <label for="category_id">Категория:</label>
        <input
            type="number"
            id="category_id"
            name="category_id"
            min="1"
            value="{{ old('category_id', 1) }}"
            required
        >
        <button type="submit">Сформировать отчёт</button>
    </form>

    @if ($processes->isEmpty())
        <div class="empty">
            Записей пока нет. Сформируйте отчёт через форму выше или командой
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
                        $isError = $process->ps_id === \App\Enums\ProcessStatusId::Error;
                        $isCompleted = $process->ps_id === \App\Enums\ProcessStatusId::Completed;
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

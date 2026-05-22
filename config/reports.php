<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Output subdirectory under storage/app/ for generated CSVs.
    |--------------------------------------------------------------------------
    |
    | Tests override this to 'reports_test' so the dev directory stays clean.
    */
    'subdir' => env('REPORT_SUBDIR', 'reports'),

    /*
    |--------------------------------------------------------------------------
    | Products per sub-job
    |--------------------------------------------------------------------------
    |
    | The producer command splits each manufacturer's product set into ranges
    | of this size. Each range becomes one queued GenerateReportChunkJob.
    | A larger value = fewer, longer jobs; smaller = more parallelism.
    */
    'chunk_size' => (int) env('REPORT_CHUNK_SIZE', 5000),
];

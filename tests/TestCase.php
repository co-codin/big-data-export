<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Recursively delete the configured test-only reports subdir so tests
     * don't leave files behind. Safe to call when the dir doesn't exist.
     */
    protected function tearDown(): void
    {
        $subdir = config('reports.subdir');
        if ($subdir && $subdir !== 'reports') {
            $path = storage_path('app/'.$subdir);
            if (is_dir($path)) {
                $items = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($items as $item) {
                    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                }
                @rmdir($path);
            }
        }

        parent::tearDown();
    }
}

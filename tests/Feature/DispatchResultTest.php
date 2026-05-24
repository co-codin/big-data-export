<?php

namespace Tests\Feature;

use App\Data\DispatchedReport;
use App\Data\DispatchResult;
use Tests\TestCase;

/**
 * Locks in the Spatie\LaravelData contract: DispatchResult serializes to
 * the camelCase shape API consumers will see when an HTTP endpoint is
 * added later.
 */
class DispatchResultTest extends TestCase
{
    public function test_to_array_serializes_nested_dispatched_reports(): void
    {
        $result = new DispatchResult(
            emptyCategory: false,
            reports: [
                new DispatchedReport(rpId: 1, manufacturerId: 10, chunkCount: 3),
                new DispatchedReport(rpId: 2, manufacturerId: 20, chunkCount: 1),
            ],
            sync: true,
        );

        $this->assertSame(
            [
                'emptyCategory' => false,
                'reports' => [
                    ['rpId' => 1, 'manufacturerId' => 10, 'chunkCount' => 3],
                    ['rpId' => 2, 'manufacturerId' => 20, 'chunkCount' => 1],
                ],
                'sync' => true,
            ],
            $result->toArray(),
        );
    }

    public function test_to_json_emits_a_stable_payload(): void
    {
        $result = new DispatchResult(
            emptyCategory: true,
            reports: [],
            sync: false,
        );

        $this->assertSame(
            '{"emptyCategory":true,"reports":[],"sync":false}',
            $result->toJson(),
        );
    }
}

<?php

namespace App\Enums;

/**
 * Backed enum mirroring the seeded rows in the `process_status` table.
 * The integer values are the primary keys assigned by the migration.
 */
enum ProcessStatusId: int
{
    case Started = 1;
    case Completed = 2;
    case Error = 3;
}

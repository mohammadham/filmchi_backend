<?php

namespace App\Services\Relay;

use RuntimeException;

/**
 * A file was not admitted because the ingest budget cannot hold it right now.
 *
 * This is not a source failure: the disk is still holding files that have not
 * been uploaded yet. The job defers itself instead of spending an attempt on
 * a condition the source has nothing to do with.
 */
class DiskBudgetExceeded extends RuntimeException
{
}

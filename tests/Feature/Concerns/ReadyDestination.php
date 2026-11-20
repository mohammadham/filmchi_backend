<?php

namespace Tests\Feature\Concerns;

use App\Services\Relay\DestinationCheck;

/**
 * A ready upload destination for tests about something else (disk, ranges,
 * retry decisions). Only the preflight's answer is replaced; the job still
 * calls it in the real place.
 */
trait ReadyDestination
{
    protected function stubReadyDestination(): void
    {
        $this->app->instance(DestinationCheck::class, new class extends DestinationCheck {
            public function __construct()
            {
            }

            public function assertReady(): void
            {
            }
        });
    }
}

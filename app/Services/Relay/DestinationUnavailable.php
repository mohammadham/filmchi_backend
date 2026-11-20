<?php

namespace App\Services\Relay;

use RuntimeException;

/**
 * The upload destination cannot take bytes right now.
 *
 * This is not a source failure: the storage service is disabled, unreachable,
 * or no Telegram account can write to the storage chat. The ingest job defers
 * itself instead of spending an attempt (and instead of downloading a file it
 * could never store).
 */
class DestinationUnavailable extends RuntimeException
{
}

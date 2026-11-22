<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A failure that ends the same way on every attempt.
 *
 * The queue retries ImportBotFileJob three times, and the manifest path mints a
 * fresh file id per attempt, so retrying a failure of this kind would only leave
 * one more half-registered row in Telegram Drive. The job fails on the first
 * one instead of spending the attempts.
 */
class PermanentImportFailure extends RuntimeException
{
}

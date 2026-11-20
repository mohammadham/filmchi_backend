<?php

namespace App\Services\Relay;

use RuntimeException;

/**
 * A non-2xx answer from Telegram Drive, carrying the HTTP status so callers can
 * branch on it (e.g. "this service cannot adopt, use the manifest fallback")
 * instead of parsing the message text.
 *
 * Extends RuntimeException, so every existing `catch (RuntimeException)` in the
 * relay code keeps working unchanged.
 */
class TelegramDriveRefusal extends RuntimeException
{
    protected int $status;

    public function __construct(string $message, int $status)
    {
        parent::__construct($message);

        $this->status = $status;
    }

    public function status(): int
    {
        return $this->status;
    }
}

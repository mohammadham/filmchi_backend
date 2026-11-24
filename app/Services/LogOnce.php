<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * One warning line per key per hour.
 *
 * The webhook, the bot queue and the supervisor all work unattended: any of
 * them can hit the same fault every tick, and a line per tick buries the rest
 * of the log. Cache::add is atomic, so of the processes inside the window
 * exactly one writes the line - the first one to notice the fault.
 */
final class LogOnce
{
    public static function warning(string $key, string $message, array $context = []): void
    {
        if (Cache::add('relay:warn:' . $key, true, now()->addHour())) {
            Log::warning($message, $context);
        }
    }
}

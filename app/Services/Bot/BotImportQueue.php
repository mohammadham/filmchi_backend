<?php

namespace App\Services\Bot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Whether dispatching a bot import ever reaches a worker process.
 *
 * Two drivers never do: `sync` runs the job inside the caller (the webhook
 * request, the supervisor sweep) and `null` drops it without a trace. The
 * panel's card, the webhook and the supervisor all read this one answer.
 */
class BotImportQueue
{
    public const INLINE_DRIVERS = ['sync', 'null'];

    public static function driver(): string
    {
        return (string) config('queue.default');
    }

    public static function runsInline(): bool
    {
        return in_array(static::driver(), self::INLINE_DRIVERS, true);
    }

    /**
     * One line an hour, written by whichever process notices first: the same
     * misconfiguration must not be repeated per update or per sweep.
     */
    public static function warnOnceAnHour(): void
    {
        if (!static::runsInline()) {
            return;
        }

        if (!Cache::add('relay:bot:warn:inline-warning', true, now()->addHour())) {
            return;
        }

        $driver = static::driver();

        // null is the worse of the two: sync at least runs the import, null
        // throws it away. The line says which one the install is on.
        Log::warning($driver === 'null'
            ? 'bot import is dropped: queue.default is null'
            : 'bot import runs inline: queue.default is ' . $driver, [
                'fix' => 'set QUEUE_CONNECTION=redis (or database) and run relay:queue-supervisor',
            ]);
    }
}

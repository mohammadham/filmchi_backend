<?php

namespace App\Jobs;

use App\ContentSource;
use App\EpisodeSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Probes one external source link and records whether it is still alive.
 *
 * HEAD first; servers that refuse it get a ranged GET instead. A failure
 * increments a consecutive-fail counter so a link is only declared dead
 * after several passes (a single hiccup should not hide a good source).
 */
class ProbeSourceLinkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 1;

    /** @var string */
    public $sourceType;

    /** @var int */
    public $sourceId;

    public function __construct(string $sourceType, int $sourceId)
    {
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;

        $this->onQueue((string) config('media_relay.queue.name', 'default'));
    }

    public function handle(): void
    {
        $source = $this->source();

        if (!$source || empty($source->source)) {
            return;
        }

        // Media-gallery-backed sources (file type) are managed by the relay,
        // and YouTube ids are not plain URLs.
        if ((int) $source->type === 1 || (int) $source->type === 7) {
            return;
        }

        $url = (string) $source->source;

        if (!Str::startsWith($url, ['http://', 'https://'])) {
            return;
        }

        $status = $this->probe($url);
        $checkedAt = now();

        if ($status === 'alive') {
            $source->link_status = 'alive';
            $source->link_fail_count = 0;
            $source->link_checked_at = $checkedAt;
            $source->save();

            return;
        }

        $fails = (int) $source->link_fail_count + 1;
        $deadAfter = max(1, (int) config('media_relay.linkcheck.dead_after', 3));

        $source->link_fail_count = $fails;
        $source->link_status = $fails >= $deadAfter ? 'dead' : ($source->link_status === 'dead' ? 'dead' : 'alive');
        $source->link_checked_at = $checkedAt;
        $source->save();
    }

    protected function probe(string $url): string
    {
        try {
            $response = Http::timeout((int) config('media_relay.linkcheck.timeout', 15))
                ->head($url);

            if ($response->status() < 400) {
                return 'alive';
            }

            if (!in_array($response->status(), [403, 404, 405, 410, 501], true)) {
                return 'dead';
            }
        } catch (\Throwable $e) {
            return 'dead';
        }

        // HEAD was refused (403/405/501): retry as a ranged GET, which most
        // media servers accept, and drop the body immediately.
        try {
            $response = Http::timeout((int) config('media_relay.linkcheck.timeout', 15))
                ->withHeaders(['Range' => 'bytes=0-0'])
                ->get($url);

            return $response->status() < 400 ? 'alive' : 'dead';
        } catch (\Throwable $e) {
            return 'dead';
        }
    }

    protected function source()
    {
        if ($this->sourceType === 'content') {
            return ContentSource::find($this->sourceId);
        }

        if ($this->sourceType === 'episode') {
            return EpisodeSource::find($this->sourceId);
        }

        return null;
    }
}

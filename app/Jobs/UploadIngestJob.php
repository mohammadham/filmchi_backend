<?php

namespace App\Jobs;

use App\ContentSource;
use App\EpisodeSource;
use App\Services\Relay\RelaySettings;
use App\Services\Relay\RelayStorage;
use App\Services\Relay\UploadMetrics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Uploads a .part file produced by IngestUrlJob.
 *
 * This runs on a separate queue (`uploads`) with its own worker pool. That
 * keeps download slots occupied while uploads are slow, instead of letting
 * every ingest worker sit in the upload phase and making both lists empty.
 */
class UploadIngestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Waiting for Telegram Drive to split and store a large file. */
    public $timeout = 7000;

    public $tries = 2;

    /** @var array<int, int> */
    public $backoff = [120, 600];

    /** @var string */
    public $sourceType;

    /** @var int */
    public $sourceId;

    /** @var string */
    public $part;

    /** @var string */
    public $name;

    /** @var string|null */
    public $mime;

    /** @var string|null */
    public $folder;

    public function __construct(
        string $sourceType,
        int $sourceId,
        string $part,
        string $name,
        ?string $mime = null,
        ?string $folder = null
    ) {
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->part = $part;
        $this->name = $name;
        $this->mime = $mime;
        $this->folder = $folder;

        $this->onQueue((string) config('media_relay.upload.queue', 'uploads'));
    }

    public function handle(RelayStorage $storage): void
    {
        RelaySettings::apply();

        $source = $this->source();

        if (!$source) {
            $this->removePart();

            return;
        }

        if ((string) $source->ingest_status === 'completed') {
            $this->removePart();

            return;
        }

        if (!is_file($this->part)) {
            $message = 'The downloaded .part file is missing; the upload cannot continue.';
            $this->markFailed($source, $message);

            throw new \RuntimeException($message);
        }

        $source->ingest_status = 'uploading';
        $source->ingest_progress = 100;
        $source->ingest_connections = 0;
        $source->ingest_error = null;
        $source->save();

        UploadMetrics::markStarted($source);
        $uploadBytes = (int) @filesize($this->part);
        $startedAt = now();
        $metrics = [];

        try {
            $relayUrl = $storage->storePath(
                $this->part,
                $this->name,
                $this->mime,
                null,
                $uploadBytes,
                $this->folder,
                $metrics
            );

            UploadMetrics::apply($source, $metrics);
            $source->source = $relayUrl;
            $source->ingest_status = 'completed';
            $source->ingest_progress = 100;
            $source->ingest_error = null;
            $source->save();

            $this->removePart();
        } catch (Throwable $e) {
            Log::warning('relay upload failed', [
                'source_type' => $this->sourceType,
                'source_id' => $this->sourceId,
                'message' => $e->getMessage(),
            ]);

            UploadMetrics::markFailed($source, $uploadBytes, $startedAt);
            $this->markFailed($source, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Called by Laravel after the final attempt. Keep a part only for a
     * retryable failure; once retries are exhausted remove it so a failed
     * transfer cannot fill the disk silently.
     */
    public function failed(Throwable $e): void
    {
        $source = $this->source();

        if ($source) {
            $this->markFailed($source, $e->getMessage());
        }

        $this->removePart();
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

    protected function markFailed($source, string $message): void
    {
        $source->ingest_status = 'failed';
        $source->ingest_error = Str::limit($message, 500);
        $source->save();
    }

    protected function removePart(): void
    {
        if (is_file($this->part)) {
            @unlink($this->part);
        }
    }
}

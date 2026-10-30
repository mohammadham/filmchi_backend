<?php

namespace App\Console\Commands;

use App\RelayFile;
use App\Services\Relay\CachePromoter;
use App\Services\Relay\RelayManager;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Deep-verifies cached copies: streams the bytes from the cache tier,
 * hashes them and compares with the sha256 recorded at upload.
 *
 * A mismatch evicts the broken copy so demand re-promotes a fresh one.
 *
 *   php artisan relay:verify            # verify up to --limit warm files
 *   php artisan relay:verify --id=42    # verify one file
 */
class RelayVerify extends Command
{
    /** @var string */
    protected $signature = 'relay:verify
        {--id= : Verify one specific relay file}
        {--limit=5 : How many warm files to verify per pass}';

    /** @var string */
    protected $description = 'Deep-verify cached copies (sha256) and evict broken ones';

    public function handle(RelayManager $relay, CachePromoter $promoter)
    {
        if ($this->option('id') !== null) {
            $files = RelayFile::where('id', (int) $this->option('id'))->get();
        } else {
            $files = RelayFile::where('cache_state', RelayFile::WARM)
                ->whereNotNull('checksum')
                ->orderBy('promoted_at', 'asc')
                ->limit((int) $this->option('limit'))
                ->get();
        }

        if ($files->isEmpty()) {
            $this->info('Nothing to verify.');

            return 0;
        }

        $ok = 0;
        $skipped = 0;
        $broken = 0;

        foreach ($files as $file) {
            if (empty($file->checksum)) {
                $this->line("[{$file->id}] {$file->name}: no checksum recorded, skipped.");
                $skipped++;

                continue;
            }

            try {
                $actual = $this->streamHash($relay, $file);
            } catch (\Throwable $e) {
                $this->error("[{$file->id}] {$file->name}: unreadable ({$e->getMessage()})");
                $broken++;

                continue;
            }

            if ($actual === null) {
                $this->line("[{$file->id}] {$file->name}: not verifiable from the cache tier.");
                $skipped++;

                continue;
            }

            if (hash_equals($file->checksum, $actual)) {
                $this->info("[{$file->id}] {$file->name}: OK");
                $ok++;

                continue;
            }

            $this->error("[{$file->id}] {$file->name}: CHECKSUM MISMATCH — evicting the cached copy.");
            $promoter->evict($file, true);
            $broken++;
        }

        $this->table(['Verified OK', 'Skipped', 'Evicted/broken'], [[$ok, $skipped, $broken]]);

        return $broken > 0 ? 1 : 0;
    }

    /**
     * Stream the file through the cache tier and return its sha256, or null
     * when the tier has no direct URL for it.
     */
    protected function streamHash(RelayManager $relay, RelayFile $file): ?string
    {
        $url = $relay->cacheUrl($file, false);

        if (empty($url)) {
            return null;
        }

        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => (int) config('media_relay.relay.stream_timeout', 1800),
            'user_agent' => (string) config('media_relay.relay.user_agent', 'flixy-media-relay/1.0'),
            'follow_location' => 1,
        ]]);

        $stream = @fopen($url, 'rb', false, $context);

        if ($stream === false) {
            throw new RuntimeException('could not open the cache URL');
        }

        $hash = hash_init('sha256');

        try {
            while (!feof($stream)) {
                $buffer = fread($stream, 262144);

                if ($buffer === false || $buffer === '') {
                    continue;
                }

                hash_update($hash, $buffer);
            }
        } finally {
            fclose($stream);
        }

        return hash_final($hash);
    }
}

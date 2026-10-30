<?php

namespace App\Services\Relay;

use App\RelayFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Extracts a poster frame from a freshly uploaded video.
 *
 * Runs at upload time, while the file still exists as a local temp upload,
 * and writes a small JPEG under storage/app/public/relay-thumbs/. Any
 * failure is non-fatal: a relay file simply lives without a preview.
 */
class Thumbnail
{
    public static function make(string $sourcePath, string $mime, RelayFile $file): void
    {
        if (!Str::startsWith(strtolower((string) $mime), 'video/')) {
            return;
        }

        if (!config('media_relay.thumbnails.enabled', true)) {
            return;
        }

        $ffmpeg = (string) config('media_relay.thumbnails.ffmpeg', '/usr/bin/ffmpeg');
        $ffprobe = (string) config('media_relay.thumbnails.ffprobe', '/usr/bin/ffprobe');

        if (!is_executable($ffmpeg)) {
            return;
        }

        try {
            $width = max(120, (int) config('media_relay.thumbnails.width', 480));
            $dest = 'relay-thumbs/' . $file->id . '.jpg';
            $destPath = storage_path('app/public/' . $dest);

            @mkdir(dirname($destPath), 0775, true);

            $at = self::probePosition($ffprobe, $sourcePath);

            $command = [$ffmpeg, '-y', '-i', $sourcePath, '-frames:v', '1'];

            if ($at > 0) {
                array_unshift($command, $at);
                array_unshift($command, '-ss');
                array_splice($command, 3, 0, ['-i']);
                array_splice($command, 4, 1);
                array_unshift($command, $ffmpeg);
                // rebuild cleanly below instead of splicing games
                $command = [$ffmpeg, '-ss', (string) $at, '-i', $sourcePath, '-frames:v', '1'];
            }

            $result = self::run($command, $destPath, $width);

            if (!$result && $at > 0) {
                // Very short files can fail with an input seek; retry from 0.
                $result = self::run([$ffmpeg, '-y', '-i', $sourcePath, '-frames:v', '1'], $destPath, $width);
            }

            if ($result) {
                $file->thumb = $dest;
                $file->save();
            }
        } catch (\Throwable $e) {
            Log::warning('Relay thumbnail generation failed', [
                'relay_file_id' => $file->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected static function run(array $command, string $destPath, int $width): bool
    {
        $command = array_merge($command, [
            '-update', '1',
            '-vf', 'scale=' . $width . ':-2', '-q:v', '4', $destPath,
        ]);

        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        if ($process->isSuccessful() && is_file($destPath) && filesize($destPath) > 0) {
            return true;
        }

        return false;
    }

    /**
     * Sample point inside the video: ~10% in, at least 2s, capped at 120s.
     */
    protected static function probePosition(string $ffprobe, string $sourcePath): int
    {
        if (!is_executable($ffprobe)) {
            return 0;
        }

        try {
            $process = new Process([
                $ffprobe,
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $sourcePath,
            ]);
            $process->setTimeout(30);
            $process->run();

            $duration = (float) trim($process->getOutput());

            if ($duration <= 0) {
                return 0;
            }

            return min(120, max(2, (int) ($duration * 0.1)));
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

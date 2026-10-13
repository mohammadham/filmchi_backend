<?php

namespace App\Services\Relay;

use RuntimeException;

/**
 * Thin wrappers around PHP's http:// stream wrapper.
 *
 * The relay must never persist bytes, so everything here works on live
 * streams: a response body is read in chunks and re-emitted immediately.
 */
class Streams
{
    /**
     * Open a remote URL as a readable PHP stream.
     *
     * @return array{stream: resource, status: int, headers: array<string,string>}
     */
    public static function open(string $url, array $headers = [], int $timeout = 300)
    {
        // PHP rejects stream timeouts above ~2147s (the platform select()
        // limit), so clamp here rather than letting a config value break
        // transfers at runtime.
        $timeout = max(1, min($timeout, 2000));

        // A dedicated connection per stream: proxying must never reuse a
        // keep-alive socket (a stale one silently truncates a response) and
        // promotion opens a second stream while the first is still reading.
        $lower = array_map('strtolower', array_keys($headers));
        if (!in_array('connection', $lower, true)) {
            $headers['Connection'] = 'close';
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $lines),
                'follow_location' => 1,
                'max_redirects' => 5,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        // Capture the reason even though the warning is suppressed, so an
        // unreachable tier produces a diagnosable error instead of a bare URL.
        $reason = null;
        set_error_handler(function ($level, $message) use (&$reason) {
            $reason = $message;

            return true;
        });

        try {
            $handle = fopen($url, 'rb', false, $context);
        } finally {
            restore_error_handler();
        }

        if ($handle === false) {
            throw new RuntimeException(
                'Unable to open upstream stream: ' . $url . ($reason ? ' (' . $reason . ')' : '')
            );
        }

        [$status, $parsed] = self::parseResponseHeaders($http_response_header ?? []);

        return ['stream' => $handle, 'status' => $status, 'headers' => $parsed];
    }

    /**
     * Parse the $http_response_header array into (status, headers).
     * Redirects append more blocks, so the last block wins.
     *
     * @return array{0:int,1:array<string,string>}
     */
    protected static function parseResponseHeaders(array $raw)
    {
        $status = 200;
        $headers = [];

        foreach ($raw as $line) {
            if (stripos($line, 'HTTP/') === 0) {
                $parts = explode(' ', $line, 3);
                $status = isset($parts[1]) ? (int) $parts[1] : $status;
                $headers = [];
                continue;
            }

            $position = strpos($line, ':');
            if ($position === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $position)));
            $headers[$name] = trim(substr($line, $position + 1));
        }

        return [$status, $headers];
    }
}

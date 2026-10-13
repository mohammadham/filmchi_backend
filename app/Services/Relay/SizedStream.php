<?php

namespace App\Services\Relay;

use GuzzleHttp\Psr7\Stream;

/**
 * A live, non-seekable stream that still reports a known total size.
 *
 * Guzzle builds multipart bodies lazily, but only sends an explicit
 * Content-Length when every part has a known size. Without this wrapper the
 * upload goes out with chunked transfer-encoding, which some proxies and
 * origins reject. The stream itself is still read on demand, so the bytes are
 * never buffered to disk.
 */
class SizedStream extends Stream
{
    /** @var int */
    protected $knownSize;

    /**
     * @param  resource  $stream  live upstream stream
     */
    public function __construct($stream, int $size)
    {
        parent::__construct($stream);

        $this->knownSize = $size;
    }

    public function getSize(): ?int
    {
        return $this->knownSize;
    }

    public function isSeekable(): bool
    {
        return false;
    }
}

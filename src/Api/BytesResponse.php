<?php

namespace SLiMS\Plugins\Inventory\Api;

use SlimsConnect\Http\Sendable;

/** A PDF or photo produced in memory, never cached by the client or a proxy. */
final class BytesResponse implements Sendable
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mimeType,
        public readonly string $filename,
    ) {}

    public function send(string $requestId): void
    {
        if (!headers_sent()) {
            http_response_code(200);
            header('X-Request-Id: ' . $requestId);
            header('Content-Type: ' . $this->mimeType);
            header('Content-Length: ' . strlen($this->bytes));
            header('Content-Disposition: inline; filename="' . addcslashes($this->filename, '"\\') . '"');
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
            header("Content-Security-Policy: default-src 'none'; sandbox");
        }
        echo $this->bytes;
    }
}

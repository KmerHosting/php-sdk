<?php

declare(strict_types=1);

namespace KmerHosting;

/** HTTP 429. `retryAfter` is the server-suggested wait in seconds, when it was sent. */
final class RateLimitException extends ApiException
{
    public function __construct(
        string $message,
        string $apiCode,
        ?string $requestId = null,
        mixed $body = null,
        public readonly ?float $retryAfter = null,
        public readonly ?int $limit = null,
        public readonly ?int $remaining = null,
    ) {
        parent::__construct($message, 429, $apiCode, $requestId, $body);
    }
}

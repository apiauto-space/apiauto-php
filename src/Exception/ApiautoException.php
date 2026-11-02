<?php

declare(strict_types=1);

namespace Apiauto\Exception;

/**
 * Base class of every error the client throws for a failed request.
 *
 * API errors are JSON such as {"error": "quota_exhausted", "reset_at": "..."}; the client turns them
 * into a subclass chosen by the HTTP status, with the body's fields as properties.
 *
 * @see https://apiauto.space/en/docs/errors/
 */
class ApiautoException extends \RuntimeException
{
    /**
     * @param int|null $statusCode The HTTP status; null when no response came.
     * @param string|null $errorCode The error field of the body, such as quota_exhausted.
     * @param string|null $detail The detail field: what exactly is wrong with the request.
     * @param mixed $body The decoded JSON body, or the raw text when it is not JSON.
     * @param int|null $retryAfter Seconds to wait before retrying (429 and 503).
     * @param string|null $resetAt When the quota renews, ISO 8601 (402).
     */
    final public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $detail = null,
        public readonly mixed $body = null,
        public readonly ?int $retryAfter = null,
        public readonly ?string $resetAt = null,
    ) {
        parent::__construct($message, $statusCode ?? 0);
    }
}

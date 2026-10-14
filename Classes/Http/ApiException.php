<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Http;

/**
 * Error that is passed to the backend UI as JSON with the given HTTP status.
 *
 * 503 = not configured / not connected, 429 = quota exhausted, 409 = already running,
 * 400 = invalid request, 502 = upstream API failed.
 */
final class ApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 502, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }
}

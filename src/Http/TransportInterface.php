<?php

declare(strict_types=1);

namespace Apiauto\Http;

use Apiauto\Exception\ConnectionException;

/**
 * Sends one GET request. Replace it to route calls through your own HTTP stack or a test double.
 * It must not follow redirects to another host: the key travels in the headers.
 */
interface TransportInterface
{
    /**
     * @param array<string, string> $headers
     * @param float $timeout Seconds the whole request may take.
     * @throws ConnectionException When no response came: a network failure or a timeout.
     */
    public function get(string $url, #[\SensitiveParameter] array $headers, float $timeout): HttpResponse;
}

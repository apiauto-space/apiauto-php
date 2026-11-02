<?php

declare(strict_types=1);

namespace Apiauto\Tests;

use Apiauto\Exception\ConnectionException;
use Apiauto\Http\HttpResponse;
use Apiauto\Http\TransportInterface;

/** Answers from a queue and records every request; no network. */
final class FakeTransport implements TransportInterface
{
    /** @var list<array{url: string, headers: array<string, string>, timeout: float}> */
    public array $requests = [];

    /** @param list<HttpResponse|ConnectionException> $answers */
    public function __construct(private array $answers = [])
    {
    }

    public function get(string $url, array $headers, float $timeout): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'timeout' => $timeout];
        $answer = array_shift($this->answers);
        if ($answer === null) {
            throw new \LogicException('Unexpected request: ' . $url);
        }
        if ($answer instanceof ConnectionException) {
            throw $answer;
        }

        return $answer;
    }

    /**
     * @param array<mixed> $body
     * @param array<string, string> $headers
     */
    public static function json(array $body, int $status = 200, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, $headers, (string) json_encode($body));
    }
}

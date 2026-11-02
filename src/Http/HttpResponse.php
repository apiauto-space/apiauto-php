<?php

declare(strict_types=1);

namespace Apiauto\Http;

/** The status, headers and body of one HTTP response. */
final class HttpResponse
{
    /** @var array<string, string> Header names in lower case. */
    private array $headers = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        public readonly string $body = '',
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }

    /** The value of a header, whatever the case of its name; null when it is absent. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}

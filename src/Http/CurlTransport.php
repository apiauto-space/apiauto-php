<?php

declare(strict_types=1);

namespace Apiauto\Http;

use Apiauto\Exception\ApiautoException;
use Apiauto\Exception\ConnectionException;

/** The default transport, on the curl extension. */
final class CurlTransport implements TransportInterface
{
    /** A page is at most 100 records: anything far above that is not an API answer. */
    public const MAX_BODY_BYTES = 64 * 1024 * 1024;

    public function get(string $url, #[\SensitiveParameter] array $headers, float $timeout): HttpResponse
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new ConnectionException('The request to the API failed: curl could not start');
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $responseHeaders = [];
        // A stream, not a growing string: reallocating 64 MiB would break the default memory_limit.
        $stream = fopen('php://temp/maxmemory:1048576', 'r+b');
        if ($stream === false) {
            throw new ConnectionException('The request to the API failed: no room for the answer');
        }
        $size = 0;
        $tooLarge = false;
        curl_setopt_array($handle, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $lines,
            // Decompressed before the write function, so the limit holds for a compressed answer too.
            CURLOPT_ENCODING => '',
            // The key is in the headers: it must not reach another host through a redirect.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT_MS => (int) ceil($timeout * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($stream, &$size, &$tooLarge): int {
                $size += strlen($chunk);
                if ($size > self::MAX_BODY_BYTES) {
                    $tooLarge = true;

                    return 0;  // anything but the chunk's length makes curl stop
                }

                return (int) fwrite($stream, $chunk);
            },
        ]);

        $finished = curl_exec($handle);
        if ($tooLarge) {
            throw new ApiautoException('The API answer is larger than 64 MiB: stopped reading it');
        }
        if ($finished === false) {
            $kind = curl_errno($handle) === CURLE_OPERATION_TIMEDOUT ? 'timed out' : 'failed';
            throw new ConnectionException("The request to the API $kind: " . curl_error($handle));
        }

        rewind($stream);

        return new HttpResponse(
            (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            $responseHeaders,
            (string) stream_get_contents($stream),
        );
    }
}

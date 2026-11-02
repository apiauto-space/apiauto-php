<?php

declare(strict_types=1);

namespace Apiauto\Tests;

use Apiauto\Client;
use Apiauto\Exception\ApiautoException;
use Apiauto\Exception\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The real transport against a local PHP server: no access outside loopback. */
final class CurlTransportTest extends TestCase
{
    private const KEY = 'test-key-1234567890';

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $log = '';
    private static string $bomb = '';

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        self::$base = 'http://' . $name;
        self::$log = (string) tempnam(sys_get_temp_dir(), 'apiauto-log');
        self::$bomb = (string) tempnam(sys_get_temp_dir(), 'apiauto-bomb');

        // 70 MiB of the same byte: about 70 KiB on the wire.
        $gzip = gzopen(self::$bomb, 'wb9');
        self::assertNotFalse($gzip);
        $chunk = str_repeat('A', 1024 * 1024);
        for ($i = 0; $i < 70; $i++) {
            gzwrite($gzip, $chunk);
        }
        gzclose($gzip);

        $env = [
            'APIAUTO_TEST_LOG' => self::$log,
            'APIAUTO_TEST_BOMB' => self::$bomb,
            'APIAUTO_TEST_TARGET' => self::$base . '/target',
        ];
        $process = proc_open(
            [PHP_BINARY, '-S', $name, __DIR__ . '/fixtures/server.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env + getenv(),
        );
        self::assertIsResource($process);
        self::$server = $process;
        register_shutdown_function(static fn () => is_resource($process) && proc_terminate($process));
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', (int) parse_url(self::$base, PHP_URL_PORT)) === false; $i++) {
            usleep(100_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        @unlink(self::$log);
        @unlink(self::$bomb);
    }

    private static function client(string $path = '/v1', float $timeout = 5.0): Client
    {
        return new Client(self::KEY, baseUrl: self::$base . $path, timeout: $timeout, maxRetries: 0);
    }

    public function testReadsAnAnswerAndItsHeaders(): void
    {
        $client = self::client();
        self::assertSame('max', $client->usage()->plan);
        self::assertSame(7, $client->lastResponse()?->quotaRemaining);
    }

    /** @return iterable<string, array{int}> */
    public static function redirects(): iterable
    {
        foreach ([301, 302, 303, 307, 308] as $code) {
            yield (string) $code => [$code];
        }
    }

    #[DataProvider('redirects')]
    public function testARedirectIsNotFollowedSoTheKeyStaysHome(int $code): void
    {
        file_put_contents(self::$log, '');
        $transport = new \Apiauto\Http\CurlTransport();
        $response = $transport->get(self::$base . "/redirect?code=$code", ['X-API-Key' => self::KEY], 5.0);
        self::assertSame($code, $response->status);
        self::assertSame('', file_get_contents(self::$log));
    }

    public function testNoDumpOfTheClientOrItsExceptionsShowsTheKey(): void
    {
        $output = [];
        $code = 1;
        exec(escapeshellarg(PHP_BINARY) . ' -d zend.exception_ignore_args=0 ' . escapeshellarg(__DIR__ . '/fixtures/leak_check.php'), $output, $code);
        self::assertSame(0, $code);
        self::assertGreaterThan(10, count($output));
        self::assertContains('previous: none', $output);
        foreach ($output as $line) {
            self::assertStringNotContainsString('LEAK', $line);
        }
    }

    public function testATimeoutIsReported(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('timed out');
        self::client('/slow', 0.3)->usage();
    }

    public function testALargeCompressedAnswerIsCut(): void
    {
        self::assertLessThan(1024 * 1024, filesize(self::$bomb));
        $before = memory_get_peak_usage();
        try {
            self::client('/bomb')->usage();
            self::fail('expected an exception');
        } catch (ConnectionException $error) {
            self::fail('a large answer must not look like a network failure, it would be retried');
        } catch (ApiautoException $error) {
            self::assertStringContainsString('64 MiB', $error->getMessage());
        }
        self::assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $before);
    }
}

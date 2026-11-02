<?php

declare(strict_types=1);

namespace Apiauto\Tests;

use Apiauto\Client;
use Apiauto\Exception\ApiautoException;
use Apiauto\Exception\AuthenticationException;
use Apiauto\Exception\BadRequestException;
use Apiauto\Exception\ConnectionException;
use Apiauto\Exception\NotFoundException;
use Apiauto\Exception\PermissionDeniedException;
use Apiauto\Exception\QuotaExceededException;
use Apiauto\Exception\RateLimitException;
use Apiauto\Exception\ServerException;
use Apiauto\Exception\ServiceUnavailableException;
use Apiauto\Http\HttpResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const KEY = 'test-key-1234567890';

    private string|false $savedKey = false;

    protected function setUp(): void
    {
        $this->savedKey = getenv('APIAUTO_KEY');
        putenv('APIAUTO_KEY');
    }

    protected function tearDown(): void
    {
        putenv($this->savedKey === false ? 'APIAUTO_KEY' : 'APIAUTO_KEY=' . $this->savedKey);
    }

    /** @param list<HttpResponse|ConnectionException> $answers */
    private static function client(array $answers, int $maxRetries = 2): RecordingClient
    {
        return new RecordingClient(self::KEY, maxRetries: $maxRetries, transport: new FakeTransport($answers));
    }

    private static function fake(Client $client): FakeTransport
    {
        $property = new \ReflectionProperty(Client::class, 'transport');
        $transport = $property->getValue($client);
        self::assertInstanceOf(FakeTransport::class, $transport);

        return $transport;
    }

    /** @return array<mixed> */
    private static function fixture(string $name): array
    {
        $decoded = json_decode((string) file_get_contents(__DIR__ . '/fixtures/' . $name), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private static function lastUrl(Client $client): string
    {
        $requests = self::fake($client)->requests;

        return $requests[count($requests) - 1]['url'];
    }

    public function testReadsTheKeyFromTheEnvironment(): void
    {
        putenv('APIAUTO_KEY= env-key ');
        $transport = new FakeTransport([FakeTransport::json(['plan' => 'max'])]);
        (new Client(transport: $transport))->usage();
        self::assertSame('env-key', $transport->requests[0]['headers']['X-API-Key']);
    }

    public function testFailsWithoutAKey(): void
    {
        $this->expectException(ApiautoException::class);
        $this->expectExceptionMessage('https://t.me/apiautospace_bot?start=demo');
        new Client();
    }

    public function testRefusesPlainHttpExceptLocalhost(): void
    {
        new Client(self::KEY, baseUrl: 'http://127.0.0.1:8080/v1');
        new Client(self::KEY, baseUrl: 'http://localhost/v1');
        $this->expectException(\InvalidArgumentException::class);
        new Client(self::KEY, baseUrl: 'http://api.apiauto.space/v1');
    }

    public function testNeverShowsTheKey(): void
    {
        $client = self::client([]);
        $wrapped = new class ($client) {
            public function __construct(public Client $inner)
            {
            }
        };
        ob_start();
        var_dump($client, (array) $client, $wrapped);
        foreach ([
            (string) ob_get_clean(),
            print_r($client, true),
            print_r((array) $client, true),
            print_r(get_object_vars($client), true),
            print_r($wrapped, true),
            var_export($client, true),
            (string) json_encode($client),
        ] as $dump) {
            self::assertStringNotContainsString(self::KEY, $dump);
        }
    }

    public function testACloneKeepsTheKeyAndHidesIt(): void
    {
        $transport = new FakeTransport([FakeTransport::json(['plan' => 'max'])]);
        $copy = clone new Client(self::KEY, transport: $transport);
        self::assertSame('max', $copy->usage()->plan);
        self::assertSame(self::KEY, $transport->requests[0]['headers']['X-API-Key']);

        // A transport of its own: the one above recorded the headers it was given.
        $silent = clone new Client(self::KEY, transport: new FakeTransport());
        self::assertStringNotContainsString(self::KEY, print_r((array) $silent, true));
    }

    public function testAClientCannotBeSerialized(): void
    {
        $this->expectException(\LogicException::class);
        serialize(self::client([]));
    }

    /** @return iterable<string, array{string}> */
    public static function badKeys(): iterable
    {
        yield 'space inside' => ['abc def'];
        yield 'header injection' => ["abc\r\nX-Evil: 1"];
        yield 'not ascii' => ['ключ'];
        yield 'del' => ["abc\x7f"];
    }

    #[DataProvider('badKeys')]
    public function testRejectsAKeyAHeaderCannotCarry(string $key): void
    {
        try {
            new Client($key);
            self::fail('expected an exception');
        } catch (\InvalidArgumentException $error) {
            self::assertStringNotContainsString($key, $error->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function badBaseUrls(): iterable
    {
        yield 'user' => ['https://user:secret@api.apiauto.space/v1'];
        yield 'query' => ['https://api.apiauto.space/v1?a=1'];
        yield 'empty query' => ['https://api.apiauto.space/v1?'];
        yield 'fragment' => ['https://api.apiauto.space/v1#a'];
        yield 'no host' => ['/v1'];
    }

    #[DataProvider('badBaseUrls')]
    public function testRejectsABadBaseUrl(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client(self::KEY, baseUrl: $url);
    }

    public function testATransportErrorIsReplacedWithoutItsTrace(): void
    {
        $transport = new class implements \Apiauto\Http\TransportInterface {
            public function get(string $url, array $headers, float $timeout): HttpResponse
            {
                throw new \RuntimeException('custom failure');
            }
        };
        $client = new Client(self::KEY, transport: $transport, maxRetries: 0);
        try {
            $client->usage();
            self::fail('expected an exception');
        } catch (ApiautoException $error) {
            self::assertSame('custom failure', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    public function testErrorsDoNotCarryTheKey(): void
    {
        $client = self::client([new HttpResponse(401, [], '{"error":"unauthorized"}')]);
        try {
            $client->usage();
            self::fail('expected an exception');
        } catch (AuthenticationException $error) {
            self::assertStringNotContainsString(self::KEY, $error->getMessage());
            self::assertStringNotContainsString(self::KEY, $error->getTraceAsString());
            self::assertStringNotContainsString(self::KEY, print_r($error->body, true));
        }
    }

    public function testSendsHeadersAndBuildsTheQuery(): void
    {
        $client = self::client([FakeTransport::json(['items' => [], 'next_cursor' => null, 'count' => 0])]);
        $client->cars('kr', [
            'brand' => 'Mercedes-Benz',
            'model' => '3 Series',
            'year_min' => 2020,
            'price_usd_max' => 19999.5,
            'has_photos' => true,
            'is_active' => false,
            'trim' => null,
        ]);
        $request = self::fake($client)->requests[0];
        self::assertSame(
            'https://api.apiauto.space/v1/cars?market=kr&brand=Mercedes-Benz&model=3%20Series&year_min=2020'
            . '&price_usd_max=19999.5&has_photos=true&is_active=false',
            $request['url'],
        );
        self::assertSame(self::KEY, $request['headers']['X-API-Key']);
        self::assertSame('application/json', $request['headers']['Accept']);
        self::assertStringStartsWith('apiauto-php/' . Client::VERSION, $request['headers']['User-Agent']);
        self::assertStringNotContainsString(self::KEY, $request['url']);
    }

    public function testRejectsAnUnknownFilterAndMarket(): void
    {
        $client = self::client([]);
        try {
            $client->cars('kr', ['source' => 'copart']);
            self::fail('expected an exception');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('source', $error->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $client->cars('de');
    }

    public function testCarIdIsEncodedExceptTheColon(): void
    {
        $client = self::client([FakeTransport::json(self::fixture('che168-car.json'))]);
        $car = $client->car('che168:60385167');
        self::assertSame('https://api.apiauto.space/v1/cars/che168:60385167', self::lastUrl($client));
        self::assertSame('che168:60385167', $car->id);

        $client = self::client([FakeTransport::json(self::fixture('che168-car.json'))]);
        $client->car('a/b?c');
        self::assertStringEndsWith('/cars/a%2Fb%3Fc', self::lastUrl($client));

        foreach ([' ', '.', '..'] as $bad) {
            try {
                $client->car($bad);
                self::fail("accepted id '$bad'");
            } catch (\InvalidArgumentException) {
                self::assertCount(1, self::fake($client)->requests);
            }
        }
    }

    public function testDecodesARealCheCar(): void
    {
        $client = self::client([FakeTransport::json(self::fixture('che168-car.json'))]);
        $car = $client->car('che168:60385167');
        self::assertSame('cn', $car->market);
        self::assertSame('che168', $car->source);
        self::assertSame('60385167', $car->sourceId);
        self::assertSame('BYD', $car->brand);
        self::assertNull($car->vin);
        self::assertSame('79000.00', $car->price->amount);
        self::assertSame('CNY', $car->price->currency);
        self::assertSame('CN', $car->location->country);
        self::assertSame(2025, $car->year);
        self::assertTrue($car->isActive);
        self::assertSame('replica', $car->sourceMode);
        self::assertSame('fwd', $car->raw['drive']);
        self::assertStringContainsString('/img/che168:60385167/1.jpg', $car->photos[0]);
    }

    public function testDecodesARealAuctionLot(): void
    {
        $client = self::client([FakeTransport::json(self::fixture('copart-lot.json'))]);
        $car = $client->car('copart:70370576');
        self::assertSame('us', $car->market);
        self::assertSame('copart', $car->source);
        self::assertNotNull($car->vin);
        self::assertNull($car->listingUrl);
        self::assertArrayHasKey('sale_date', $car->raw);
    }

    public function testIteratesPagesAndKeepsTheLastPageSmall(): void
    {
        $client = self::client([
            FakeTransport::json(['items' => [['id' => 'a:1'], ['id' => 'a:2']], 'next_cursor' => 'next+1', 'count' => 9]),
            FakeTransport::json(['items' => [['id' => 'a:3']], 'next_cursor' => 'next2', 'count' => 9]),
        ]);
        $ids = [];
        foreach ($client->iterCars('kr', ['limit' => 2], maxItems: 3) as $car) {
            $ids[] = $car->id;
        }
        self::assertSame(['a:1', 'a:2', 'a:3'], $ids);
        $requests = self::fake($client)->requests;
        self::assertCount(2, $requests);
        self::assertSame('https://api.apiauto.space/v1/cars?market=kr&limit=2', $requests[0]['url']);
        self::assertSame('https://api.apiauto.space/v1/cars?market=kr&limit=1&cursor=next%2B1', $requests[1]['url']);
    }

    public function testIterationEndsOnTheLastPageOrAnEmptyOne(): void
    {
        $client = self::client([FakeTransport::json(['items' => [['id' => 'a:1']], 'next_cursor' => null, 'count' => 1])]);
        self::assertCount(1, iterator_to_array($client->iterCars('us'), false));

        $client = self::client([FakeTransport::json(['items' => [], 'next_cursor' => 'x', 'count' => 0])]);
        self::assertCount(0, iterator_to_array($client->iterCars('us'), false));

        $client = self::client([]);
        self::assertCount(0, iterator_to_array($client->iterCars('us', maxItems: 0), false));
        self::assertCount(0, self::fake($client)->requests);
    }

    public function testARepeatedCursorStopsTheIteration(): void
    {
        $page = ['items' => [['id' => 'a:1']], 'next_cursor' => 'SAME'];
        $client = self::client([FakeTransport::json($page), FakeTransport::json($page)]);
        $seen = 0;
        try {
            foreach ($client->iterCars('kr') as $car) {
                $seen++;
            }
            self::fail('expected an exception');
        } catch (ApiautoException $error) {
            self::assertStringContainsString('cursor', $error->getMessage());
        }
        self::assertSame(2, $seen);
        self::assertCount(2, self::fake($client)->requests);
    }

    public function testACursorGivenAtTheStartCountsAsSeen(): void
    {
        $client = self::client([FakeTransport::json(['items' => [['id' => 1, 'car_id' => 'a:1']], 'next_cursor' => 'START'])]);
        $this->expectException(ApiautoException::class);
        foreach ($client->iterChanges('kr', '2026-11-02T00:00:00Z', ['cursor' => 'START']) as $event) {
            self::assertSame(1, $event->id);
        }
    }

    public function testAPageWithoutRecordsEndsTheIteration(): void
    {
        $client = self::client([FakeTransport::json(['items' => ['x', 1, null], 'next_cursor' => 'SAME'])]);
        self::assertSame([], iterator_to_array($client->iterCars('kr', maxItems: 5), false));
        self::assertCount(1, self::fake($client)->requests);
    }

    public function testMaxItemsHoldsWithSkippedItems(): void
    {
        $client = self::client([FakeTransport::json([
            'items' => ['x', ['id' => 'a:1'], 'y', ['id' => 'a:2'], ['id' => 'a:3']],
            'next_cursor' => 'more',
        ])]);
        self::assertCount(2, iterator_to_array($client->iterCars('kr', maxItems: 2), false));
    }

    public function testIteratorChecksArgumentsAtOnce(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::client([])->iterCars('kr', ['nope' => 1]);
    }

    public function testChangesTakeADateAndARepeatedType(): void
    {
        $client = self::client([FakeTransport::json(self::fixture('changes.json'))]);
        $since = new \DateTimeImmutable('2026-11-02T12:00:00+03:00');
        $page = $client->changes('cn', $since, ['type' => ['price_drop', 'price_up'], 'min_pct' => 5, 'limit' => 5]);
        self::assertSame(
            'https://api.apiauto.space/v1/changes?market=cn&since=2026-11-02T09%3A00%3A00.000000Z'
            . '&type=price_drop&type=price_up&min_pct=5&limit=5',
            self::lastUrl($client),
        );
        self::assertSame('price_drop', $page->items[0]->type);
        self::assertSame('che168:60161758', $page->items[0]->carId);
        self::assertSame('26000.00', $page->items[0]->oldPrice);
        self::assertSame('23000.00', $page->items[0]->newPrice);
        self::assertSame('11.538', $page->items[0]->pct);
    }

    public function testIterChangesFollowsTheCursor(): void
    {
        $client = self::client([
            FakeTransport::json(['items' => [['id' => 1, 'car_id' => 'iaai:1']], 'next_cursor' => 'c2']),
            FakeTransport::json(['items' => [['id' => 2, 'car_id' => 'iaai:2']], 'next_cursor' => null]),
        ]);
        $ids = [];
        foreach ($client->iterChanges('us', '2026-11-02T00:00:00Z') as $event) {
            $ids[] = $event->id;
        }
        self::assertSame([1, 2], $ids);
        self::assertStringContainsString('since=2026-11-02T00%3A00%3A00Z', self::fake($client)->requests[0]['url']);
        self::assertStringContainsString('cursor=c2', self::fake($client)->requests[1]['url']);
    }

    public function testReferencesAndUsage(): void
    {
        $client = self::client([
            FakeTransport::json([['name' => 'BYD', 'count' => 7015]]),
            FakeTransport::json([['name' => 'Song Pro', 'count' => 12]]),
            FakeTransport::json(['fuel' => ['gasoline' => ['petrol']]]),
            FakeTransport::json(['items' => [[
                'market' => 'cn', 'source' => 'che168', 'available_in_plan' => true, 'status' => 'ok',
                'data_updated_at' => null, 'data_age_seconds' => 12, 'stale_after_seconds' => 7200,
                'stale' => false, 'active_cars' => 5,
            ]]]),
            FakeTransport::json([
                'plan' => 'max', 'status' => 'active', 'quota_unit' => 'records', 'quota_limit' => 100,
                'quota_used' => 1, 'by_market' => [['market' => 'cn', 'records' => 1, 'requests' => 1, 'live_us_requests' => 0]],
            ]),
        ]);
        self::assertSame(7015, $client->brands('cn')[0]->count);
        self::assertSame('Song Pro', $client->models('cn', 'BYD')[0]->name);
        self::assertSame('https://api.apiauto.space/v1/references/models?market=cn&brand=BYD', self::lastUrl($client));
        self::assertSame(['petrol'], $client->dictionaries()['fuel']['gasoline']);
        self::assertSame(12, $client->markets()[0]->dataAgeSeconds);
        $usage = $client->usage();
        self::assertSame('max', $usage->plan);
        self::assertSame('cn', $usage->byMarket[0]->market);
    }

    public function testUsLiveOnlyAsksWhenCalled(): void
    {
        $client = self::client([FakeTransport::json([['id' => 'copart:1', 'source_mode' => 'live']])]);
        $lots = $client->usLive(['make' => 'Toyota', 'page' => 2]);
        self::assertSame('https://api.apiauto.space/v1/us/live?make=Toyota&page=2', self::lastUrl($client));
        self::assertSame('live', $lots[0]->sourceMode);

        $this->expectException(\InvalidArgumentException::class);
        $client->usLive(['brand' => 'Toyota']);
    }

    /** @return iterable<string, array{int, class-string<ApiautoException>}> */
    public static function statuses(): iterable
    {
        yield '400' => [400, BadRequestException::class];
        yield '401' => [401, AuthenticationException::class];
        yield '402' => [402, QuotaExceededException::class];
        yield '403' => [403, PermissionDeniedException::class];
        yield '404' => [404, NotFoundException::class];
    }

    /** @param class-string<ApiautoException> $class */
    #[DataProvider('statuses')]
    public function testClientErrorsAreTypedAndNotRetried(int $status, string $class): void
    {
        $client = self::client([FakeTransport::json(
            ['error' => 'some_code', 'detail' => 'why', 'reset_at' => '2026-12-01T00:00:00Z'],
            $status,
        )]);
        try {
            $client->usage();
            self::fail('expected an exception');
        } catch (ApiautoException $error) {
            self::assertInstanceOf($class, $error);
            self::assertSame($status, $error->statusCode);
            self::assertSame('some_code', $error->errorCode);
            self::assertSame('why', $error->detail);
            self::assertSame('2026-12-01T00:00:00Z', $error->resetAt);
            self::assertSame("HTTP $status some_code: why", $error->getMessage());
        }
        self::assertCount(1, self::fake($client)->requests);
        self::assertSame([], $client->sleeps);
    }

    public function testRateLimitWaitsRetryAfterFromTheBody(): void
    {
        $client = self::client([
            FakeTransport::json(['error' => 'rate_limited', 'retry_after' => 3], 429),
            FakeTransport::json(['plan' => 'max']),
        ]);
        self::assertSame('max', $client->usage()->plan);
        self::assertSame([3.0], $client->sleeps);
    }

    public function testServiceUnavailableWaitsTheHeaderAndIsCapped(): void
    {
        $client = self::client([
            new HttpResponse(503, ['Retry-After' => '3600'], '{"error":"upstream_quota_exhausted"}'),
            FakeTransport::json(['plan' => 'max']),
        ]);
        $client->usage();
        self::assertSame([60.0], $client->sleeps);
    }

    /** @return iterable<string, array{mixed, string|null, int|null}> */
    public static function retryAfterValues(): iterable
    {
        yield 'json negative' => [-5, null, 0];
        yield 'json huge' => [PHP_INT_MAX, null, 60];
        yield 'json float' => [1.5, null, 2];
        yield 'json over the cap' => [61, null, 60];
        yield 'json wrong type' => [true, null, null];
        yield 'header negative' => [null, '-5', 0];
        yield 'header huge' => [null, '99999999999999999999', 60];
        yield 'header in the past' => [null, 'Wed, 21 Oct 2015 07:28:00 GMT', 0];
        yield 'header far ahead' => [null, 'Fri, 01 Jan 2100 00:00:00 GMT', 60];
        yield 'header rfc 850' => [null, 'Sunday, 06-Nov-94 08:49:37 GMT', 0];
        yield 'header asctime' => [null, 'Sun Nov  6 08:49:37 1994', 0];
        yield 'header a date strtotime would take' => [null, 'tomorrow', null];
        yield 'header relative' => [null, '+1 week', null];
        yield 'header wrong weekday' => [null, 'Mon, 21 Oct 2015 07:28:00 GMT', null];
        yield 'header garbage' => [null, 'soon', null];
    }

    #[DataProvider('retryAfterValues')]
    public function testRetryAfterIsReadStrictlyAndCapped(mixed $body, ?string $header, ?int $expected): void
    {
        $fields = $body === null ? ['error' => 'rate_limited'] : ['error' => 'rate_limited', 'retry_after' => $body];
        $headers = $header === null ? [] : ['Retry-After' => $header];
        $client = self::client([FakeTransport::json($fields, 429, $headers)], maxRetries: 0);
        try {
            $client->usage();
            self::fail('expected an exception');
        } catch (RateLimitException $error) {
            self::assertSame($expected, $error->retryAfter);
        }
    }

    public function testErrorTextIsCleanedAndShortened(): void
    {
        $client = self::client([FakeTransport::json(
            ['error' => "bad\ncode", 'detail' => "a\x1b[31m\r\nb" . str_repeat('я', 900)],
            400,
        )]);
        try {
            $client->usage();
            self::fail('expected an exception');
        } catch (BadRequestException $error) {
            self::assertSame('bad code', $error->errorCode);
            self::assertNotNull($error->detail);
            self::assertDoesNotMatchRegularExpression('/[\x00-\x1f\x7f]/', $error->detail);
            self::assertDoesNotMatchRegularExpression('/[\x00-\x1f\x7f]/', $error->getMessage());
            self::assertSame(500, mb_strlen($error->detail));
        }
    }

    public function testServerErrorsBackOffAndGiveUp(): void
    {
        $client = self::client([
            new HttpResponse(500, [], 'oops'),
            new HttpResponse(502, [], '<html>bad gateway</html>'),
            new HttpResponse(500, [], 'oops'),
        ]);
        try {
            $client->usage();
            self::fail('expected an exception');
        } catch (ServerException $error) {
            self::assertSame(500, $error->statusCode);
            self::assertSame('oops', $error->body);
            self::assertSame('HTTP 500: oops', $error->getMessage());
        }
        self::assertCount(3, self::fake($client)->requests);
        self::assertCount(2, $client->sleeps);
        self::assertGreaterThanOrEqual(0.25, $client->sleeps[0]);
        self::assertLessThanOrEqual(0.5, $client->sleeps[0]);
        self::assertGreaterThanOrEqual(0.5, $client->sleeps[1]);
        self::assertLessThanOrEqual(1.0, $client->sleeps[1]);
    }

    public function testConnectionErrorsAreRetried(): void
    {
        $client = self::client([new ConnectionException('down'), FakeTransport::json(['plan' => 'max'])]);
        self::assertSame('max', $client->usage()->plan);
        self::assertCount(1, $client->sleeps);

        $client = self::client([new ConnectionException('down')], maxRetries: 0);
        $this->expectException(ConnectionException::class);
        $client->usage();
    }

    public function testRateLimitAndUnavailableKeepTheirClasses(): void
    {
        $client = self::client([FakeTransport::json(['error' => 'rate_limited', 'retry_after' => 1], 429)], maxRetries: 0);
        try {
            $client->usage();
            self::fail('expected an exception');
        } catch (RateLimitException $error) {
            self::assertSame(1, $error->retryAfter);
        }

        $client = self::client([FakeTransport::json(['error' => 'upstream_unavailable'], 503)], maxRetries: 0);
        $this->expectException(ServiceUnavailableException::class);
        $client->usage();
    }

    public function testASuccessThatIsNotJsonIsAnError(): void
    {
        $client = self::client([new HttpResponse(200, [], '<html>')]);
        try {
            $client->usage();
            self::fail('expected an exception');
        } catch (ApiautoException $error) {
            self::assertSame(200, $error->statusCode);
            self::assertSame('<html>', $error->body);
        }
        self::assertCount(1, self::fake($client)->requests);
    }

    public function testLastResponseReadsTheHeaders(): void
    {
        $client = self::client([FakeTransport::json(
            ['items' => [], 'next_cursor' => null, 'count' => 0],
            200,
            [
                'x-quota-limit' => '100', 'X-Quota-Remaining' => '97', 'X-Quota-Reset' => '2026-10-28T12:04:00Z',
                'X-RateLimit-Limit' => '1', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '1',
                'X-Data-Age-Seconds' => '938',
            ],
        )]);
        self::assertNull($client->lastResponse());
        $client->cars('cn');
        $info = $client->lastResponse();
        self::assertNotNull($info);
        self::assertSame(200, $info->status);
        self::assertSame(100, $info->quotaLimit);
        self::assertSame(97, $info->quotaRemaining);
        self::assertSame('2026-10-28T12:04:00Z', $info->quotaReset);
        self::assertSame(1, $info->rateLimit);
        self::assertSame(0, $info->rateRemaining);
        self::assertSame(1, $info->rateReset);
        self::assertSame(938, $info->dataAgeSeconds);
    }
}

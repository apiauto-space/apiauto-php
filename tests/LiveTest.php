<?php

declare(strict_types=1);

namespace Apiauto\Tests;

use Apiauto\Client;
use Apiauto\Exception\NotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test against the real API, skipped unless APIAUTO_KEY is set.
 *
 * It spends at most 10 records of the quota (5 data requests on a demo key): references and usage
 * are free, the rest asks for 1-2 cars or events at a time. It never calls usLive(), which spends
 * the paid counter of the data provider.
 *
 *     APIAUTO_KEY=... vendor/bin/phpunit tests/LiveTest.php
 */
final class LiveTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        if (trim((string) getenv('APIAUTO_KEY')) === '') {
            self::markTestSkipped('set APIAUTO_KEY to run the live smoke test');
        }
        $this->client = new Client();
    }

    public function testFreeEndpoints(): void
    {
        self::assertContains($this->client->usage()->quotaUnit, ['records', 'requests']);
        $markets = array_map(static fn ($market) => $market->market, $this->client->markets());
        sort($markets);
        self::assertSame(['cn', 'kr', 'us'], $markets);
        $brands = $this->client->brands('cn');
        self::assertNotSame([], $brands);
        self::assertNotSame([], $this->client->models('cn', $brands[0]->name));
        self::assertArrayHasKey('fuel', $this->client->dictionaries());
    }

    public function testCarsAndCarById(): void
    {
        $page = $this->client->cars('cn', ['limit' => 2]);
        self::assertGreaterThanOrEqual(1, count($page->items));
        self::assertLessThanOrEqual(2, count($page->items));
        $first = $page->items[0];
        self::assertStringStartsWith('che168:', $first->id);
        self::assertSame(200, $this->client->lastResponse()?->status);
        self::assertNotNull($this->client->lastResponse()?->dataAgeSeconds);
        self::assertSame($first->id, $this->client->car($first->id)->id);
    }

    public function testIterationAcrossAPage(): void
    {
        $sources = [];
        foreach ($this->client->iterCars('us', ['limit' => 2], maxItems: 3) as $car) {
            $sources[] = $car->source;
        }
        self::assertCount(3, $sources);
        self::assertSame([], array_diff($sources, ['copart', 'iaai']));
    }

    public function testChangeFeed(): void
    {
        $since = new \DateTimeImmutable('-1 day');
        $page = $this->client->changes('cn', $since, ['limit' => 1]);
        self::assertLessThanOrEqual(1, count($page->items));
    }

    public function testNotFoundIsTypedAndFree(): void
    {
        $this->expectException(NotFoundException::class);
        $this->client->car('encar:1');
    }
}

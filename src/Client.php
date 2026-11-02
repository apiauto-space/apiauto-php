<?php

declare(strict_types=1);

namespace Apiauto;

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
use Apiauto\Http\CurlTransport;
use Apiauto\Http\HttpResponse;
use Apiauto\Http\TransportInterface;
use Apiauto\Model\Car;
use Apiauto\Model\CarsPage;
use Apiauto\Model\Change;
use Apiauto\Model\ChangesPage;
use Apiauto\Model\MarketStatus;
use Apiauto\Model\ReferenceItem;
use Apiauto\Model\Usage;

/**
 * Client of the apiauto.space API: used cars of Encar (Korea), Che168 (China), Copart and IAAI (USA)
 * in one JSON format.
 *
 * Call it from your server: the API does not accept browser requests.
 *
 *     $client = new \Apiauto\Client();  // reads APIAUTO_KEY
 *     $page = $client->cars('kr', ['brand' => 'Kia', 'limit' => 20]);
 *
 * @see https://apiauto.space/en/docs/
 */
class Client
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://api.apiauto.space/v1';
    public const API_KEY_ENV = 'APIAUTO_KEY';

    private const DEMO_KEY_URL = 'https://t.me/apiautospace_bot?start=demo';
    private const MARKETS = ['kr', 'cn', 'us'];
    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_RETRY_AFTER = 60;
    private const MAX_BACKOFF = 8.0;
    private const MAX_ERROR_TEXT = 500;
    private const CAR_FILTERS = [
        'brand', 'model', 'generation', 'trim', 'year_min', 'year_max', 'price_min', 'price_max',
        'price_usd_min', 'price_usd_max', 'mileage_max', 'fuel', 'transmission', 'body_type',
        'has_photos', 'is_active', 'q', 'sort', 'limit', 'cursor',
    ];
    private const CHANGE_OPTIONS = ['type', 'min_pct', 'limit', 'cursor'];
    private const LIVE_PARAMS = ['make', 'model', 'year_min', 'year_max', 'price_max', 'page'];
    private const STATUS_EXCEPTIONS = [
        400 => BadRequestException::class,
        401 => AuthenticationException::class,
        402 => QuotaExceededException::class,
        403 => PermissionDeniedException::class,
        404 => NotFoundException::class,
        429 => RateLimitException::class,
        503 => ServiceUnavailableException::class,
    ];

    /**
     * The keys, outside the instances: (array) casts, var_dump() and dump tools read properties.
     * An instance holds only an empty handle, which its clones share.
     *
     * @var \WeakMap<\stdClass, string>|null
     */
    private static ?\WeakMap $keys = null;

    private \stdClass $keyHandle;
    private string $baseUrl;
    private float $timeout;
    private int $maxRetries;
    private TransportInterface $transport;
    private ?ResponseInfo $lastResponse = null;

    /**
     * @param string|null $apiKey Your key; defaults to the APIAUTO_KEY environment variable.
     * @param string $baseUrl The API root: https, or http for localhost; no user, query or fragment.
     * @param float $timeout Seconds one attempt may take.
     * @param int $maxRetries How many times to retry after 429, 5xx or a network error.
     *                        429 and 503 wait Retry-After (at most 60 s), the rest back off
     *                        exponentially. Other 4xx errors are never retried.
     * @param TransportInterface|null $transport Your own HTTP layer (a proxy, a test double).
     */
    public function __construct(
        #[\SensitiveParameter]
        ?string $apiKey = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $timeout = 30.0,
        int $maxRetries = 2,
        ?TransportInterface $transport = null,
    ) {
        $fromEnv = getenv(self::API_KEY_ENV);
        $key = trim($apiKey ?? (is_string($fromEnv) ? $fromEnv : ''));
        if ($key === '') {
            throw new ApiautoException(
                'No API key: pass $apiKey or set the ' . self::API_KEY_ENV . ' environment variable. '
                . 'A free demo key comes from ' . self::DEMO_KEY_URL,
            );
        }
        if (preg_match('/[^\x21-\x7e]/', $key) === 1) {
            throw new \InvalidArgumentException('The API key must be printable ASCII without spaces');
        }
        if ($timeout <= 0) {
            throw new \InvalidArgumentException('timeout must be above 0');
        }
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('maxRetries must be 0 or more');
        }
        self::checkBaseUrl($baseUrl);

        $this->keyHandle = new \stdClass();
        self::$keys ??= new \WeakMap();
        self::$keys[$this->keyHandle] = $key;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
        $this->transport = $transport ?? new CurlTransport();
    }

    /**
     * Quota and rate headers of the last response, successful or not; null before the first one.
     *
     * @phpstan-impure
     */
    public function lastResponse(): ?ResponseInfo
    {
        return $this->lastResponse;
    }

    /**
     * Search one market's catalog: GET /cars. Every car returned counts against the quota.
     *
     * @param string $market kr (Encar), cn (Che168) or us (Copart and IAAI).
     * @param array<string, mixed> $filters brand, model, generation, trim, year_min, year_max,
     *        price_min, price_max, price_usd_min, price_usd_max, mileage_max, fuel, transmission,
     *        body_type, has_photos, is_active, q, sort, limit, cursor.
     */
    public function cars(string $market, array $filters = []): CarsPage
    {
        return CarsPage::fromArray($this->getObject('/cars', self::carsQuery($market, $filters)));
    }

    /**
     * Every car matching the filters, following next_cursor.
     *
     * @param array<string, mixed> $filters The filters of cars(); limit sets the page size.
     * @param int|null $maxItems Stop after this many cars. The last request asks only for what is
     *                           still needed, so no quota is spent on extra records.
     * @return \Generator<int, Car>
     */
    public function iterCars(string $market, array $filters = [], ?int $maxItems = null): \Generator
    {
        $query = self::carsQuery($market, $filters);
        self::checkMaxItems($maxItems);

        return $this->paginate('/cars', $query, $maxItems, Car::fromArray(...));
    }

    /**
     * One car by id, such as encar:43020893: GET /cars/{id}.
     * Cars taken off sale stay available for 14 days with isActive = false.
     */
    public function car(string $id): Car
    {
        if (trim($id) === '' || $id === '.' || $id === '..') {
            throw new \InvalidArgumentException('Car id must look like encar:43020893');
        }

        return Car::fromArray($this->getObject('/cars/' . str_replace('%3A', ':', rawurlencode($id))));
    }

    /**
     * The change feed: GET /changes. Each event counts as one record.
     * It comes with the Pro, Max and demo plans.
     *
     * @param \DateTimeInterface|string $since Events after this moment: a date or an ISO 8601 string with a time zone.
     * @param array<string, mixed> $options type (a string or a list), min_pct, limit, cursor.
     */
    public function changes(string $market, \DateTimeInterface|string $since, array $options = []): ChangesPage
    {
        return ChangesPage::fromArray($this->getObject('/changes', self::changesQuery($market, $since, $options)));
    }

    /**
     * Every event since a moment, following next_cursor.
     *
     * @param array<string, mixed> $options The options of changes(), without cursor if you start from the first page.
     * @return \Generator<int, Change>
     */
    public function iterChanges(
        string $market,
        \DateTimeInterface|string $since,
        array $options = [],
        ?int $maxItems = null,
    ): \Generator {
        $query = self::changesQuery($market, $since, $options);
        self::checkMaxItems($maxItems);

        return $this->paginate('/changes', $query, $maxItems, Change::fromArray(...));
    }

    /**
     * A market's makes with car counts, most frequent first. Free.
     *
     * @return list<ReferenceItem>
     */
    public function brands(string $market): array
    {
        $items = $this->getObject('/references/brands', ['market' => self::checkMarket($market)]);

        return self::mapList($items, ReferenceItem::fromArray(...));
    }

    /**
     * A make's models with car counts, most frequent first. Free.
     *
     * @return list<ReferenceItem>
     */
    public function models(string $market, string $brand): array
    {
        $items = $this->getObject('/references/models', ['market' => self::checkMarket($market), 'brand' => $brand]);

        return self::mapList($items, ReferenceItem::fromArray(...));
    }

    /**
     * Canonical values of fuel, transmission, body_type and more, with synonyms. Free.
     *
     * @return array<mixed> Field name => canonical value => list of synonyms.
     */
    public function dictionaries(): array
    {
        return $this->getObject('/references/dictionaries');
    }

    /**
     * Every market: plan access, status, data age and the number of active cars. Free.
     *
     * @return list<MarketStatus>
     */
    public function markets(): array
    {
        $body = $this->getObject('/references/markets');
        $items = $body['items'] ?? [];

        return is_array($items) ? self::mapList($items, MarketStatus::fromArray(...)) : [];
    }

    /** The plan, its term and how much of the quota is spent. Free. */
    public function usage(): Usage
    {
        return Usage::fromArray($this->getObject('/usage'));
    }

    /**
     * Search Copart and IAAI lots live at the auction data provider: GET /us/live.
     *
     * For cars that are not in the catalog copy yet. Pro and Max plans; it spends a separate
     * monthly counter, not the car quota.
     *
     * @param array<string, mixed> $params make, model, year_min, year_max, price_max (US dollars), page (1-100).
     * @return list<Car>
     */
    public function usLive(array $params = []): array
    {
        self::rejectUnknown($params, self::LIVE_PARAMS, 'live search parameter');

        return self::mapList($this->getObject('/us/live', $params), Car::fromArray(...));
    }

    /** @return array<string, mixed> What var_dump() and print_r() show: no key. */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'apiKey' => '***', 'timeout' => $this->timeout, 'maxRetries' => $this->maxRetries];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('A client holds an API key and cannot be serialized');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A client holds an API key and cannot be unserialized');
    }

    /** Waits before a retry. Override it to change how the client waits. */
    protected function sleep(float $seconds): void
    {
        usleep((int) round($seconds * 1_000_000));
    }

    /**
     * @param array<string, mixed> $query
     * @param callable(array<mixed>): T $decode
     * @return \Generator<int, T>
     * @template T
     */
    private function paginate(string $path, array $query, ?int $maxItems, callable $decode): \Generator
    {
        $remaining = $maxItems;
        $seen = is_string($query['cursor'] ?? null) ? [$query['cursor'] => true] : [];
        while ($remaining === null || $remaining > 0) {
            if ($remaining !== null) {
                $size = (int) ($query['limit'] ?? self::DEFAULT_PAGE_SIZE);
                $query['limit'] = (string) min($size, $remaining);
            }
            $page = $this->getObject($path, $query);
            $items = $page['items'] ?? [];
            $decoded = 0;
            foreach (is_array($items) ? $items : [] as $item) {
                if (is_array($item)) {
                    $decoded++;
                    yield $decode($item);
                    if ($remaining !== null && --$remaining === 0) {
                        return;
                    }
                }
            }
            $cursor = $page['next_cursor'] ?? null;
            if ($decoded === 0 || !is_string($cursor) || $cursor === '') {
                return;
            }
            if (isset($seen[$cursor])) {
                throw new ApiautoException('The API repeated a cursor: stopped to avoid an endless loop');
            }
            $seen[$cursor] = true;
            $query['cursor'] = $cursor;
        }
    }

    /**
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    private function getObject(string $path, array $query = []): array
    {
        $url = $this->baseUrl . $path . self::queryString($query);
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->attempt($url);
            } catch (ApiautoException $error) {
                $delay = $this->retryDelay($error, $attempt);
                if ($delay === null) {
                    throw $error;
                }
                $this->sleep($delay);
            }
        }
    }

    /** @return array<mixed> */
    private function attempt(string $url): array
    {
        $headers = [
            'X-API-Key' => self::$keys[$this->keyHandle] ?? '',
            'Accept' => 'application/json',
            'User-Agent' => 'apiauto-php/' . self::VERSION . ' (+https://github.com/apiauto-space/apiauto-php)',
        ];
        try {
            $response = $this->transport->get($url, $headers, $this->timeout);
        } catch (\Exception $error) {
            // A new exception without $previous: the transport's stack trace holds the headers.
            throw $error instanceof ConnectionException
                ? new ConnectionException($error->getMessage())
                : new ApiautoException($error->getMessage());
        }
        $this->lastResponse = ResponseInfo::fromResponse($response);
        if ($response->status < 200 || $response->status >= 300) {
            throw self::errorFromResponse($response);
        }
        $body = json_decode($response->body, true);
        if (!is_array($body)) {
            throw new ApiautoException(
                'The API answered with a body that is not JSON',
                statusCode: $response->status,
                body: $response->body,
            );
        }

        return $body;
    }

    private function retryDelay(ApiautoException $error, int $attempt): ?float
    {
        if ($attempt >= $this->maxRetries) {
            return null;
        }
        if (
            ($error instanceof RateLimitException || $error instanceof ServiceUnavailableException)
            && $error->retryAfter !== null
        ) {
            return (float) $error->retryAfter;
        }
        if ($error instanceof RateLimitException || $error instanceof ServerException || $error instanceof ConnectionException) {
            return min(self::MAX_BACKOFF, 0.5 * 2 ** $attempt) * (0.5 + mt_rand() / mt_getrandmax() / 2);
        }

        return null;
    }

    private static function errorFromResponse(HttpResponse $response): ApiautoException
    {
        $decoded = json_decode($response->body, true);
        $fields = is_array($decoded) ? $decoded : [];
        $code = is_string($fields['error'] ?? null) ? self::cleanText($fields['error']) : null;
        $detail = is_string($fields['detail'] ?? null) ? self::cleanText($fields['detail']) : null;
        $resetAt = is_string($fields['reset_at'] ?? null) ? self::cleanText($fields['reset_at']) : null;
        $retryAfter = self::retryAfter($fields['retry_after'] ?? null) ?? self::retryAfter($response->header('Retry-After'));

        $message = 'HTTP ' . $response->status;
        if ($code !== null) {
            $message .= ' ' . $code;
        }
        if ($detail !== null) {
            $message .= ': ' . $detail;
        } elseif ($fields === [] && trim($response->body) !== '') {
            $message .= ': ' . self::cleanText($response->body);
        }

        $class = self::STATUS_EXCEPTIONS[$response->status]
            ?? ($response->status >= 500 ? ServerException::class : ApiautoException::class);

        return new $class(
            $message,
            statusCode: $response->status,
            errorCode: $code,
            detail: $detail,
            body: is_array($decoded) ? $decoded : $response->body,
            retryAfter: $retryAfter,
            resetAt: $resetAt,
        );
    }

    /** Control characters out, at most 500 characters: API text ends up in logs and terminals. */
    private static function cleanText(string $text): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text));
        if (preg_match('/^.{0,' . self::MAX_ERROR_TEXT . '}/su', $clean, $match) === 1) {
            return $match[0];
        }

        return substr($clean, 0, self::MAX_ERROR_TEXT);  // not valid UTF-8
    }

    /** Retry-After from a JSON field or a header, as seconds from 0 to 60; null when unreadable. */
    private static function retryAfter(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return (int) ceil(max(0.0, min((float) $value, (float) self::MAX_RETRY_AFTER)));
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^-?[0-9]+$/', $value) === 1) {
            return self::retryAfter((float) $value);
        }
        $moment = self::httpDate($value);

        return $moment === null ? null : self::retryAfter($moment - time());
    }

    /** The three date formats of RFC 7231, section 7.1.1.1; anything else is null. */
    private static function httpDate(string $value): ?int
    {
        $asctime = (string) preg_replace('/^(\w{3} \w{3})  (\d) /', '$1 $2 ', $value);
        $formats = [
            ['D, d M Y H:i:s \G\M\T', $value],
            ['l, d-M-y H:i:s \G\M\T', $value],
            ['D M j H:i:s Y', $asctime],
        ];
        foreach ($formats as [$format, $text]) {
            $moment = \DateTimeImmutable::createFromFormat('!' . $format, $text, new \DateTimeZone('UTC'));
            if ($moment !== false && $moment->format($format) === $text) {
                return $moment->getTimestamp();
            }
        }

        return null;
    }

    private static function checkBaseUrl(string $baseUrl): void
    {
        $parts = parse_url($baseUrl);
        if (
            !is_array($parts) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || strpbrk($baseUrl, '?#') !== false
        ) {
            throw new \InvalidArgumentException('baseUrl must be a plain URL without a user, query or fragment');
        }
        $scheme = $parts['scheme'] ?? '';
        $local = in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $local)) {
            throw new \InvalidArgumentException('baseUrl must use https: the key travels in a header');
        }
    }

    private static function checkMarket(string $market): string
    {
        if (!in_array($market, self::MARKETS, true)) {
            throw new \InvalidArgumentException(
                'market must be one of ' . implode(', ', self::MARKETS) . ", got '$market'",
            );
        }

        return $market;
    }

    private static function checkMaxItems(?int $maxItems): void
    {
        if ($maxItems !== null && $maxItems < 0) {
            throw new \InvalidArgumentException('maxItems must be 0 or more');
        }
    }

    /**
     * @param array<string, mixed> $given
     * @param list<string> $known
     */
    private static function rejectUnknown(array $given, array $known, string $what): void
    {
        $unknown = array_diff(array_keys($given), $known);
        if ($unknown !== []) {
            throw new \InvalidArgumentException("Unknown $what: " . implode(', ', $unknown));
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private static function carsQuery(string $market, array $filters): array
    {
        self::rejectUnknown($filters, self::CAR_FILTERS, 'car filter');

        return ['market' => self::checkMarket($market)] + $filters;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function changesQuery(string $market, \DateTimeInterface|string $since, array $options): array
    {
        self::rejectUnknown($options, self::CHANGE_OPTIONS, 'change feed option');

        return ['market' => self::checkMarket($market), 'since' => $since] + $options;
    }

    /**
     * Null values are dropped, booleans become true/false, dates become ISO 8601 in UTC,
     * lists become repeated parameters.
     *
     * @param array<string, mixed> $query
     */
    private static function queryString(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if ($item !== null) {
                    $pairs[] = rawurlencode($name) . '=' . rawurlencode(self::queryValue($item));
                }
            }
        }

        return $pairs === [] ? '' : '?' . implode('&', $pairs);
    }

    private static function queryValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z');
        }
        if (is_float($value)) {
            return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        }
        if (is_int($value) || is_string($value)) {
            return (string) $value;
        }
        throw new \InvalidArgumentException('Query values must be scalars, lists of them or dates');
    }

    /**
     * @param array<mixed> $items
     * @param callable(array<mixed>): T $decode
     * @return list<T>
     * @template T
     */
    private static function mapList(array $items, callable $decode): array
    {
        $result = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $result[] = $decode($item);
            }
        }

        return $result;
    }
}

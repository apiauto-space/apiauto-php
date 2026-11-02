<?php

// Runs outside PHPUnit: PHPUnit's own objects in a stack trace would hide what the client leaks.
// Prints one line per check, "clean" or "LEAK". Argument: the base URL to fail on.

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Apiauto\Client;

const KEY = 'test-key-1234567890';

function report(string $what, string $text): void
{
    echo $what, ': ', str_contains($text, KEY) || str_contains($text, substr(KEY, 0, 12)) ? 'LEAK' : 'clean', "\n";
}

function dumped(mixed $value): string
{
    ob_start();
    var_dump($value);

    return (string) ob_get_clean();
}

$client = new Client(KEY, baseUrl: 'http://127.0.0.1:1', timeout: 2.0, maxRetries: 0);
report('client var_dump', dumped($client));
report('client print_r', print_r($client, true));
report('client var_export', var_export($client, true));
report('client json_encode', (string) json_encode($client));
report('client (array) var_dump', dumped((array) $client));
report('client (array) print_r', print_r((array) $client, true));

try {
    $client->usage();
    echo "no exception\n";
} catch (\Throwable $error) {
    report('connection print_r', print_r($error, true));
    report('connection var_dump', dumped($error));
    report('connection var_export', var_export($error, true));
    report('connection string', (string) $error);
    report('connection trace', print_r($error->getTrace(), true));
    report('connection trace json', (string) json_encode($error->getTrace()));
    echo 'previous: ', $error->getPrevious() === null ? 'none' : 'SET', "\n";
}

try {
    new Client(KEY, baseUrl: 'http://example.com');
} catch (\Throwable $error) {
    report('constructor print_r', print_r($error, true));
    report('constructor trace', $error->getTraceAsString());
}

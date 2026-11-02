<?php

// Router of the local server CurlTransportTest starts. Serves nothing outside loopback.

declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$log = (string) getenv('APIAUTO_TEST_LOG');

if (str_contains($path, '/target')) {
    file_put_contents($log, $_SERVER['HTTP_X_API_KEY'] ?? 'no key');
    header('Content-Type: application/json');
    echo '{"plan":"reached"}';

    return;
}
if (str_contains($path, '/redirect')) {
    header('Location: ' . getenv('APIAUTO_TEST_TARGET'), true, (int) ($_GET['code'] ?? 302));

    return;
}
if (str_contains($path, '/slow')) {
    sleep(3);
    echo '{}';

    return;
}
if (str_contains($path, '/bomb')) {
    header('Content-Encoding: gzip');
    header('Content-Type: application/json');
    readfile((string) getenv('APIAUTO_TEST_BOMB'));

    return;
}
header('Content-Type: application/json');
header('X-Quota-Remaining: 7');
echo '{"plan":"max"}';

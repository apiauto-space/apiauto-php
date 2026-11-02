<?php

// What changed on Che168 in the last 24 hours: new cars, removed cars, price changes.
//
//   export APIAUTO_KEY=...
//   php examples/change-feed.php
//
// The feed comes with the Pro, Max and demo plans; each event counts as one record.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this example from the command line. Listing data is untrusted: escape it before showing it in HTML.\n");
}

require __DIR__ . '/../vendor/autoload.php';

$client = new Apiauto\Client();

$since = new DateTimeImmutable('-1 day');
foreach ($client->iterChanges('cn', $since, ['limit' => 5], maxItems: 5) as $event) {
    printf("%s  %-11s  %s  %s\n", $event->at, $event->type, $event->carId, $event->newPrice ?? '');
}

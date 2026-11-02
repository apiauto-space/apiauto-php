<?php

// Copart and IAAI lots: VIN, damage, title and sale date.
//
//   export APIAUTO_KEY=...
//   php examples/us-lots.php
//
// market=us holds both auctions and the API has no filter by auction: `source` on each record
// tells them apart, so keep the ones you need on your side.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this example from the command line. Listing data is untrusted: escape it before showing it in HTML.\n");
}

require __DIR__ . '/../vendor/autoload.php';

$client = new Apiauto\Client();

foreach ($client->iterCars('us', ['brand' => 'Toyota', 'model' => 'Camry', 'year_min' => 2020, 'limit' => 5], maxItems: 5) as $lot) {
    printf(
        "%-7s %s  %s  VIN %s  damage: %s  sale: %s\n",
        $lot->source,
        $lot->sourceId,
        $lot->title,
        $lot->vin ?? '-',
        $lot->raw['damage_main'] ?? '-',
        $lot->raw['sale_date'] ?? '-',
    );
}

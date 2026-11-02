<?php

// Search Encar (South Korea) by make, model and year.
//
//   composer install
//   export APIAUTO_KEY=...   # the demo key from https://t.me/apiautospace_bot?start=demo
//   php examples/search.php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this example from the command line. Listing data is untrusted: escape it before showing it in HTML.\n");
}

require __DIR__ . '/../vendor/autoload.php';

$client = new Apiauto\Client();

$page = $client->cars('kr', ['brand' => 'Kia', 'model' => 'Sorento', 'year_min' => 2021, 'limit' => 5]);

printf("%s cars match\n", $page->count ?? '10,000+');
foreach ($page->items as $car) {
    printf("%s  %s  %s %s\n", $car->id, $car->title, $car->price->amount ?? '-', $car->price->currency);
}
printf("records left: %s\n", $client->lastResponse()?->quotaRemaining ?? '?');

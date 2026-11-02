<?php

declare(strict_types=1);

namespace Apiauto\Tests;

use Apiauto\Client;

/** Records the waits between retries instead of sleeping. */
final class RecordingClient extends Client
{
    /** @var list<float> */
    public array $sleeps = [];

    protected function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
    }
}

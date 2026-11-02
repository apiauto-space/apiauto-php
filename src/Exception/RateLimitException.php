<?php

declare(strict_types=1);

namespace Apiauto\Exception;

/** 429: more requests per second than the plan allows; see retryAfter. */
class RateLimitException extends ApiautoException
{
}

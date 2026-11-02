<?php

declare(strict_types=1);

namespace Apiauto\Exception;

/** 503: the upstream provider of live US search is unavailable; see retryAfter. */
class ServiceUnavailableException extends ServerException
{
}

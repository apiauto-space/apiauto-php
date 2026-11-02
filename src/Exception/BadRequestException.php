<?php

declare(strict_types=1);

namespace Apiauto\Exception;

/** 400: a bad parameter, a limit above the plan's, or offset given. */
class BadRequestException extends ApiautoException
{
}

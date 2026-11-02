<?php

declare(strict_types=1);

namespace Apiauto\Exception;

/** 401: no key, a wrong or revoked key, or the subscription has ended. */
class AuthenticationException extends ApiautoException
{
}

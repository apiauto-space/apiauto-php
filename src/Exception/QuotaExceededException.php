<?php

declare(strict_types=1);

namespace Apiauto\Exception;

/** 402: the period quota is spent; resetAt says when it renews. */
class QuotaExceededException extends ApiautoException
{
}

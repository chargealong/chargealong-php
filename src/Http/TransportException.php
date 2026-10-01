<?php

declare(strict_types=1);

namespace ChargeAlong\Http;

/**
 * No answer at all: the connection failed, timed out, or sent more than a
 * response to this API could be.
 */
final class TransportException extends \RuntimeException
{
}

<?php

declare(strict_types=1);

namespace ChargeAlong\Exception;

/**
 * The request could not be served as written. The detail names the parameter:
 * "lat: must be between -90 and 90".
 */
class BadRequest extends ChargeAlongException
{
}

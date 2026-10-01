<?php

declare(strict_types=1);

namespace ChargeAlong\Exception;

/**
 * Nothing is filed under that id or slug, and never was.
 *
 * Ordinary rather than a failure. A site that was removed is not this: it still
 * answers, with its status set to `removed`.
 */
final class NotFound extends ChargeAlongException
{
}

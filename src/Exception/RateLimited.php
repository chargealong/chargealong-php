<?php

declare(strict_types=1);

namespace ChargeAlong\Exception;

/**
 * Too many requests from here.
 *
 * The API is free and keyless and asks callers to be gentle: answers kept
 * rather than fetched again, typing debounced. If the volume is real, say what
 * you are building at chargealong.io/en/contact/.
 */
final class RateLimited extends ChargeAlongException
{
    public function __construct(
        int $status,
        string $title = '',
        string $detail = '',
        /** Seconds the service asked for, when it said. */
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($status, $title, $detail);
    }
}

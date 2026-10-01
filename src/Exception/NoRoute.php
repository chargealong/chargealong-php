<?php

declare(strict_types=1);

namespace ChargeAlong\Exception;

/**
 * No road route between the two points of a trip (422).
 *
 * An answer to show rather than a parameter to fix: Sydney to Auckland has no
 * road. Melbourne to Hobart does, since the router takes the ferry, and that
 * plan comes back infeasible with the crossing as its gap.
 */
final class NoRoute extends BadRequest
{
}

<?php

declare(strict_types=1);

namespace ChargeAlong\Exception;

/**
 * What the API said when it refused.
 *
 * The service answers RFC 7807 problem documents: a title and a detail written
 * for a person to read and act on, such as which parameter is out of range.
 * Collapsing that into "HTTP 400" throws away the only part of the answer that
 * says what to do about it.
 */
class ChargeAlongException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $title = '',
        public readonly string $detail = '',
    ) {
        $message = $title !== '' ? $title : "HTTP $status";
        if ($detail !== '') {
            $message .= ': ' . $detail;
        }
        parent::__construct($message, $status);
    }

    /**
     * The exception for a status: the status decides, never the body.
     */
    public static function for(int $status, string $title = '', string $detail = '', ?int $retryAfter = null): self
    {
        return match (true) {
            $status === 404 => new NotFound($status, $title, $detail),
            $status === 422 => new NoRoute($status, $title, $detail),
            $status === 429 => new RateLimited($status, $title, $detail, $retryAfter),
            $status >= 400 && $status < 500 => new BadRequest($status, $title, $detail),
            default => new self($status, $title, $detail),
        };
    }
}

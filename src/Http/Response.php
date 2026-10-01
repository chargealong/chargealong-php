<?php

declare(strict_types=1);

namespace ChargeAlong\Http;

/**
 * What came back: the status, the headers with lowercased names, the body.
 */
final class Response
{
    /**
     * @param array<string, string> $headers lowercased name => value
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}

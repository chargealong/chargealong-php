<?php

declare(strict_types=1);

namespace ChargeAlong\Http;

/**
 * How a request reaches the API.
 *
 * One method, so a framework's own client fits behind it in a few lines:
 * WordPress's `wp_remote_get`, Laravel's `Http`, a PSR-18 client. The default is
 * {@see CurlTransport}.
 *
 * An implementation must not follow redirects, must hand back every status
 * rather than throwing on 4xx and 5xx, and throws {@see TransportException}
 * only when there is no answer at all.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers header name => value
     */
    public function get(string $url, array $headers): Response;
}

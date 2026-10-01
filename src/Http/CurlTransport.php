<?php

declare(strict_types=1);

namespace ChargeAlong\Http;

/**
 * The default transport, on ext-curl.
 */
final class CurlTransport implements Transport
{
    /**
     * Far past any answer this API gives, and short of what a hostile or
     * broken peer could make a PHP worker hold in memory.
     */
    public const MAX_BODY = 8 << 20;

    public function __construct(private readonly float $timeout = 15.0)
    {
        if (!\function_exists('curl_init')) {
            throw new \LogicException('chargealong: ext-curl is not loaded; pass a Transport of your own');
        }
    }

    public function get(string $url, array $headers): Response
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https' && $scheme !== 'http') {
            throw new TransportException("chargealong: refusing to fetch a $scheme URL");
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $body = '';
        $received = [];
        $tooBig = false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $lines,
            // A redirect is not an answer this API gives. Following one would
            // hand the request to wherever the Location points.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => (int) min($this->timeout * 1000, 5000),
            CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$received): int {
                $colon = strpos($line, ':');
                if ($colon !== false) {
                    $received[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
                } elseif (str_starts_with($line, 'HTTP/')) {
                    // A new status line: headers from an interim response
                    // (100 Continue) do not belong to the final one.
                    $received = [];
                }
                return \strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooBig): int {
                if (\strlen($body) + \strlen($chunk) > self::MAX_BODY) {
                    $tooBig = true;
                    return 0;
                }
                $body .= $chunk;
                return \strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        unset($ch);

        if ($tooBig) {
            throw new TransportException('chargealong: the response was larger than ' . self::MAX_BODY . ' bytes');
        }
        if ($ok === false || $status === 0) {
            throw new TransportException('chargealong: ' . ($error !== '' ? $error : 'no response'));
        }

        return new Response($status, $received, $body);
    }
}

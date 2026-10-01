<?php

declare(strict_types=1);

namespace ChargeAlong\Tests\Support;

use ChargeAlong\Http\Response;
use ChargeAlong\Http\Transport;

/**
 * Records every request and answers with whatever the test set up.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{url: string, headers: array<string, string>}> */
    public array $calls = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private string $body = '{"data":[]}',
        private int $status = 200,
        private array $headers = [],
    ) {
    }

    /**
     * @param mixed $data
     * @param array<string, string> $headers
     */
    public static function json($data, int $status = 200, array $headers = []): self
    {
        return new self(json_encode($data, JSON_THROW_ON_ERROR), $status, $headers);
    }

    /**
     * A real answer captured from api.chargealong.io (tests/fixtures).
     *
     * @param array<string, string> $headers
     */
    public static function fixture(string $name, int $status = 200, array $headers = []): self
    {
        return new self((string) file_get_contents(__DIR__ . "/../fixtures/$name.json"), $status, $headers);
    }

    /** @return array<string, mixed> */
    public static function load(string $name): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) file_get_contents(__DIR__ . "/../fixtures/$name.json"), true, 64, JSON_THROW_ON_ERROR);
    }

    public function get(string $url, array $headers): Response
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers];

        return new Response($this->status, $this->headers, $this->body);
    }

    public function url(): string
    {
        return $this->calls[array_key_last($this->calls)]['url'];
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->calls[array_key_last($this->calls)]['headers'];
    }

    public function path(): string
    {
        return (string) parse_url($this->url(), PHP_URL_PATH);
    }

    /**
     * The query as the service reads it: name and value in order, a name
     * repeated when it was sent more than once. parse_str would keep the last.
     *
     * @return list<array{string, string}>
     */
    public function pairs(): array
    {
        $query = (string) parse_url($this->url(), PHP_URL_QUERY);
        if ($query === '') {
            return [];
        }

        return array_map(static function (string $pair): array {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            return [rawurldecode($name), rawurldecode($value)];
        }, explode('&', $query));
    }

    /**
     * The query as a map, for a request that names each parameter once.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        $out = [];
        foreach ($this->pairs() as [$name, $value]) {
            $out[$name] = $value;
        }

        return $out;
    }
}

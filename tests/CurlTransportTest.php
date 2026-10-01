<?php

declare(strict_types=1);

namespace ChargeAlong\Tests;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ChargeAlong\Http\CurlTransport;
use ChargeAlong\Http\TransportException;

/**
 * The default transport, against a real server on loopback.
 */
#[RequiresPhpExtension('curl')]
final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        self::$base = 'http://' . $name;

        $cmd = [PHP_BINARY, '-S', $name, __DIR__ . '/Support/server.php'];
        $proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($proc);
        self::$server = $proc;

        for ($i = 0; $i < 100; $i++) {
            $probe = @fsockopen('127.0.0.1', (int) substr($name, strrpos($name, ':') + 1));
            if ($probe) {
                fclose($probe);
                return;
            }
            usleep(20_000);
        }
        self::fail('the test server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    public function testSendsTheHeadersItIsGivenAndReadsTheAnswer(): void
    {
        $res = (new CurlTransport())->get(self::$base . '/echo?q=coburg', [
            'Accept' => 'application/json',
            'User-Agent' => 'chargealong-php/test',
        ]);

        self::assertSame(200, $res->status);
        self::assertSame('abc', $res->headers['x-trace']);
        $seen = json_decode($res->body, true);
        self::assertSame('GET', $seen['method']);
        self::assertSame('q=coburg', $seen['query']);
        self::assertSame('application/json', $seen['accept']);
        self::assertSame('chargealong-php/test', $seen['user_agent']);
        self::assertNull($seen['cookie']);
    }

    public function testDoesNotFollowARedirect(): void
    {
        $res = (new CurlTransport())->get(self::$base . '/redirect', []);

        self::assertSame(302, $res->status);
        self::assertSame('/echo', $res->headers['location']);
    }

    public function testHandsBackAnErrorStatusRatherThanThrowing(): void
    {
        $res = (new CurlTransport())->get(self::$base . '/problem', []);

        self::assertSame(429, $res->status);
        self::assertSame('7', $res->headers['retry-after']);
        self::assertSame('{"title":"slow down"}', $res->body);
    }

    public function testStopsReadingABodyPastTheCap(): void
    {
        $this->expectException(TransportException::class);
        (new CurlTransport())->get(self::$base . '/big', []);
    }

    public function testRefusesASchemeOtherThanHttpOrHttps(): void
    {
        $this->expectException(TransportException::class);
        (new CurlTransport())->get('file:///etc/passwd', []);
    }

    public function testReportsAConnectionThatFails(): void
    {
        $this->expectException(TransportException::class);
        (new CurlTransport(timeout: 2.0))->get('http://127.0.0.1:1/', []);
    }
}

<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Install\ExposureProbe;
use TiffinDB\Core\Install\LoopbackHttpProbe;
use TiffinDB\Core\Install\ProbeResponse;
use TiffinDB\Core\Install\ProbeTarget;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;

/**
 * T-INS-8: LoopbackHttpProbe over a local socket pair made inside this
 * process. No test here opens a network socket: every connector is a
 * closure returning one end of stream_socket_pair, or false.
 */
#[CoversClass(LoopbackHttpProbe::class)]
final class LoopbackHttpProbeTest extends TestCase
{
    /** @var list<resource> */
    private array $open = [];

    protected function tearDown(): void
    {
        foreach ($this->open as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private static function target(bool $secure = false, string $address = '192.0.2.10', string $host = 'example.test:8080'): ProbeTarget
    {
        return new ProbeTarget($host, $address, 8080, $secure, null, '');
    }

    /**
     * A connector that records its arguments and hands the probe one end
     * of a socket pair whose other end already holds $response; the other
     * end is returned through $server so the test can read the request.
     *
     * @param array<string, mixed> $calls
     * @param resource|null $server
     */
    private function connector(string $response, array &$calls, mixed &$server, bool $shutdown = true): \Closure
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$client, $server] = $pair;
        $this->open[] = $server;
        $written = 0;
        while ($written < strlen($response)) {
            $n = fwrite($server, substr($response, $written));
            $this->assertIsInt($n);
            $written += $n;
        }
        if ($shutdown) {
            stream_socket_shutdown($server, STREAM_SHUT_WR);
        }

        return static function (string $uri, array $options, float $timeout) use ($client, &$calls): mixed {
            $calls[] = ['uri' => $uri, 'options' => $options, 'timeout' => $timeout];

            return $client;
        };
    }

    /** @param resource $server */
    private static function readAll(mixed $server): string
    {
        stream_set_timeout($server, 1);

        return (string) stream_get_contents($server);
    }

    public function testRequestBytesStatusAndBody(): void
    {
        $calls = [];
        $server = null;
        $probe = new LoopbackHttpProbe($this->connector("HTTP/1.1 404 Not Found\r\nContent-Type: text/plain\r\n\r\nnot here\r\n\r\nstill body", $calls, $server));

        $response = $probe->get(self::target(), '/my%20site/data/probe-0123456789abcdef.txt');

        $this->assertEquals(new ProbeResponse(404, "not here\r\n\r\nstill body"), $response);
        $this->assertSame(
            "GET /my%20site/data/probe-0123456789abcdef.txt HTTP/1.0\r\nHost: example.test:8080\r\nUser-Agent: TiffinDB-setup-check\r\nConnection: close\r\n\r\n",
            self::readAll($server),
        );
        $this->assertSame([['uri' => 'tcp://192.0.2.10:8080', 'options' => [], 'timeout' => 5.0]], $calls);
    }

    public function testStatusWithoutReasonPhraseAndHttp10(): void
    {
        $calls = [];
        $server = null;
        $probe = new LoopbackHttpProbe($this->connector("HTTP/1.0 200\r\n\r\nabc", $calls, $server));

        $this->assertEquals(new ProbeResponse(200, 'abc'), $probe->get(self::target(), '/x'));
    }

    public function testLongAnswersAreTruncated(): void
    {
        $head = "HTTP/1.1 200 OK\r\n\r\n";
        $body = str_repeat('a', LoopbackHttpProbe::MAX_BYTES);
        $calls = [];
        $server = null;
        $probe = new LoopbackHttpProbe($this->connector($head . $body . 'TAIL', $calls, $server));

        $response = $probe->get(self::target(), '/x');

        $this->assertNotNull($response);
        $this->assertSame(200, $response->status);
        $this->assertSame(LoopbackHttpProbe::MAX_BYTES - strlen($head), strlen($response->body));
        $this->assertStringNotContainsString('TAIL', $response->body);
    }

    /** @return iterable<string, array{string}> */
    public static function nonHttp(): iterable
    {
        yield 'empty' => [''];
        yield 'SSH banner' => ["SSH-2.0-OpenSSH_9.6\r\n"];
        yield 'HTTP/2 status line' => ["HTTP/2 200\r\n\r\nx"];
        yield 'two-digit status' => ["HTTP/1.1 20 OK\r\n\r\nx"];
        yield 'no header end' => ["HTTP/1.1 200 OK\r\nContent-Length: 3\r\n"];
        yield 'bare LF' => ["HTTP/1.1 200 OK\n\nx"];
    }

    #[DataProvider('nonHttp')]
    public function testANonHttpAnswerGivesNull(string $answer): void
    {
        $calls = [];
        $server = null;
        $probe = new LoopbackHttpProbe($this->connector($answer, $calls, $server));

        $this->assertNull($probe->get(self::target(), '/x'));
    }

    public function testSecureTargetUsesTlsWithPeerNameWithoutPort(): void
    {
        $calls = [];
        $server = null;
        $probe = new LoopbackHttpProbe($this->connector("HTTP/1.1 403 Forbidden\r\n\r\n", $calls, $server));

        $this->assertEquals(new ProbeResponse(403, ''), $probe->get(self::target(true), '/x'));
        $this->assertSame('tls://192.0.2.10:8080', $calls[0]['uri']);
        $this->assertSame(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => 'example.test',
            'SNI_enabled' => true,
        ]], $calls[0]['options']);
    }

    public function testIpv6AddressIsBracketedAndHostIsNotUsedToConnect(): void
    {
        $calls = [];
        $server = null;
        $probe = new LoopbackHttpProbe($this->connector("HTTP/1.1 404 Not Found\r\n\r\n", $calls, $server));

        $probe->get(self::target(false, '2001:db8::1', 'attacker.example:80'), '/x');

        $this->assertSame('tcp://[2001:db8::1]:8080', $calls[0]['uri']);
        $this->assertStringContainsString("Host: attacker.example:80\r\n", self::readAll($server));
    }

    public function testUnsafePathsAreNotSent(): void
    {
        $called = false;
        $probe = new LoopbackHttpProbe(static function () use (&$called): mixed {
            $called = true;

            return false;
        });

        $this->assertNull($probe->get(self::target(), "/x HTTP/1.0\r\nX: y"));
        $this->assertNull($probe->get(self::target(), 'x'));
        $this->assertNull($probe->get(self::target(), '/a b'));
        $this->assertFalse($called);
    }

    public function testAWarningConnectorGivesNullAndRestoresTheHandler(): void
    {
        $refused = static function (): mixed {
            trigger_error('stream_socket_client(): Unable to connect (Connection refused)', E_USER_WARNING);

            return false;
        };
        $outerCalls = 0;
        set_error_handler(static function () use (&$outerCalls): bool {
            $outerCalls++;

            return true;
        });
        try {
            $result = (new LoopbackHttpProbe($refused))->get(self::target(), '/x');
            // The handler in place now must still be ours.
            $previous = set_error_handler(static fn (): bool => true);
            restore_error_handler();
        } finally {
            restore_error_handler();
        }

        $this->assertNull($result);
        $this->assertSame(0, $outerCalls, 'No warning escaped the probe.');
        $this->assertInstanceOf(\Closure::class, $previous);
        $this->assertNotNull($previous);
        $previous(E_USER_WARNING, 'check');
        $this->assertSame(1, $outerCalls, 'The handler restored after the probe is the one set before it.');
    }

    public function testAWarningWithAUsableStreamStillGivesNull(): void
    {
        // F4: a warning anywhere in the exchange discards even a good answer.
        $calls = [];
        $server = null;
        $inner = $this->connector("HTTP/1.1 200 OK\r\n\r\n0123456789abcdef0123456789abcdef", $calls, $server);
        $warning = static function (string $uri, array $options, float $timeout) use ($inner): mixed {
            trigger_error('stream_socket_client(): a warning on the way', E_USER_WARNING);

            return $inner($uri, $options, $timeout);
        };

        $this->assertNull((new LoopbackHttpProbe($warning))->get(self::target(), '/x'));
        $this->assertCount(1, $calls, 'The stream was handed over and used.');
    }

    public function testAnAnswerThatTricklesPastTheDeadlineGivesNull(): void
    {
        // F6: bytes keep coming within each read timeout, but the overall
        // deadline of 10 s passes. The fake time moves 6 s per reading.
        $calls = [];
        $server = null;
        $connector = $this->connector("HTTP/1.1 200 OK\r\n", $calls, $server, false);
        $now = -6.0;
        $seconds = static function () use (&$now): float {
            $now += 6.0;

            return $now;
        };

        $started = hrtime(true);
        $this->assertNull((new LoopbackHttpProbe($connector, $seconds))->get(self::target(), '/x'));
        $this->assertLessThan(2.0, (hrtime(true) - $started) / 1e9, 'No real read timeout was waited for.');
    }

    public function testAnAnswerWithinTheDeadlineIsKept(): void
    {
        $calls = [];
        $server = null;
        $connector = $this->connector("HTTP/1.1 404 Not Found\r\n\r\n", $calls, $server);
        $now = 0.0;
        $seconds = static function () use (&$now): float {
            $now += 1.0;

            return $now;
        };

        $this->assertEquals(new ProbeResponse(404, ''), (new LoopbackHttpProbe($connector, $seconds))->get(self::target(), '/x'));
    }

    public function testAThrowingConnectorGivesNull(): void
    {
        $probe = new LoopbackHttpProbe(static function (): mixed {
            throw new \RuntimeException('no');
        });

        $this->assertNull($probe->get(self::target(), '/x'));
    }

    public function testThroughExposureProbeARefusedConnectionIsInconclusive(): void
    {
        $temp = TempDir::create();
        try {
            mkdir($temp->path('www/app/data'), 0755, true);
            $refused = static function (): mixed {
                trigger_error('stream_socket_client(): Unable to connect', E_USER_WARNING);

                return false;
            };
            $verdict = (new ExposureProbe(new SeededRandom('tiffindb-loopback-seed-1')))->run(
                $temp->path('www/app/data'),
                $temp->path('www/app'),
                new ProbeTarget('example.test', '192.0.2.10', 80, false, $temp->path('www'), '/app'),
                new LoopbackHttpProbe($refused),
            );

            $this->assertSame('inconclusive', $verdict);
            $this->assertSame([], glob($temp->path('www/app/data') . '/probe-*') ?: []);
        } finally {
            $temp->remove();
        }
    }

    public function testParse(): void
    {
        $this->assertEquals(new ProbeResponse(500, 'x'), LoopbackHttpProbe::parse("HTTP/1.1 500 Internal Server Error\r\nA: b\r\n\r\nx"));
        $this->assertNull(LoopbackHttpProbe::parse("HTTP/1.1 500\r\n"));
    }
}

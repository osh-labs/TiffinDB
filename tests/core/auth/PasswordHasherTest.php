<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Auth\PasswordHasher;

/** T-AUTH-2: password hashing, rehashing, the default algorithm, and the dummy hashes. */
#[CoversClass(PasswordHasher::class)]
final class PasswordHasherTest extends TestCase
{
    private const PASSWORD = 'correct horse 1';

    public function testHashAndVerify(): void
    {
        $hasher = new PasswordHasher(['bcrypt' => 4]);
        $hash = $hasher->hash(self::PASSWORD);
        $this->assertStringStartsWith('$2y$04$', $hash);
        $this->assertTrue($hasher->verify(self::PASSWORD, $hash));
        $this->assertFalse($hasher->verify('correct horse 2', $hash));
        $this->assertFalse($hasher->verify('', $hash));
        $this->assertFalse($hasher->verify(self::PASSWORD, 'not a hash'));
        $this->assertSame('bcrypt', $hasher->algorithm());
    }

    public function testAPasswordWithANulByteNeverVerifies(): void
    {
        $hasher = new PasswordHasher(['bcrypt' => 4]);
        $hash = $hasher->hash(self::PASSWORD);
        // bcrypt itself would compare only the part before the NUL byte.
        $this->assertFalse($hasher->verify(self::PASSWORD . "\0extra", $hash));
        $this->expectException(\InvalidArgumentException::class);
        $hasher->hash("abc\0def");
    }

    public function testNeedsRehashAfterACostOrAlgorithmChange(): void
    {
        $cost4 = new PasswordHasher(['bcrypt' => 4]);
        $hash = $cost4->hash(self::PASSWORD);
        $this->assertFalse($cost4->needsRehash($hash));
        $this->assertTrue((new PasswordHasher(['bcrypt' => 5]))->needsRehash($hash));
        if (PasswordHasher::argon2idAvailable()) {
            $argon = new PasswordHasher(['argon2id' => ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]]);
            $this->assertTrue($argon->needsRehash($hash));
            $this->assertTrue($argon->verify(self::PASSWORD, $hash), 'Old hashes still verify after a change.');
        }
    }

    public function testDefaultAlgorithmMatchesWhatPhpProvides(): void
    {
        $expected = defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true) ? 'argon2id' : 'bcrypt';
        $this->assertSame($expected, (new PasswordHasher())->algorithm());
        $this->assertSame($expected === 'argon2id', PasswordHasher::argon2idAvailable());
    }

    public function testDefaultHashUsesTheDefaultParameters(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash(self::PASSWORD);
        $info = password_get_info($hash);
        if ($hasher->algorithm() === 'argon2id') {
            $this->assertSame('argon2id', $info['algoName']);
            $this->assertSame(['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1], $info['options']);
        } else {
            $this->assertSame('bcrypt', $info['algoName']);
            $this->assertSame(['cost' => 12], $info['options']);
        }
        $this->assertTrue($hasher->verify(self::PASSWORD, $hash));
        $this->assertFalse($hasher->needsRehash($hash));
    }

    public function testDummyHashesAreValidWithExactlyTheDefaultParameters(): void
    {
        $argon = password_get_info(PasswordHasher::DUMMY_ARGON2ID);
        $this->assertSame('argon2id', $argon['algoName']);
        $this->assertSame(['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1], $argon['options']);
        $this->assertSame(PasswordHasher::DEFAULT_ARGON2ID, $argon['options']);

        $bcrypt = password_get_info(PasswordHasher::DUMMY_BCRYPT);
        $this->assertSame('bcrypt', $bcrypt['algoName']);
        $this->assertSame(['cost' => 12], $bcrypt['options']);
        $this->assertSame(60, strlen(PasswordHasher::DUMMY_BCRYPT));
    }

    public function testVerifyDummyWithDefaultOptionsMakesNoHash(): void
    {
        $hasher = new PasswordHasher();
        $this->assertSame(0, $hasher->hashCalls(), 'The constructor uses the constant dummy.');
        $hasher->verifyDummy('anything at all');
        $hasher->verifyDummy('something else');
        $this->assertSame(0, $hasher->hashCalls());
        $this->assertSame(2, $hasher->verifyCalls());
    }

    public function testNonDefaultOptionsMakeTheDummyInTheConstructorOnly(): void
    {
        $hasher = new PasswordHasher(['bcrypt' => 4]);
        $this->assertSame(1, $hasher->hashCalls());
        $hasher->verifyDummy('anything at all');
        $this->assertSame(1, $hasher->hashCalls(), 'No hash on first use.');
        $this->assertSame(1, $hasher->verifyCalls());
    }

    public function testUnknownOptionsAreRefused(): void
    {
        foreach ([['md5' => 1], ['bcrypt' => 3], ['bcrypt' => '4'], ['bcrypt' => 4, 'argon2id' => []]] as $options) {
            try {
                new PasswordHasher($options);
                $this->fail('Accepted ' . json_encode($options));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

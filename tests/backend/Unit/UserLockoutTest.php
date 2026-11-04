<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit;

use Filegator\Config\Config;
use Filegator\Services\Auth\UserLockout;
use Filegator\Services\Auth\AuthInterface;
use Tests\TestCase;

/**
 * @internal
 */
class UserLockoutTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetTempDir();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->resetTempDir();
    }

    public function testUsesAdapterCanonicalUsername()
    {
        // same domain handling as the LDAP adapter with username_RemoveDomains / username_AddDomain
        $auth = $this->getMockBuilder(AuthInterface::class)->addMethods(['canonicalUsername'])->getMockForAbstractClass();
        $auth->method('canonicalUsername')->willReturnCallback(function ($username) {
            $username = str_replace(['@old.example.com'], '', $username);

            return strpos($username, '@example.com') === false ? $username . '@example.com' : $username;
        });
        $lockout = new UserLockout($this->lockoutConfig(), $auth);

        $lockout->registerFailure('john');
        $lockout->registerFailure('john@example.com');
        $lockout->registerFailure('John@old.example.com');
        $this->assertEquals(0, $lockout->remaining('john'));

        $this->assertEquals(60, $lockout->registerFailure(' JOHN '));
        $this->assertGreaterThan(0, $lockout->remaining('john@example.com'));

        $accounts = $lockout->lockedAccounts();
        $this->assertCount(1, $accounts);
        $this->assertEquals('john@example.com', $accounts[0]['username']);
        $this->assertEquals(4, $accounts[0]['failures']);

        // unlocking with the listed (already normalized) name works
        $this->assertTrue($lockout->clear($accounts[0]['username']));
        $this->assertEquals(0, $lockout->remaining('john'));
    }

    public function testControlCharactersAreStripped()
    {
        $lockout = new UserLockout($this->lockoutConfig(), $this->createMock(AuthInterface::class));

        $lockout->registerFailure("evil\nname");
        $lockout->registerFailure('evilname');

        $content = file_get_contents($lockout->getLockfile('evilname'));
        $this->assertEquals("evilname\nevilname\n", $content);
    }

    public function testDisabledWithEmptyTiers()
    {
        $lockout = new UserLockout($this->lockoutConfig(['user_lockout_tiers' => []]), $this->createMock(AuthInterface::class));

        for ($i = 0; $i < 20; $i++) {
            $this->assertEquals(0, $lockout->registerFailure('john'));
        }
        $this->assertEquals(0, $lockout->remaining('john'));
        $this->assertEmpty(glob(TEST_LOCKOUT_PATH . '*.ulock'));
    }

    public function testExpiredLockfilesAreRemoved()
    {
        $lockout = new UserLockout($this->lockoutConfig(['user_lockout_reset' => 3600]), $this->createMock(AuthInterface::class));

        $lockout->registerFailure('john');
        touch($lockout->getLockfile('john'), time() - 3600);

        $this->assertEquals([], $lockout->lockedAccounts());
        $this->assertFileNotExists($lockout->getLockfile('john'));
    }

    protected function lockoutConfig(array $override = []): Config
    {
        return new Config(array_merge([
            'user_lockout_path' => TEST_LOCKOUT_PATH,
            'user_lockout_tiers' => [4 => 60, 8 => 300],
            'user_lockout_reset' => 24 * 60 * 60,
        ], $override));
    }
}

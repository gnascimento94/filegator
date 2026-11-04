<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Feature;

use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Tests\TestCase;

/**
 * @internal
 */
class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        // lockout state is kept in tmpfs, start every test clean
        $this->resetTempDir();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->resetTempDir();
    }

    public function testSuccessfulLogin()
    {
        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'john123',
        ]);

        $this->assertOk();
    }

    public function testBadLogin()
    {
        $this->sendRequest('POST', '/login', [
            'username' => 'fake',
            'password' => 'fake',
        ]);

        $this->assertUnprocessable();
    }

    public function testBruteForceLogin()
    {
        // standard 422 bad response code
        $this->sendRequest('POST', '/login', [
            'username' => 'bad',
            'password' => 'bad',
        ], [], ['REMOTE_ADDR' => '10.10.10.10']);
        $this->assertStatus(422);

        // too many requests should change the response code to 429
        for ($i = 0; $i < 20; $i++) {
            $this->sendRequest('POST', '/login', [
                'username' => 'bad',
                'password' => 'bad',
            ], [], ['REMOTE_ADDR' => '10.10.10.10']);
        }
        $this->assertStatus(429);

        // now even the good one from this ip should fail as 429
        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'john123',
        ], [], ['REMOTE_ADDR' => '10.10.10.10']);
        $this->assertStatus(429);

        // another ip should fail as a standard 422 bad response (unaffected)
        // a different username is used because 'bad' is now locked per username too
        $this->sendRequest('POST', '/login', [
            'username' => 'bad2',
            'password' => 'bad',
        ], [], ['REMOTE_ADDR' => '2001:db8:3333:4444:5555:6666:7777:8888']);
        $this->assertStatus(422);

        // another ip with valid credentials should be ok (unaffected)
        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'john123',
        ], [], ['REMOTE_ADDR' => '20.20.20.20']);
        $this->assertOk();
    }

    public function testUserIsLockedAcrossDifferentIps()
    {
        // default tiers: 5 failures => 60 seconds
        for ($i = 1; $i <= 5; $i++) {
            $this->failLogin('john@example.com', "30.0.0.{$i}");
            $this->assertStatus(422);
        }

        // even the right password from a fresh ip is rejected while locked
        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'john123',
        ], [], ['REMOTE_ADDR' => '40.40.40.40']);
        $this->assertStatus(429);
        $this->assertResponseJsonHas(['data' => 'Too many failed login attempts, account temporarily locked']);

        // other users are not affected
        $this->sendRequest('POST', '/login', [
            'username' => 'admin@example.com',
            'password' => 'admin123',
        ], [], ['REMOTE_ADDR' => '40.40.40.40']);
        $this->assertOk();
    }

    public function testUsernameMatchingIsCaseInsensitive()
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->failLogin($i % 2 ? 'John@Example.com' : ' john@example.com ', "31.0.0.{$i}");
        }

        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'john123',
        ], [], ['REMOTE_ADDR' => '41.41.41.41']);
        $this->assertStatus(429);
    }

    public function testUnknownUsernamesAreLockedToo()
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->failLogin('nobody@example.com', "32.0.0.{$i}");
        }

        $this->failLogin('nobody@example.com', '42.42.42.42');
        $this->assertStatus(429);
    }

    public function testUserLockoutExpiresAndEscalates()
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->failLogin('john@example.com', "33.0.0.{$i}");
        }
        $this->failLogin('john@example.com', '43.0.0.1');
        $this->assertStatus(429);

        // after the 60 seconds tier expires the user can try again
        $this->ageUserLockfile('john@example.com', 61);
        $this->failLogin('john@example.com', '43.0.0.2');
        $this->assertStatus(422);

        // past the first tier, every new failure locks again
        $this->failLogin('john@example.com', '43.0.0.3');
        $this->assertStatus(429);

        // reach 10 failures => 5 minutes tier
        for ($i = 7; $i <= 10; $i++) {
            $this->ageUserLockfile('john@example.com', 61);
            $this->failLogin('john@example.com', "33.1.0.{$i}");
            $this->assertStatus(422);
        }
        $this->ageUserLockfile('john@example.com', 61);
        $this->failLogin('john@example.com', '43.0.0.4');
        $this->assertStatus(429);

        $this->ageUserLockfile('john@example.com', 5 * 60 + 1);
        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'john123',
        ], [], ['REMOTE_ADDR' => '43.0.0.5']);
        $this->assertOk();
    }

    public function testSuccessfulLoginResetsUserFailures()
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->failLogin('john@example.com', "34.0.0.{$i}");
        }

        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'john123',
        ], [], ['REMOTE_ADDR' => '44.0.0.1']);
        $this->assertOk();

        // counter starts from zero again, 4 more failures do not lock
        for ($i = 5; $i <= 8; $i++) {
            $this->failLogin('john@example.com', "34.0.0.{$i}");
            $this->assertStatus(422);
        }
    }

    public function testUserFailuresAreForgottenAfterResetPeriod()
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->failLogin('john@example.com', "35.0.0.{$i}");
        }

        // default user_lockout_reset is 24 hours
        $this->ageUserLockfile('john@example.com', 24 * 60 * 60);

        $this->failLogin('john@example.com', '45.0.0.1');
        $this->assertStatus(422);
        $this->failLogin('john@example.com', '45.0.0.2');
        $this->assertStatus(422);
    }

    protected function failLogin(string $username, string $ip)
    {
        $this->sendRequest('POST', '/login', [
            'username' => $username,
            'password' => 'wrong password',
        ], [], ['REMOTE_ADDR' => $ip]);
    }

    protected function ageUserLockfile(string $username, int $seconds)
    {
        $lockfile = TEST_LOCKOUT_PATH . md5('user:' . mb_strtolower(trim($username))) . '.ulock';
        touch($lockfile, time() - $seconds);
        clearstatcache();
    }

    public function testAdminCanListAndUnlockLockedUsers()
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->failLogin('John@Example.com', "36.0.0.{$i}");
        }
        $this->failLogin('nobody@example.com', '36.1.0.1');

        $this->signIn('admin@example.com', 'admin123');

        // only currently locked accounts are listed, not the ones with a few failures
        $this->sendRequest('GET', '/listlockedusers');
        $this->assertOk();
        $locked = json_decode($this->response->getContent(), true)['data'];
        $this->assertCount(1, $locked);
        $this->assertEquals('john@example.com', $locked[0]['username']);
        $this->assertEquals(5, $locked[0]['failures']);
        $this->assertGreaterThan(0, $locked[0]['remaining']);
        $this->assertLessThanOrEqual(60, $locked[0]['remaining']);

        $this->sendRequest('POST', '/unlockuser', ['username' => 'john@example.com']);
        $this->assertOk();

        $this->sendRequest('GET', '/listlockedusers');
        $this->assertEquals([], json_decode($this->response->getContent(), true)['data']);

        // unlocking again reports it was not locked
        $this->sendRequest('POST', '/unlockuser', ['username' => 'john@example.com']);
        $this->assertUnprocessable();

        // john can log in again and starts with a clean counter
        $this->signOut();
        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'john123',
        ], [], ['REMOTE_ADDR' => '46.0.0.1']);
        $this->assertOk();
    }

    public function testOnlyAdminCanSeeAndUnlockLockedUsers()
    {
        $this->sendRequest('GET', '/listlockedusers');
        $this->assertStatus(404);
        $this->sendRequest('POST', '/unlockuser', ['username' => 'john@example.com']);
        $this->assertStatus(404);

        $this->signIn('john@example.com', 'john123');
        $this->sendRequest('GET', '/listlockedusers');
        $this->assertStatus(404);
        $this->sendRequest('POST', '/unlockuser', ['username' => 'john@example.com']);
        $this->assertStatus(404);
    }

    public function testLockfilesAreNotInTmpfs()
    {
        $this->failLogin('john@example.com', '37.0.0.1');

        $this->assertNotEmpty(glob(TEST_LOCKOUT_PATH . '*.ulock'));
        $this->assertEmpty(glob(TEST_TMP_PATH . '*.ulock'));
    }

    public function testAlreadyLoggedIn()
    {
        $username = 'john@example.com';
        $this->signIn($username, 'john123');

        $this->sendRequest('POST', '/login', ['username' => $username, 'password' => 'john123']);

        $this->assertStatus(404);
    }

    public function testGetUser()
    {
        $user = 'john@example.com';
        $this->signIn($user, 'john123');

        $this->sendRequest('GET', '/getuser');

        $this->assertOk();
        $this->assertResponseJsonHas([
            'data' => [
                'username' => $user,
                'name' => 'John Doe',
                'role' => 'user',
                'homedir' => '/john',
            ],
        ]);
    }

    public function testGetAdmin()
    {
        $admin = 'admin@example.com';
        $this->signIn($admin, 'admin123');

        $this->sendRequest('GET', '/getuser');

        $this->assertOk();
        $this->assertResponseJsonHas([
            'data' => [
                'username' => $admin,
                'name' => 'Admin',
                'role' => 'admin',
                'homedir' => '/',
            ],
        ]);
    }

    public function testReceiveGuestIfNoUserIsLoggedIn()
    {
        $this->sendRequest('GET', '/getuser');

        $this->assertOk();
        $this->assertResponseJsonHas([
            'data' => [
                'role' => 'guest',
            ],
        ]);
    }

    public function testLogout()
    {
        $this->signIn('john@example.com', 'john123');
        $this->sendRequest('POST', '/logout');

        $this->assertOk();
    }

    public function testResponseThrows404()
    {
        $request = Request::create(
            '?r=/notfound',
            'GET'
        );

        $app = $this->bootFreshApp(null, $request);

        $response = $app->resolve(Response::class);

        $this->assertEquals($response->getStatusCode(), 404);
    }

    public function testChangePassword()
    {
        $this->signIn('john@example.com', 'john123');
        $this->sendRequest('POST', '/changepassword', [
            'oldpassword' => 'john123',
            'newpassword' => '',
        ]);
        $this->assertStatus(422);

        $this->signIn('john@example.com', 'john123');
        $this->sendRequest('POST', '/changepassword', [
            'oldpassword' => 'wrongpass',
            'newpassword' => 'password123',
        ]);
        $this->assertStatus(422);

        $this->signIn('john@example.com', 'john123');
        $this->sendRequest('POST', '/changepassword', [
            'oldpassword' => 'john123',
            'newpassword' => 'password123',
        ]);
        $this->assertOk();
    }
}

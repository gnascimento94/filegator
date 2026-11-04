<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Services\Auth;

use Filegator\Config\Config;

/**
 * Progressive per-username login lockout.
 *
 * Each username has a lockfile in its own folder (outside tmpfs garbage collection).
 * One line with the normalized username is appended per failed attempt, so the line count
 * is the number of failures and the file mtime marks the last failure. Appending avoids
 * read-modify-write races between simultaneous requests.
 */
class UserLockout
{
    protected $auth;

    protected $path;

    protected $tiers;

    protected $reset;

    public function __construct(Config $config, AuthInterface $auth)
    {
        $this->auth = $auth;
        $this->path = rtrim((string) $config->get('user_lockout_path', dirname(__DIR__, 3) . '/private/lockout'), '/\\') . '/';
        $this->tiers = (array) $config->get('user_lockout_tiers', [5 => 60, 10 => 5 * 60, 15 => 30 * 60]);
        $this->reset = (int) $config->get('user_lockout_reset', 24 * 60 * 60);
    }

    public function isEnabled(): bool
    {
        return ! empty($this->tiers);
    }

    /**
     * Username as it is counted: lowercase, no control chars, and with the same
     * domain handling as the auth adapter (e.g. LDAP), so variants share one counter
     */
    public function normalize(string $username): string
    {
        $username = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $username));

        if (method_exists($this->auth, 'canonicalUsername')) {
            $username = $this->auth->canonicalUsername($username);
        }

        return mb_strtolower($username);
    }

    /**
     * Seconds left until the username can try to log in again, 0 if not locked
     */
    public function remaining(string $username): int
    {
        if (! $this->isEnabled()) {
            return 0;
        }

        $state = $this->readState($this->getLockfile($this->normalize($username)));

        return $state ? $state['remaining'] : 0;
    }

    /**
     * Records a failed attempt and returns the lockout duration it triggered (0 = not locked)
     */
    public function registerFailure(string $username): int
    {
        if (! $this->isEnabled()) {
            return 0;
        }

        $this->ensurePath();

        $key = $this->normalize($username);
        $lockfile = $this->getLockfile($key);

        file_put_contents($lockfile, $key . "\n", FILE_APPEND | LOCK_EX);

        // forget abandoned usernames from time to time
        if (mt_rand(0, 99) < 10) {
            $this->lockedAccounts();
        }

        clearstatcache();

        return $this->duration($this->countFailures($lockfile));
    }

    /**
     * Removes the failed attempts of a username, returns false if there were none
     */
    public function clear(string $username): bool
    {
        $cleared = false;

        // accept both a login username and an already normalized one (as listed by lockedAccounts)
        $keys = array_unique([$this->normalize($username), mb_strtolower(trim($username))]);

        foreach ($keys as $key) {
            $lockfile = $this->getLockfile($key);
            if (is_file($lockfile) && unlink($lockfile)) {
                $cleared = true;
            }
        }

        return $cleared;
    }

    /**
     * Currently locked accounts, expired lockfiles are removed on the way
     */
    public function lockedAccounts(): array
    {
        $accounts = [];

        foreach (glob($this->path . '*.ulock') ?: [] as $lockfile) {
            $state = $this->readState($lockfile);
            if ($state && $state['remaining'] > 0) {
                $accounts[] = [
                    'username' => $state['username'],
                    'failures' => $state['failures'],
                    'remaining' => $state['remaining'],
                    'last_failure' => $state['last_failure'],
                ];
            }
        }

        usort($accounts, function ($a, $b) {
            return $b['last_failure'] - $a['last_failure'];
        });

        return $accounts;
    }

    /**
     * Lockout duration for a number of failed attempts: the highest tier reached,
     * so every failure past a tier locks the account again for that tier's duration
     */
    public function duration(int $failures): int
    {
        $duration = 0;

        foreach ($this->tiers as $attempts => $seconds) {
            if ($failures >= (int) $attempts) {
                $duration = max($duration, (int) $seconds);
            }
        }

        return $duration;
    }

    public function getLockfile(string $normalized_username): string
    {
        return $this->path . md5('user:' . $normalized_username) . '.ulock';
    }

    protected function readState(string $lockfile): ?array
    {
        clearstatcache();

        if (! is_file($lockfile)) {
            return null;
        }

        $last_failure = (int) filemtime($lockfile);
        $elapsed = time() - $last_failure;

        if ($this->reset > 0 && $elapsed >= $this->reset) {
            @unlink($lockfile);

            return null;
        }

        $content = (string) file_get_contents($lockfile);
        $failures = substr_count($content, "\n");

        return [
            'username' => strstr($content, "\n", true) ?: '',
            'failures' => $failures,
            'last_failure' => $last_failure,
            'remaining' => max(0, $this->duration($failures) - $elapsed),
        ];
    }

    protected function countFailures(string $lockfile): int
    {
        return substr_count((string) file_get_contents($lockfile), "\n");
    }

    protected function ensurePath()
    {
        if (! is_dir($this->path)) {
            mkdir($this->path, 0775, true);
        }
    }
}

<?php

declare(strict_types=1);

/**
 * System B: the ATS API, faked. This file is the API, its failures and its database.
 *
 * Everything you know about the tags has to come through the two public methods below.
 *
 * Do not modify this file. Your code has to cope with system B as it is.
 */

final class RateLimitException extends RuntimeException
{
    /**
     * @param int|null $retryAfter Seconds the API asks us to wait, null when it does not say.
     */
    public function __construct(
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct('429 Too Many Requests');
    }
}

final class FakeAtsClient
{
    /**
     * What system B holds before anyone touches it. Its seed, not your input.
     *
     * @var array<int, list<string>>
     */
    private const array INITIAL_TAGS = [
        1 => ['brand-balaton'],
        2 => ['brand-balaton'],
        3 => [],
        4 => ['brand-triglav'],
        5 => ['brand-kampinos', 'vip'],
        6 => [],
    ];

    /** Static: B counts the calls you make, not the clients you build. Ten of them, one counter. */
    private static int $callCount = 0;

    /** @var array{log_file: string, db_file: string, rate_limit_every: int, retry_after: ?int, die_after_calls: ?int, die_at: 'before'|'after'} */
    private readonly array $config;

    public function __construct()
    {
        if (PHP_VERSION_ID < 80400) {
            throw new RuntimeException(sprintf('PHP 8.4 or newer is required, got %s.', PHP_VERSION));
        }

        $this->config = require __DIR__ . '/config.php';

        foreach ([$this->config['log_file'], $this->config['db_file']] as $file) {
            $directory = dirname($file);

            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
        }
    }

    /**
     * GET /candidates/{id}/tags - every tag the candidate currently carries.
     *
     * @return list<string>
     * @throws RateLimitException
     */
    public function tags(int $candidateId): array
    {
        $request = sprintf('GET /candidates/%d/tags', $candidateId);

        $this->begin($request);

        $tags = $this->database()[$candidateId] ?? [];

        $this->complete($request);

        return $tags;
    }

    /**
     * POST /candidates/{id}/tags - adds tags to the candidate.
     *
     * Additive: the tags he already has stay, the new ones join them. B stores this on disk, so a
     * later tags() reports it - including after your process has been restarted.
     *
     * @param list<string> $tags
     * @throws RateLimitException
     */
    public function addTags(int $candidateId, array $tags): void
    {
        sort($tags);

        $request = sprintf('POST /candidates/%d/tags %s', $candidateId, implode(',', $tags));

        $this->begin($request);

        $database = $this->database();
        $database[$candidateId] = array_values(array_unique([...$database[$candidateId] ?? [], ...$tags]));

        $this->write($database);

        $this->complete($request);
    }

    /**
     * Seeded on first contact, then it lives on disk and outlives your process.
     *
     * @return array<int, list<string>>
     */
    private function database(): array
    {
        if (!is_file($this->config['db_file'])) {
            $this->write(self::INITIAL_TAGS);

            return self::INITIAL_TAGS;
        }

        return json_decode(file_get_contents($this->config['db_file']), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<int, list<string>> $database
     */
    private function write(array $database): void
    {
        file_put_contents(
            $this->config['db_file'],
            json_encode($database, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            LOCK_EX,
        );
    }

    /** @throws RateLimitException */
    private function begin(string $request): void
    {
        self::$callCount++;

        $this->sigkillWhenDue($request, 'before');
        $this->checkRateLimit($request);
    }

    private function complete(string $request): void
    {
        $this->sigkillWhenDue($request, 'after');

        $this->log(sprintf('200 %s', $request));
    }

    /** @throws RateLimitException */
    private function checkRateLimit(string $request): void
    {
        // A call due to die after its write is let through: otherwise the limit would bounce it
        // first and the kill would silently never land.
        if (self::$callCount % $this->config['rate_limit_every'] !== 0 || $this->isDueToDie('after')) {
            return;
        }

        $this->log(sprintf('429 %s', $request));

        throw new RateLimitException($this->config['retry_after']);
    }

    /** @param 'before'|'after' $position */
    private function isDueToDie(string $position): bool
    {
        return $this->config['die_after_calls'] !== null
            && self::$callCount === $this->config['die_after_calls']
            && $this->config['die_at'] === $position;
    }

    /** @param 'before'|'after' $position */
    private function sigkillWhenDue(string $request, string $position): void
    {
        if (!$this->isDueToDie($position)) {
            return;
        }

        $this->log(sprintf('KILLED %s %s', $position, $request));

        if (function_exists('posix_kill') && defined('SIGKILL')) {
            posix_kill(posix_getpid(), SIGKILL);
        }

        exit(1);
    }

    private function log(string $line): void
    {
        file_put_contents($this->config['log_file'], $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

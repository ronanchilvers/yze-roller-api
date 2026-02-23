<?php

declare(strict_types=1);

namespace YZERoller\Api\Security;

final class FileJoinRateLimiter implements JoinRateLimiter
{
    /**
     * @var callable():int
     */
    private $timeProvider;

    /**
     * @param callable():int|null $timeProvider
     */
    public function __construct(
        private readonly string $storageFilePath,
        private readonly int $maxAttempts = 10,
        private readonly int $windowSeconds = 60,
        ?callable $timeProvider = null
    ) {
        $this->timeProvider = $timeProvider ?? static fn (): int => time();
    }

    public function allow(string $key): bool
    {
        if ($key === '' || $this->maxAttempts <= 0 || $this->windowSeconds <= 0) {
            return true;
        }

        $handle = @fopen($this->storageFilePath, 'c+');
        if (!is_resource($handle)) {
            // Fail open to avoid denying valid requests on local storage issues.
            return true;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return true;
            }

            $raw = stream_get_contents($handle);
            $state = $this->decodeState($raw);
            $now = ($this->timeProvider)();

            $entries = $this->pruneEntries($state['entries'], $now);
            $entry = $entries[$key] ?? null;

            if ($entry === null || ($now - $entry['window_start']) >= $this->windowSeconds) {
                $entries[$key] = [
                    'window_start' => $now,
                    'count' => 1,
                ];
                $allowed = true;
            } elseif ($entry['count'] >= $this->maxAttempts) {
                $allowed = false;
            } else {
                $entry['count']++;
                $entries[$key] = $entry;
                $allowed = true;
            }

            $nextState = [
                'entries' => $entries,
            ];
            $this->writeState($handle, $nextState);

            return $allowed;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array{entries:array<string,array{window_start:int,count:int}>}
     */
    private function decodeState(string|false $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return ['entries' => []];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_array($decoded['entries'] ?? null)) {
            return ['entries' => []];
        }

        $entries = [];
        foreach ($decoded['entries'] as $key => $entry) {
            if (!is_string($key) || !is_array($entry)) {
                continue;
            }
            $windowStart = $entry['window_start'] ?? null;
            $count = $entry['count'] ?? null;
            if (!is_int($windowStart) || !is_int($count)) {
                continue;
            }
            if ($windowStart < 0 || $count < 0) {
                continue;
            }

            $entries[$key] = [
                'window_start' => $windowStart,
                'count' => $count,
            ];
        }

        return ['entries' => $entries];
    }

    /**
     * @param array<string,array{window_start:int,count:int}> $entries
     * @return array<string,array{window_start:int,count:int}>
     */
    private function pruneEntries(array $entries, int $now): array
    {
        return array_filter(
            $entries,
            fn (array $entry): bool => ($now - $entry['window_start']) < $this->windowSeconds
        );
    }

    /**
     * @param resource $handle
     * @param array{entries:array<string,array{window_start:int,count:int}>} $state
     */
    private function writeState($handle, array $state): void
    {
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $json);
        fflush($handle);
    }
}


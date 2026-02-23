<?php

declare(strict_types=1);

namespace YZERoller\Api\TestsMariaDb;

use flight\database\SimplePdo;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use YZERoller\Api\Auth\AuthGuard;
use YZERoller\Api\Auth\TokenLookup;
use YZERoller\Api\Security\JoinRateLimiter;
use YZERoller\Api\Service\EventsSubmitService;
use YZERoller\Api\Service\GmPlayerRevokeService;
use YZERoller\Api\Service\GmSceneStrainResetService;
use YZERoller\Api\Service\JoinService;
use YZERoller\Api\Service\SessionBootstrapService;
use YZERoller\Api\Validation\RequestValidator;

final class TransactionConcurrencyMariaDbTest extends TestCase
{
    /** @var array{host:string,port:int,user:string,password:string,db:string,site_url:string} */
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('MariaDB concurrency tests require pcntl extension.');
        }
        $this->config = $this->loadRequiredConfig();
        $this->resetSchema();
    }

    public function testConcurrentRevokeEmitsSingleLeaveEvent(): void
    {
        $seed = $this->seedSessionWithPlayer();

        $results = $this->runConcurrently(
            fn (): array => $this->revokeWorker($seed['gm_token'], $seed['session_id'], $seed['player_token_id']),
            fn (): array => $this->revokeWorker($seed['gm_token'], $seed['session_id'], $seed['player_token_id'])
        );

        self::assertSame(200, $results[0]['code']);
        self::assertSame(200, $results[1]['code']);

        $flags = [
            (bool) ($results[0]['data']['event_emitted'] ?? false),
            (bool) ($results[1]['data']['event_emitted'] ?? false),
        ];
        sort($flags);
        self::assertSame([false, true], $flags);

        $db = $this->newDb();
        $leaveCount = $db->fetchRow(
            'SELECT COUNT(*) AS count_total FROM events WHERE event_session_id = ? AND event_type = ?',
            [$seed['session_id'], 'leave']
        );
        self::assertNotNull($leaveCount);
        self::assertSame(1, (int) ($leaveCount['count_total'] ?? 0));
    }

    public function testConcurrentPushStrainPreservesSceneStrainTotal(): void
    {
        $seed = $this->seedSessionWithPlayer();

        $results = $this->runConcurrently(
            fn (): array => $this->pushWorker($seed['gm_token'], 1),
            fn (): array => $this->pushWorker($seed['gm_token'], 1)
        );

        self::assertSame(201, $results[0]['code']);
        self::assertSame(201, $results[1]['code']);

        $db = $this->newDb();
        $stateRow = $db->fetchRow(
            'SELECT state_value FROM session_state WHERE state_session_id = ? AND state_name = ?',
            [$seed['session_id'], 'scene_strain']
        );
        self::assertNotNull($stateRow);
        self::assertSame('2', (string) ($stateRow['state_value'] ?? ''));

        $pushCount = $db->fetchRow(
            'SELECT COUNT(*) AS count_total FROM events WHERE event_session_id = ? AND event_type = ?',
            [$seed['session_id'], 'push']
        );
        self::assertNotNull($pushCount);
        self::assertSame(2, (int) ($pushCount['count_total'] ?? 0));
    }

    public function testConcurrentResetAndPushPreservesBothEvents(): void
    {
        $seed = $this->seedSessionWithPlayer();

        // Seed non-zero scene strain first.
        $seedPush = $this->pushWorker($seed['gm_token'], 2);
        self::assertSame(201, $seedPush['code']);

        $db = $this->newDb();
        $beforePushCountRow = $db->fetchRow(
            'SELECT COUNT(*) AS count_total FROM events WHERE event_session_id = ? AND event_type = ?',
            [$seed['session_id'], 'push']
        );
        $beforeResetCountRow = $db->fetchRow(
            'SELECT COUNT(*) AS count_total FROM events WHERE event_session_id = ? AND event_type = ?',
            [$seed['session_id'], 'strain_reset']
        );
        $beforePushCount = (int) ($beforePushCountRow['count_total'] ?? 0);
        $beforeResetCount = (int) ($beforeResetCountRow['count_total'] ?? 0);

        $results = $this->runConcurrently(
            fn (): array => $this->resetWorker($seed['gm_token'], $seed['session_id']),
            fn (): array => $this->pushWorker($seed['gm_token'], 1)
        );

        $codes = [$results[0]['code'], $results[1]['code']];
        sort($codes);
        self::assertSame([200, 201], $codes);

        $afterPushCountRow = $db->fetchRow(
            'SELECT COUNT(*) AS count_total FROM events WHERE event_session_id = ? AND event_type = ?',
            [$seed['session_id'], 'push']
        );
        $afterResetCountRow = $db->fetchRow(
            'SELECT COUNT(*) AS count_total FROM events WHERE event_session_id = ? AND event_type = ?',
            [$seed['session_id'], 'strain_reset']
        );
        $afterPushCount = (int) ($afterPushCountRow['count_total'] ?? 0);
        $afterResetCount = (int) ($afterResetCountRow['count_total'] ?? 0);

        self::assertSame($beforePushCount + 1, $afterPushCount);
        self::assertSame($beforeResetCount + 1, $afterResetCount);

        $stateRow = $db->fetchRow(
            'SELECT state_value FROM session_state WHERE state_session_id = ? AND state_name = ?',
            [$seed['session_id'], 'scene_strain']
        );
        self::assertNotNull($stateRow);
        $finalStrain = (int) ($stateRow['state_value'] ?? '0');
        self::assertContains($finalStrain, [0, 1]);
    }

    /**
     * @return array{session_id:int,gm_token:string,join_token:string,player_token_id:int}
     */
    private function seedSessionWithPlayer(): array
    {
        $db = $this->newDb();
        $bootstrap = $this->newBootstrapService($db);
        $createResponse = $bootstrap->createSession(['session_name' => 'Concurrency Session']);
        self::assertSame(201, $createResponse->code());

        $created = $createResponse->data();
        if (!is_array($created)) {
            throw new RuntimeException('Invalid create session payload.');
        }

        $sessionId = (int) ($created['session_id'] ?? 0);
        $gmToken = (string) ($created['gm_token'] ?? '');
        $joinLink = (string) ($created['join_link'] ?? '');
        $joinToken = $this->parseJoinToken($joinLink);

        $joinService = $this->newJoinService($db);
        $joinResponse = $joinService->join('Bearer ' . $joinToken, ['display_name' => 'Alice'], '127.0.0.1');
        self::assertSame(201, $joinResponse->code());
        $joined = $joinResponse->data();
        if (!is_array($joined)) {
            throw new RuntimeException('Invalid join payload.');
        }

        return [
            'session_id' => $sessionId,
            'gm_token' => $gmToken,
            'join_token' => $joinToken,
            'player_token_id' => (int) ($joined['player']['token_id'] ?? 0),
        ];
    }

    /**
     * @return array{code:int,data:array<string,mixed>|object|null}
     */
    private function revokeWorker(string $gmToken, int $sessionId, int $playerTokenId): array
    {
        $db = $this->newDb();
        $service = $this->newRevokeService($db);
        $response = $service->revoke('Bearer ' . $gmToken, (string) $sessionId, (string) $playerTokenId, []);

        return [
            'code' => $response->code(),
            'data' => $response->data(),
        ];
    }

    /**
     * @return array{code:int,data:array<string,mixed>|object|null}
     */
    private function pushWorker(string $gmToken, int $banes): array
    {
        $db = $this->newDb();
        $service = $this->newEventsSubmitService($db);
        $response = $service->submit('Bearer ' . $gmToken, [
            'type' => 'push',
            'payload' => [
                'successes' => 1,
                'banes' => $banes,
                'strain' => true,
            ],
        ]);

        return [
            'code' => $response->code(),
            'data' => $response->data(),
        ];
    }

    /**
     * @return array{code:int,data:array<string,mixed>|object|null}
     */
    private function resetWorker(string $gmToken, int $sessionId): array
    {
        $db = $this->newDb();
        $service = $this->newResetService($db);
        $response = $service->reset('Bearer ' . $gmToken, (string) $sessionId, []);

        return [
            'code' => $response->code(),
            'data' => $response->data(),
        ];
    }

    /**
     * @param callable():array<string,mixed> ...$workers
     * @return array<int,array<string,mixed>>
     */
    private function runConcurrently(callable ...$workers): array
    {
        $tempFiles = [];
        $pids = [];

        foreach ($workers as $index => $worker) {
            $tempPath = tempnam(sys_get_temp_dir(), 'yze_cc_');
            if ($tempPath === false) {
                throw new RuntimeException('Failed to create temp file for worker output.');
            }

            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Failed to fork worker process.');
            }

            if ($pid === 0) {
                $payload = null;
                try {
                    $payload = [
                        'ok' => true,
                        'result' => $worker(),
                    ];
                } catch (Throwable $e) {
                    $payload = [
                        'ok' => false,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ];
                }

                file_put_contents($tempPath, json_encode($payload, JSON_THROW_ON_ERROR));
                exit(0);
            }

            $tempFiles[$index] = $tempPath;
            $pids[$index] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];
        foreach ($tempFiles as $index => $tempPath) {
            $raw = file_get_contents($tempPath);
            @unlink($tempPath);
            if (!is_string($raw) || trim($raw) === '') {
                throw new RuntimeException('Worker produced no output.');
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
                $message = (string) ($decoded['error'] ?? 'Unknown worker failure');
                $trace = (string) ($decoded['trace'] ?? '');
                throw new RuntimeException("Worker failed: {$message}\n{$trace}");
            }

            $results[$index] = $decoded['result'];
        }

        ksort($results);

        return array_values($results);
    }

    private function parseJoinToken(string $joinLink): string
    {
        $parts = explode('#join=', $joinLink, 2);
        if (count($parts) !== 2 || trim($parts[1]) === '') {
            throw new RuntimeException('Invalid join link payload.');
        }

        return trim($parts[1]);
    }

    private function resetSchema(): void
    {
        $pdo = $this->newPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('DROP TABLE IF EXISTS events');
        $pdo->exec('DROP TABLE IF EXISTS session_state');
        $pdo->exec('DROP TABLE IF EXISTS session_tokens');
        $pdo->exec('DROP TABLE IF EXISTS session_join_tokens');
        $pdo->exec('DROP TABLE IF EXISTS sessions');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $schema = file_get_contents(__DIR__ . '/../schema/001_initial_schema.sql');
        if (!is_string($schema) || trim($schema) === '') {
            throw new RuntimeException('Failed to load schema file for MariaDB tests.');
        }
        $pdo->exec($schema);
    }

    /**
     * @return array{host:string,port:int,user:string,password:string,db:string,site_url:string}
     */
    private function loadRequiredConfig(): array
    {
        $host = getenv('YZE_MARIADB_TEST_HOST') ?: '';
        $user = getenv('YZE_MARIADB_TEST_USER') ?: '';
        $db = getenv('YZE_MARIADB_TEST_DB') ?: '';

        if ($host === '' || $user === '' || $db === '') {
            $this->markTestSkipped(
                'MariaDB integration tests require YZE_MARIADB_TEST_HOST, YZE_MARIADB_TEST_USER, and YZE_MARIADB_TEST_DB.'
            );
        }

        $portRaw = getenv('YZE_MARIADB_TEST_PORT');
        $port = is_string($portRaw) && $portRaw !== '' ? (int) $portRaw : 3306;

        return [
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'password' => (string) (getenv('YZE_MARIADB_TEST_PASSWORD') ?: ''),
            'db' => $db,
            'site_url' => (string) (getenv('YZE_MARIADB_TEST_SITE_URL') ?: 'http://localhost:8080'),
        ];
    }

    private function dsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config['host'],
            $this->config['port'],
            $this->config['db']
        );
    }

    private function newPdo(): PDO
    {
        return new PDO(
            $this->dsn(),
            $this->config['user'],
            $this->config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    private function newDb(): SimplePdo
    {
        return new SimplePdo(
            $this->dsn(),
            $this->config['user'],
            $this->config['password']
        );
    }

    private function newBootstrapService(SimplePdo $db): SessionBootstrapService
    {
        return new SessionBootstrapService(
            $db,
            new RequestValidator(),
            $this->config['site_url']
        );
    }

    private function newJoinService(SimplePdo $db): JoinService
    {
        return new JoinService(
            $db,
            $this->newAuthGuard($db),
            new RequestValidator(),
            new class implements JoinRateLimiter {
                public function allow(string $key): bool
                {
                    return true;
                }
            }
        );
    }

    private function newEventsSubmitService(SimplePdo $db): EventsSubmitService
    {
        return new EventsSubmitService(
            $db,
            $this->newAuthGuard($db),
            new RequestValidator()
        );
    }

    private function newRevokeService(SimplePdo $db): GmPlayerRevokeService
    {
        return new GmPlayerRevokeService(
            $db,
            $this->newAuthGuard($db),
            new RequestValidator()
        );
    }

    private function newResetService(SimplePdo $db): GmSceneStrainResetService
    {
        return new GmSceneStrainResetService(
            $db,
            $this->newAuthGuard($db),
            new RequestValidator()
        );
    }

    private function newAuthGuard(SimplePdo $db): AuthGuard
    {
        return new AuthGuard(new TokenLookup($db));
    }
}

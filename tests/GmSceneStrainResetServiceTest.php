<?php

declare(strict_types=1);

namespace YZERoller\Api\Tests;

use DateTimeImmutable;
use DateTimeZone;
use flight\database\SimplePdo;
use flight\util\Collection;
use PDO;
use PHPUnit\Framework\TestCase;
use YZERoller\Api\Auth\AuthGuard;
use YZERoller\Api\Auth\GmSessionAuthorizer;
use YZERoller\Api\Auth\TokenLookup;
use YZERoller\Api\Response;
use YZERoller\Api\Service\GmSceneStrainResetService;
use YZERoller\Api\Support\DateTimeFormatter;
use YZERoller\Api\Tests\Fixture\FixedClock;
use YZERoller\Api\Validation\RequestValidator;

final class GmSceneStrainResetServiceTest extends TestCase
{
    public function testResetReturnsTokenMissingWhenHeaderMissing(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::never())->method('fetchRow');

        $resetDb = $this->createResetDbMock();
        $resetDb->expects(self::never())->method('fetchRow');
        $resetDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $resetDb);
        $response = $service->reset(null, '7', []);

        self::assertSame(Response::STATUS_UNAUTHORIZED, $response->code());
        self::assertSame(Response::ERROR_TOKEN_MISSING, $response->data()['error']['code']);
    }

    public function testResetReturnsRoleForbiddenWhenNotGm(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'player', sessionId: 7, tokenId: 11));

        $resetDb = $this->createResetDbMock();
        $resetDb->expects(self::never())->method('fetchRow');
        $resetDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $resetDb);
        $response = $service->reset('Bearer gm-token', '7', []);

        self::assertSame(Response::STATUS_FORBIDDEN, $response->code());
        self::assertSame(Response::ERROR_ROLE_FORBIDDEN, $response->data()['error']['code']);
    }

    public function testResetReturnsValidationErrorForInvalidPathOrBody(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::exactly(1))
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7, tokenId: 99));

        $resetDb = $this->createResetDbMock();
        $resetDb->expects(self::never())->method('fetchRow');
        $resetDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $resetDb);
        $response = $service->reset('Bearer gm-token', 'x', []);
        self::assertSame(Response::STATUS_UNPROCESSABLE_ENTITY, $response->code());
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $response->data()['error']['code']);

        $response2 = $service->reset('Bearer gm-token', '7', ['unexpected' => true]);
        self::assertSame(Response::STATUS_UNPROCESSABLE_ENTITY, $response2->code());
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $response2->data()['error']['code']);
    }

    public function testResetReturnsRoleForbiddenWhenSessionMismatch(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 8, tokenId: 99));

        $resetDb = $this->createResetDbMock();
        $resetDb->expects(self::never())->method('fetchRow');
        $resetDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $resetDb);
        $response = $service->reset('Bearer gm-token', '7', []);

        self::assertSame(Response::STATUS_FORBIDDEN, $response->code());
        self::assertSame(Response::ERROR_ROLE_FORBIDDEN, $response->data()['error']['code']);
    }

    public function testResetReturnsSessionNotFoundWhenSessionMissing(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7, tokenId: 99));

        $resetDb = $this->createResetDbMock();
        $resetDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'), [7])
            ->willReturn(null);
        $resetDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $resetDb);
        $response = $service->reset('Bearer gm-token', '7', []);

        self::assertSame(Response::STATUS_NOT_FOUND, $response->code());
        self::assertSame(Response::ERROR_SESSION_NOT_FOUND, $response->data()['error']['code']);
    }

    public function testResetUpdatesExistingSceneStrainAndEmitsStrainResetEvent(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7, tokenId: 99));

        $statement = $this->getMockBuilder(\PDOStatement::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetch'])
            ->getMock();
        $statement->expects(self::once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([
                'state_id' => '17',
                'state_value' => '4',
            ]);

        $resetDb = $this->createResetDbMock();
        $resetDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'), [7])
            ->willReturn(new Collection(['session_id' => 7]));
        $resetDb->expects(self::once())
            ->method('runQuery')
            ->with(self::stringContains('FOR UPDATE'), [7, 'scene_strain'])
            ->willReturn($statement);
        $resetDb->expects(self::once())
            ->method('update')
            ->with(
                'session_state',
                [
                    'state_value' => '0',
                    'state_updated' => '2026-02-23 13:14:15.987000',
                ],
                'state_id = ?',
                [17]
            )
            ->willReturn(1);
        $resetDb->expects(self::once())
            ->method('insert')
            ->with(
                'events',
                self::callback(function (array $data): bool {
                    if (($data['event_session_id'] ?? null) !== 7) {
                        return false;
                    }
                    if (($data['event_actor_token_id'] ?? null) !== 99) {
                        return false;
                    }
                    if (($data['event_type'] ?? null) !== 'strain_reset') {
                        return false;
                    }
                    $payload = json_decode($data['event_payload_json'] ?? '', true);
                    return $payload === [
                        'previous_scene_strain' => 4,
                        'scene_strain' => 0,
                    ];
                })
            )
            ->willReturn('129');
        $resetDb->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(function (callable $callback) use ($resetDb) {
                return $callback($resetDb);
            });

        $fixedTime = new DateTimeImmutable('2026-02-23T13:14:15.987Z', new DateTimeZone('UTC'));

        $service = $this->createService($lookupDb, $resetDb, $fixedTime);
        $response = $service->reset('Bearer gm-token', '7', []);

        self::assertSame(Response::STATUS_OK, $response->code());
        self::assertSame([
            'session_id' => 7,
            'scene_strain' => 0,
            'event_id' => 129,
        ], $response->data());
    }

    public function testResetCreatesSceneStrainStateWhenMissing(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7, tokenId: 99));

        $statement = $this->getMockBuilder(\PDOStatement::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetch'])
            ->getMock();
        $statement->expects(self::once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);

        $resetDb = $this->createResetDbMock();
        $resetDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'), [7])
            ->willReturn(new Collection(['session_id' => 7]));
        $resetDb->expects(self::once())
            ->method('runQuery')
            ->with(self::stringContains('FOR UPDATE'), [7, 'scene_strain'])
            ->willReturn($statement);
        $resetDb->expects(self::never())->method('update');
        $insertCalls = [];
        $resetDb->expects(self::exactly(2))
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$insertCalls): string {
                $insertCalls[] = [$table, $data];

                return $table === 'session_state' ? '201' : '130';
            });
        $resetDb->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(function (callable $callback) use ($resetDb) {
                return $callback($resetDb);
            });

        $fixedTime = new DateTimeImmutable('2026-02-23T13:14:15.987Z', new DateTimeZone('UTC'));

        $service = $this->createService($lookupDb, $resetDb, $fixedTime);
        $response = $service->reset('Bearer gm-token', '7', []);

        self::assertSame(Response::STATUS_OK, $response->code());
        self::assertSame([
            'session_id' => 7,
            'scene_strain' => 0,
            'event_id' => 130,
        ], $response->data());

        self::assertCount(2, $insertCalls);
        self::assertSame('session_state', $insertCalls[0][0]);
        self::assertSame([
            'state_session_id' => 7,
            'state_name' => 'scene_strain',
            'state_value' => '0',
            'state_created' => '2026-02-23 13:14:15.987000',
            'state_updated' => '2026-02-23 13:14:15.987000',
        ], $insertCalls[0][1]);
    }

    public function testResetHandlesInvalidStoredSceneStrainAsZero(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7, tokenId: 99));

        $statement = $this->getMockBuilder(\PDOStatement::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetch'])
            ->getMock();
        $statement->expects(self::once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([
                'state_id' => '17',
                'state_value' => 'not-a-number',
            ]);

        $resetDb = $this->createResetDbMock();
        $resetDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'), [7])
            ->willReturn(new Collection(['session_id' => 7]));
        $resetDb->expects(self::once())
            ->method('runQuery')
            ->with(self::stringContains('FOR UPDATE'), [7, 'scene_strain'])
            ->willReturn($statement);
        $resetDb->expects(self::once())
            ->method('update')
            ->with(
                'session_state',
                [
                    'state_value' => '0',
                    'state_updated' => '2026-02-23 13:14:15.987000',
                ],
                'state_id = ?',
                [17]
            )
            ->willReturn(1);
        $resetDb->expects(self::once())
            ->method('insert')
            ->with(
                'events',
                self::callback(function (array $data): bool {
                    $payload = json_decode($data['event_payload_json'] ?? '', true);

                    return $payload === [
                        'previous_scene_strain' => 0,
                        'scene_strain' => 0,
                    ];
                })
            )
            ->willReturn('131');
        $resetDb->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(function (callable $callback) use ($resetDb) {
                return $callback($resetDb);
            });

        $fixedTime = new DateTimeImmutable('2026-02-23T13:14:15.987Z', new DateTimeZone('UTC'));

        $service = $this->createService($lookupDb, $resetDb, $fixedTime);
        $response = $service->reset('Bearer gm-token', '7', []);

        self::assertSame(Response::STATUS_OK, $response->code());
        self::assertSame([
            'session_id' => 7,
            'scene_strain' => 0,
            'event_id' => 131,
        ], $response->data());
    }

    public function testResetReturnsInternalErrorWhenTransactionFails(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7, tokenId: 99));

        $resetDb = $this->createResetDbMock();
        $resetDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'), [7])
            ->willReturn(new Collection(['session_id' => 7]));
        $resetDb->expects(self::once())
            ->method('transaction')
            ->willThrowException(new \RuntimeException('db fail'));

        $service = $this->createService($lookupDb, $resetDb);
        $response = $service->reset('Bearer gm-token', '7', []);

        self::assertSame(Response::STATUS_INTERNAL_SERVER_ERROR, $response->code());
        self::assertSame(Response::ERROR_INTERNAL, $response->data()['error']['code']);
    }

    private function createService(
        SimplePdo $lookupDb,
        SimplePdo $resetDb,
        ?DateTimeImmutable $fixedTime = null
    ): GmSceneStrainResetService {
        $authGuard = new AuthGuard(new TokenLookup($lookupDb));
        $authorizer = new GmSessionAuthorizer($resetDb, $authGuard, new RequestValidator());
        $clock = $fixedTime !== null ? new FixedClock($fixedTime) : new FixedClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));

        return new GmSceneStrainResetService(
            $resetDb,
            $authorizer,
            new RequestValidator(),
            new DateTimeFormatter($clock)
        );
    }

    private function createLookupDbMock(): SimplePdo
    {
        return $this->getMockBuilder(SimplePdo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchRow'])
            ->getMock();
    }

    private function createResetDbMock(): SimplePdo
    {
        return $this->getMockBuilder(SimplePdo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchRow', 'transaction', 'runQuery', 'update', 'insert'])
            ->getMock();
    }

    private function tokenRow(string $role, int $sessionId, int $tokenId): Collection
    {
        return new Collection([
            'token_id' => $tokenId,
            'token_session_id' => $sessionId,
            'token_role' => $role,
            'token_display_name' => $role === 'gm' ? null : 'Alice',
            'token_hash' => hash('sha256', 'gm-token', true),
            'token_prefix' => 'prefixtoken12',
            'token_revoked' => null,
        ]);
    }
}

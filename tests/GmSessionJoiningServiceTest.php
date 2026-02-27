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
use YZERoller\Api\Service\GmSessionJoiningService;
use YZERoller\Api\Support\DateTimeFormatter;
use YZERoller\Api\Tests\Fixture\FixedClock;
use YZERoller\Api\Validation\RequestValidator;

final class GmSessionJoiningServiceTest extends TestCase
{
    public function testUpdateJoiningReturnsTokenMissingWhenHeaderMissing(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::never())->method('fetchRow');

        $gmDb = $this->createGmDbMock();
        $gmDb->expects(self::never())->method('fetchRow');
        $gmDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $gmDb);
        $response = $service->updateJoining(null, '7', ['joining_enabled' => false]);

        self::assertSame(Response::STATUS_UNAUTHORIZED, $response->code());
        self::assertSame(Response::ERROR_TOKEN_MISSING, $response->data()['error']['code']);
    }

    public function testUpdateJoiningReturnsRoleForbiddenWhenNotGm(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'player', sessionId: 7));

        $gmDb = $this->createGmDbMock();
        $gmDb->expects(self::never())->method('fetchRow');
        $gmDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $gmDb);
        $response = $service->updateJoining('Bearer gm-token', '7', ['joining_enabled' => false]);

        self::assertSame(Response::STATUS_FORBIDDEN, $response->code());
        self::assertSame(Response::ERROR_ROLE_FORBIDDEN, $response->data()['error']['code']);
    }

    public function testUpdateJoiningReturnsValidationErrorForInvalidSessionId(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7));

        $gmDb = $this->createGmDbMock();
        $gmDb->expects(self::never())->method('fetchRow');
        $gmDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $gmDb);
        $response = $service->updateJoining('Bearer gm-token', 'x', ['joining_enabled' => true]);

        self::assertSame(Response::STATUS_UNPROCESSABLE_ENTITY, $response->code());
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $response->data()['error']['code']);
    }

    public function testUpdateJoiningReturnsRoleForbiddenWhenSessionMismatch(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 8));

        $gmDb = $this->createGmDbMock();
        $gmDb->expects(self::never())->method('fetchRow');
        $gmDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $gmDb);
        $response = $service->updateJoining('Bearer gm-token', '7', ['joining_enabled' => true]);

        self::assertSame(Response::STATUS_FORBIDDEN, $response->code());
        self::assertSame(Response::ERROR_ROLE_FORBIDDEN, $response->data()['error']['code']);
    }

    public function testUpdateJoiningReturnsValidationErrorForInvalidPayload(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::never())->method('fetchRow');

        $gmDb = $this->createGmDbMock();
        $gmDb->expects(self::never())->method('fetchRow');
        $gmDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $gmDb);
        $response = $service->updateJoining('Bearer gm-token', '7', ['joining_enabled' => 'false']);

        self::assertSame(Response::STATUS_UNPROCESSABLE_ENTITY, $response->code());
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $response->data()['error']['code']);
    }

    public function testUpdateJoiningReturnsSessionNotFoundWhenSessionMissing(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7));

        $gmDb = $this->createGmDbMock();
        $gmDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'), [7])
            ->willReturn(null);
        $gmDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $gmDb);
        $response = $service->updateJoining('Bearer gm-token', '7', ['joining_enabled' => false]);

        self::assertSame(Response::STATUS_NOT_FOUND, $response->code());
        self::assertSame(Response::ERROR_SESSION_NOT_FOUND, $response->data()['error']['code']);
    }

    public function testUpdateJoiningUpdatesExistingStateInTransaction(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7));

        $statement = $this->getMockBuilder(\PDOStatement::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetch'])
            ->getMock();
        $statement->expects(self::once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['state_id' => '15']);

        $gmDb = $this->createGmDbMock();
        $gmDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'), [7])
            ->willReturn(new Collection(['session_id' => 7]));

        $gmDb->expects(self::once())
            ->method('runQuery')
            ->with(
                self::stringContains('FOR UPDATE'),
                [7, 'joining_enabled']
            )
            ->willReturn($statement);

        $gmDb->expects(self::once())
            ->method('update')
            ->with(
                'session_state',
                [
                    'state_value' => 'false',
                    'state_updated' => '2026-02-23 10:11:12.345000',
                ],
                'state_id = ?',
                [15]
            )
            ->willReturn(1);

        $gmDb->expects(self::never())->method('insert');

        $gmDb->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(function (callable $callback) use ($gmDb) {
                $callback($gmDb);

                return null;
            });

        $fixedTime = new DateTimeImmutable('2026-02-23T10:11:12.345Z', new DateTimeZone('UTC'));

        $service = $this->createService($lookupDb, $gmDb, $fixedTime);
        $response = $service->updateJoining('Bearer gm-token', '7', ['joining_enabled' => false]);

        self::assertSame(Response::STATUS_OK, $response->code());
        $payload = $response->data();
        self::assertIsArray($payload);
        self::assertSame(7, $payload['session_id']);
        self::assertFalse($payload['joining_enabled']);
        self::assertSame('2026-02-23T10:11:12.345Z', $payload['updated_at']);
    }

    public function testUpdateJoiningInsertsStateWhenMissing(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7));

        $statement = $this->getMockBuilder(\PDOStatement::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetch'])
            ->getMock();
        $statement->expects(self::once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);

        $gmDb = $this->createGmDbMock();
        $gmDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'), [7])
            ->willReturn(new Collection(['session_id' => 7]));

        $gmDb->expects(self::once())
            ->method('runQuery')
            ->with(
                self::stringContains('FOR UPDATE'),
                [7, 'joining_enabled']
            )
            ->willReturn($statement);

        $gmDb->expects(self::never())->method('update');

        $gmDb->expects(self::once())
            ->method('insert')
            ->with('session_state', [
                'state_session_id' => 7,
                'state_name' => 'joining_enabled',
                'state_value' => 'true',
                'state_created' => '2026-02-23 10:11:12.345000',
                'state_updated' => '2026-02-23 10:11:12.345000',
            ])
            ->willReturn('99');

        $gmDb->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(function (callable $callback) use ($gmDb) {
                $callback($gmDb);

                return null;
            });

        $fixedTime = new DateTimeImmutable('2026-02-23T10:11:12.345Z', new DateTimeZone('UTC'));

        $service = $this->createService($lookupDb, $gmDb, $fixedTime);
        $response = $service->updateJoining('Bearer gm-token', '7', ['joining_enabled' => true]);

        self::assertSame(Response::STATUS_OK, $response->code());
        $payload = $response->data();
        self::assertIsArray($payload);
        self::assertSame(7, $payload['session_id']);
        self::assertTrue($payload['joining_enabled']);
        self::assertSame('2026-02-23T10:11:12.345Z', $payload['updated_at']);
    }

    public function testUpdateJoiningReturnsInternalErrorWhenTransactionFails(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7));

        $gmDb = $this->createGmDbMock();
        $gmDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'), [7])
            ->willReturn(new Collection(['session_id' => 7]));
        $gmDb->expects(self::once())
            ->method('transaction')
            ->willThrowException(new \RuntimeException('db fail'));

        $service = $this->createService($lookupDb, $gmDb);
        $response = $service->updateJoining('Bearer gm-token', '7', ['joining_enabled' => false]);

        self::assertSame(Response::STATUS_INTERNAL_SERVER_ERROR, $response->code());
        self::assertSame(Response::ERROR_INTERNAL, $response->data()['error']['code']);
    }

    private function createService(
        SimplePdo $lookupDb,
        SimplePdo $gmDb,
        ?DateTimeImmutable $fixedTime = null
    ): GmSessionJoiningService {
        $authGuard = new AuthGuard(new TokenLookup($lookupDb));
        $authorizer = new GmSessionAuthorizer($gmDb, $authGuard, new RequestValidator());
        $clock = $fixedTime !== null ? new FixedClock($fixedTime) : new FixedClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));

        return new GmSessionJoiningService(
            $gmDb,
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

    private function createGmDbMock(): SimplePdo
    {
        return $this->getMockBuilder(SimplePdo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchRow', 'transaction', 'runQuery', 'update', 'insert'])
            ->getMock();
    }

    private function tokenRow(string $role, int $sessionId): Collection
    {
        return new Collection([
            'token_id' => 31,
            'token_session_id' => $sessionId,
            'token_role' => $role,
            'token_display_name' => $role === 'gm' ? null : 'Alice',
            'token_hash' => hash('sha256', 'gm-token', true),
            'token_prefix' => 'prefixtoken12',
            'token_revoked' => null,
        ]);
    }
}


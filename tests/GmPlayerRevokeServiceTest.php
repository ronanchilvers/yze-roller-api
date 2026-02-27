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
use YZERoller\Api\Service\GmPlayerRevokeService;
use YZERoller\Api\Support\DateTimeFormatter;
use YZERoller\Api\Tests\Fixture\FixedClock;
use YZERoller\Api\Validation\RequestValidator;

final class GmPlayerRevokeServiceTest extends TestCase
{
    public function testRevokeReturnsTokenMissingWhenHeaderMissing(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::never())->method('fetchRow');

        $revokeDb = $this->createRevokeDbMock();
        $revokeDb->expects(self::never())->method('fetchRow');
        $revokeDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $revokeDb);
        $response = $service->revoke(null, '7', '31', []);

        self::assertSame(Response::STATUS_UNAUTHORIZED, $response->code());
        self::assertSame(Response::ERROR_TOKEN_MISSING, $response->data()['error']['code']);
    }

    public function testRevokeReturnsRoleForbiddenWhenNotGm(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'player', sessionId: 7, tokenId: 11));

        $revokeDb = $this->createRevokeDbMock();
        $revokeDb->expects(self::never())->method('fetchRow');
        $revokeDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $revokeDb);
        $response = $service->revoke('Bearer gm-token', '7', '31', []);

        self::assertSame(Response::STATUS_FORBIDDEN, $response->code());
        self::assertSame(Response::ERROR_ROLE_FORBIDDEN, $response->data()['error']['code']);
    }

    public function testRevokeReturnsValidationErrorForInvalidPathParams(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::exactly(1))
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7, tokenId: 99));

        $revokeDb = $this->createRevokeDbMock();
        $revokeDb->expects(self::never())->method('fetchRow');
        $revokeDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $revokeDb);
        $response = $service->revoke('Bearer gm-token', 'x', '31', []);
        self::assertSame(Response::STATUS_UNPROCESSABLE_ENTITY, $response->code());
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $response->data()['error']['code']);

        $response2 = $service->revoke('Bearer gm-token', '7', '0', []);
        self::assertSame(Response::STATUS_UNPROCESSABLE_ENTITY, $response2->code());
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $response2->data()['error']['code']);
    }

    public function testRevokeReturnsValidationErrorForNonEmptyBody(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::never())->method('fetchRow');

        $revokeDb = $this->createRevokeDbMock();
        $revokeDb->expects(self::never())->method('fetchRow');
        $revokeDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $revokeDb);
        $response = $service->revoke('Bearer gm-token', '7', '31', ['unexpected' => true]);

        self::assertSame(Response::STATUS_UNPROCESSABLE_ENTITY, $response->code());
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $response->data()['error']['code']);
    }

    public function testRevokeReturnsRoleForbiddenWhenSessionMismatch(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 8, tokenId: 99));

        $revokeDb = $this->createRevokeDbMock();
        $revokeDb->expects(self::never())->method('fetchRow');
        $revokeDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $revokeDb);
        $response = $service->revoke('Bearer gm-token', '7', '31', []);

        self::assertSame(Response::STATUS_FORBIDDEN, $response->code());
        self::assertSame(Response::ERROR_ROLE_FORBIDDEN, $response->data()['error']['code']);
    }

    public function testRevokeReturnsSessionNotFoundWhenSessionMissing(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7, tokenId: 99));

        $revokeDb = $this->createRevokeDbMock();
        $revokeDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'), [7])
            ->willReturn(null);
        $revokeDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $revokeDb);
        $response = $service->revoke('Bearer gm-token', '7', '31', []);

        self::assertSame(Response::STATUS_NOT_FOUND, $response->code());
        self::assertSame(Response::ERROR_SESSION_NOT_FOUND, $response->data()['error']['code']);
    }

    public function testRevokeReturnsNotFoundWhenPlayerTokenMissing(): void
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

        $revokeDb = $this->createRevokeDbMock();
        $revokeDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'), [7])
            ->willReturn(new Collection(['session_id' => 7]));
        $revokeDb->expects(self::once())
            ->method('runQuery')
            ->with(self::stringContains('FOR UPDATE'), [31, 7, 'player'])
            ->willReturn($statement);
        $revokeDb->expects(self::never())->method('update');
        $revokeDb->expects(self::never())->method('insert');
        $revokeDb->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(function (callable $callback) use ($revokeDb) {
                return $callback($revokeDb);
            });

        $service = $this->createService($lookupDb, $revokeDb);
        $response = $service->revoke('Bearer gm-token', '7', '31', []);

        self::assertSame(Response::STATUS_NOT_FOUND, $response->code());
        self::assertSame(Response::ERROR_SESSION_NOT_FOUND, $response->data()['error']['code']);
    }

    public function testRevokeIsIdempotentWhenAlreadyRevoked(): void
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
                'token_id' => 31,
                'token_display_name' => 'Alice',
                'token_revoked' => '2026-02-23 11:59:00.000000',
            ]);

        $revokeDb = $this->createRevokeDbMock();
        $revokeDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'), [7])
            ->willReturn(new Collection(['session_id' => 7]));
        $revokeDb->expects(self::once())
            ->method('runQuery')
            ->with(self::stringContains('FOR UPDATE'), [31, 7, 'player'])
            ->willReturn($statement);
        $revokeDb->expects(self::never())->method('update');
        $revokeDb->expects(self::never())->method('insert');
        $revokeDb->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(function (callable $callback) use ($revokeDb) {
                return $callback($revokeDb);
            });

        $service = $this->createService($lookupDb, $revokeDb);
        $response = $service->revoke('Bearer gm-token', '7', '31', []);

        self::assertSame(Response::STATUS_OK, $response->code());
        self::assertSame([
            'session_id' => 7,
            'token_id' => 31,
            'revoked' => true,
            'event_emitted' => false,
        ], $response->data());
    }

    public function testRevokeUpdatesTokenAndEmitsLeaveEventOnFirstRevoke(): void
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
                'token_id' => 31,
                'token_display_name' => 'Alice',
                'token_revoked' => null,
            ]);

        $revokeDb = $this->createRevokeDbMock();
        $revokeDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'), [7])
            ->willReturn(new Collection(['session_id' => 7]));
        $revokeDb->expects(self::once())
            ->method('runQuery')
            ->with(self::stringContains('FOR UPDATE'), [31, 7, 'player'])
            ->willReturn($statement);
        $revokeDb->expects(self::once())
            ->method('update')
            ->with(
                'session_tokens',
                [
                    'token_revoked' => '2026-02-23 12:34:56.789000',
                    'token_updated' => '2026-02-23 12:34:56.789000',
                ],
                'token_id = ?',
                [31]
            )
            ->willReturn(1);
        $revokeDb->expects(self::once())
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
                    if (($data['event_type'] ?? null) !== 'leave') {
                        return false;
                    }
                    $payload = json_decode($data['event_payload_json'] ?? '', true);
                    return $payload === [
                        'token_id' => 31,
                        'display_name' => 'Alice',
                        'reason' => 'revoked',
                    ];
                })
            )
            ->willReturn('130');
        $revokeDb->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(function (callable $callback) use ($revokeDb) {
                return $callback($revokeDb);
            });

        $fixedTime = new DateTimeImmutable('2026-02-23T12:34:56.789Z', new DateTimeZone('UTC'));

        $service = $this->createService($lookupDb, $revokeDb, $fixedTime);
        $response = $service->revoke('Bearer gm-token', '7', '31', []);

        self::assertSame(Response::STATUS_OK, $response->code());
        self::assertSame([
            'session_id' => 7,
            'token_id' => 31,
            'revoked' => true,
            'event_emitted' => true,
            'event_id' => 130,
        ], $response->data());
    }

    public function testRevokeReturnsInternalErrorWhenTransactionFails(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7, tokenId: 99));

        $revokeDb = $this->createRevokeDbMock();
        $revokeDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'), [7])
            ->willReturn(new Collection(['session_id' => 7]));
        $revokeDb->expects(self::once())
            ->method('transaction')
            ->willThrowException(new \RuntimeException('db fail'));

        $service = $this->createService($lookupDb, $revokeDb);
        $response = $service->revoke('Bearer gm-token', '7', '31', []);

        self::assertSame(Response::STATUS_INTERNAL_SERVER_ERROR, $response->code());
        self::assertSame(Response::ERROR_INTERNAL, $response->data()['error']['code']);
    }

    private function createService(
        SimplePdo $lookupDb,
        SimplePdo $revokeDb,
        ?DateTimeImmutable $fixedTime = null
    ): GmPlayerRevokeService {
        $authGuard = new AuthGuard(new TokenLookup($lookupDb));
        $authorizer = new GmSessionAuthorizer($revokeDb, $authGuard, new RequestValidator());
        $clock = $fixedTime !== null ? new FixedClock($fixedTime) : new FixedClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));

        return new GmPlayerRevokeService(
            $revokeDb,
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

    private function createRevokeDbMock(): SimplePdo
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

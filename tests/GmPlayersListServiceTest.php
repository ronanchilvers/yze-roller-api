<?php

declare(strict_types=1);

namespace YZERoller\Api\Tests;

use flight\database\SimplePdo;
use flight\util\Collection;
use PHPUnit\Framework\TestCase;
use YZERoller\Api\Auth\AuthGuard;
use YZERoller\Api\Auth\TokenLookup;
use YZERoller\Api\Response;
use YZERoller\Api\Service\GmPlayersListService;
use YZERoller\Api\Validation\RequestValidator;

final class GmPlayersListServiceTest extends TestCase
{
    public function testListPlayersReturnsTokenMissingWhenHeaderMissing(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::never())->method('fetchRow');

        $playersDb = $this->createPlayersDbMock();
        $playersDb->expects(self::never())->method('fetchRow');
        $playersDb->expects(self::never())->method('fetchAll');

        $service = $this->createService($lookupDb, $playersDb);
        $response = $service->listPlayers(null, '7');

        self::assertSame(Response::STATUS_UNAUTHORIZED, $response->code());
        self::assertSame(Response::ERROR_TOKEN_MISSING, $response->data()['error']['code']);
    }

    public function testListPlayersReturnsRoleForbiddenWhenNotGm(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'player', sessionId: 7));

        $playersDb = $this->createPlayersDbMock();
        $playersDb->expects(self::never())->method('fetchRow');
        $playersDb->expects(self::never())->method('fetchAll');

        $service = $this->createService($lookupDb, $playersDb);
        $response = $service->listPlayers('Bearer gm-token', '7');

        self::assertSame(Response::STATUS_FORBIDDEN, $response->code());
        self::assertSame(Response::ERROR_ROLE_FORBIDDEN, $response->data()['error']['code']);
    }

    public function testListPlayersReturnsValidationErrorForInvalidSessionId(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7));

        $playersDb = $this->createPlayersDbMock();
        $playersDb->expects(self::never())->method('fetchRow');
        $playersDb->expects(self::never())->method('fetchAll');

        $service = $this->createService($lookupDb, $playersDb);
        $response = $service->listPlayers('Bearer gm-token', 'x');

        self::assertSame(Response::STATUS_UNPROCESSABLE_ENTITY, $response->code());
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $response->data()['error']['code']);
    }

    public function testListPlayersReturnsRoleForbiddenWhenSessionMismatch(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 8));

        $playersDb = $this->createPlayersDbMock();
        $playersDb->expects(self::never())->method('fetchRow');
        $playersDb->expects(self::never())->method('fetchAll');

        $service = $this->createService($lookupDb, $playersDb);
        $response = $service->listPlayers('Bearer gm-token', '7');

        self::assertSame(Response::STATUS_FORBIDDEN, $response->code());
        self::assertSame(Response::ERROR_ROLE_FORBIDDEN, $response->data()['error']['code']);
    }

    public function testListPlayersReturnsSessionNotFoundWhenSessionMissing(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7));

        $playersDb = $this->createPlayersDbMock();
        $playersDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'), [7])
            ->willReturn(null);
        $playersDb->expects(self::never())->method('fetchAll');

        $service = $this->createService($lookupDb, $playersDb);
        $response = $service->listPlayers('Bearer gm-token', '7');

        self::assertSame(Response::STATUS_NOT_FOUND, $response->code());
        self::assertSame(Response::ERROR_SESSION_NOT_FOUND, $response->data()['error']['code']);
    }

    public function testListPlayersReturnsMappedPlayersPayload(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7));

        $playersDb = $this->createPlayersDbMock();
        $playersDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'), [7])
            ->willReturn(new Collection(['session_id' => 7]));

        $playersDb->expects(self::once())
            ->method('fetchAll')
            ->with(
                self::stringContains('FROM session_tokens'),
                [7, 'player']
            )
            ->willReturn([
                new Collection([
                    'token_id' => 31,
                    'token_display_name' => 'Alice',
                    'token_role' => 'player',
                    'token_revoked' => null,
                    'token_created' => '2026-02-22 20:30:10.000000',
                    'token_last_seen' => '2026-02-22 20:31:20.500000',
                ]),
                new Collection([
                    'token_id' => 44,
                    'token_display_name' => 'Bob',
                    'token_role' => 'player',
                    'token_revoked' => '2026-02-22 20:40:00.000000',
                    'token_created' => '2026-02-22 20:32:00.000000',
                    'token_last_seen' => null,
                ]),
            ]);

        $service = $this->createService($lookupDb, $playersDb);
        $response = $service->listPlayers('Bearer gm-token', '7');

        self::assertSame(Response::STATUS_OK, $response->code());
        $payload = $response->data();
        self::assertIsArray($payload);
        self::assertSame(7, $payload['session_id']);
        self::assertCount(2, $payload['players']);

        self::assertSame([
            'token_id' => 31,
            'display_name' => 'Alice',
            'role' => 'player',
            'revoked' => false,
            'created_at' => '2026-02-22T20:30:10.000Z',
            'last_seen_at' => '2026-02-22T20:31:20.500Z',
            'revoked_at' => null,
        ], $payload['players'][0]);

        self::assertSame([
            'token_id' => 44,
            'display_name' => 'Bob',
            'role' => 'player',
            'revoked' => true,
            'created_at' => '2026-02-22T20:32:00.000Z',
            'last_seen_at' => null,
            'revoked_at' => '2026-02-22T20:40:00.000Z',
        ], $payload['players'][1]);
    }

    public function testListPlayersReturnsInternalErrorWhenDbFails(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7));

        $playersDb = $this->createPlayersDbMock();
        $playersDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'), [7])
            ->willReturn(new Collection(['session_id' => 7]));
        $playersDb->expects(self::once())
            ->method('fetchAll')
            ->willThrowException(new \RuntimeException('db fail'));

        $service = $this->createService($lookupDb, $playersDb);
        $response = $service->listPlayers('Bearer gm-token', '7');

        self::assertSame(Response::STATUS_INTERNAL_SERVER_ERROR, $response->code());
        self::assertSame(Response::ERROR_INTERNAL, $response->data()['error']['code']);
    }

    private function createService(SimplePdo $lookupDb, SimplePdo $playersDb): GmPlayersListService
    {
        $authGuard = new AuthGuard(new TokenLookup($lookupDb));

        return new GmPlayersListService(
            $playersDb,
            $authGuard,
            new RequestValidator()
        );
    }

    private function createLookupDbMock(): SimplePdo
    {
        return $this->getMockBuilder(SimplePdo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchRow'])
            ->getMock();
    }

    private function createPlayersDbMock(): SimplePdo
    {
        return $this->getMockBuilder(SimplePdo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchRow', 'fetchAll'])
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


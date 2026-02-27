<?php

declare(strict_types=1);

namespace YZERoller\Api\Tests;

use flight\database\SimplePdo;
use flight\util\Collection;
use PHPUnit\Framework\TestCase;
use YZERoller\Api\Auth\AuthGuard;
use YZERoller\Api\Auth\GmSessionAuthorizer;
use YZERoller\Api\Auth\TokenLookup;
use YZERoller\Api\Response;
use YZERoller\Api\Validation\RequestValidator;

final class GmSessionAuthorizerTest extends TestCase
{
    private function makeTokenRow(string $role, int $sessionId, int $tokenId = 1): Collection
    {
        return new Collection([
            'token_id' => $tokenId,
            'token_session_id' => $sessionId,
            'token_role' => $role,
            'token_display_name' => null,
            'token_hash' => 'hash',
            'token_prefix' => 'prefix',
            'token_revoked' => null,
            'token_created' => '2024-01-01 00:00:00',
            'token_updated' => null,
            'token_last_seen' => null,
        ]);
    }

    private function createLookupDbMock(): SimplePdo
    {
        return $this->getMockBuilder(SimplePdo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchRow'])
            ->getMock();
    }

    private function createSessionDbMock(): SimplePdo
    {
        return $this->getMockBuilder(SimplePdo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchRow'])
            ->getMock();
    }

    private function createAuthorizer(SimplePdo $lookupDb, SimplePdo $sessionDb): GmSessionAuthorizer
    {
        $authGuard = new AuthGuard(new TokenLookup($lookupDb));

        return new GmSessionAuthorizer($sessionDb, $authGuard, new RequestValidator());
    }

    public function testReturnsErrorWhenTokenMissing(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::never())->method('fetchRow');

        $sessionDb = $this->createSessionDbMock();
        $sessionDb->expects(self::never())->method('fetchRow');

        $authorizer = $this->createAuthorizer($lookupDb, $sessionDb);
        $result = $authorizer->authorize(null, '7');

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(Response::STATUS_UNAUTHORIZED, $result->code());
    }

    public function testReturnsErrorWhenNotGm(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->willReturn($this->makeTokenRow('player', 7));

        $sessionDb = $this->createSessionDbMock();
        $sessionDb->expects(self::never())->method('fetchRow');

        $authorizer = $this->createAuthorizer($lookupDb, $sessionDb);
        $result = $authorizer->authorize('Bearer validtoken', '7');

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(Response::STATUS_FORBIDDEN, $result->code());
    }

    public function testReturnsErrorWhenSessionIdInvalid(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->willReturn($this->makeTokenRow('gm', 7));

        $sessionDb = $this->createSessionDbMock();
        $sessionDb->expects(self::never())->method('fetchRow');

        $authorizer = $this->createAuthorizer($lookupDb, $sessionDb);
        $result = $authorizer->authorize('Bearer validtoken', 'notanid');

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(Response::STATUS_UNPROCESSABLE_ENTITY, $result->code());
    }

    public function testReturnsErrorWhenSessionMismatch(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->willReturn($this->makeTokenRow('gm', 99, 1));

        $sessionDb = $this->createSessionDbMock();
        $sessionDb->expects(self::never())->method('fetchRow');

        $authorizer = $this->createAuthorizer($lookupDb, $sessionDb);
        $result = $authorizer->authorize('Bearer validtoken', '7');

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(Response::STATUS_FORBIDDEN, $result->code());
    }

    public function testReturnsErrorWhenActorTokenIdIsZero(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->willReturn(new Collection([
                'token_id' => 0,
                'token_session_id' => 7,
                'token_role' => 'gm',
                'token_display_name' => null,
                'token_hash' => 'hash',
                'token_prefix' => 'prefix',
                'token_revoked' => null,
                'token_created' => '2024-01-01 00:00:00',
                'token_updated' => null,
                'token_last_seen' => null,
            ]));

        $sessionDb = $this->createSessionDbMock();
        $sessionDb->expects(self::never())->method('fetchRow');

        $authorizer = $this->createAuthorizer($lookupDb, $sessionDb);
        $result = $authorizer->authorize('Bearer validtoken', '7');

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(Response::STATUS_UNAUTHORIZED, $result->code());
    }

    public function testReturnsErrorWhenSessionNotFound(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->willReturn($this->makeTokenRow('gm', 7, 1));

        $sessionDb = $this->createSessionDbMock();
        $sessionDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'))
            ->willReturn(null);

        $authorizer = $this->createAuthorizer($lookupDb, $sessionDb);
        $result = $authorizer->authorize('Bearer validtoken', '7');

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(Response::STATUS_NOT_FOUND, $result->code());
    }

    public function testReturnsAuthResultOnSuccess(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->willReturn($this->makeTokenRow('gm', 7, 3));

        $sessionDb = $this->createSessionDbMock();
        $sessionDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'))
            ->willReturn(new Collection(['session_id' => 7]));

        $authorizer = $this->createAuthorizer($lookupDb, $sessionDb);
        $result = $authorizer->authorize('Bearer validtoken', '7');

        self::assertIsArray($result);
        self::assertSame(7, $result['sessionId']);
        self::assertSame(3, $result['actorTokenId']);
        self::assertIsArray($result['sessionTokenRow']);
    }
}

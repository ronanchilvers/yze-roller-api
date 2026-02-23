<?php

declare(strict_types=1);

namespace YZERoller\Api\Tests;

use flight\database\SimplePdo;
use flight\util\Collection;
use PHPUnit\Framework\TestCase;
use YZERoller\Api\Auth\AuthGuard;
use YZERoller\Api\Auth\TokenLookup;
use YZERoller\Api\Response;

final class AuthGuardTest extends TestCase
{
    public function testRequireJoinTokenReturnsTokenMissingForMissingHeader(): void
    {
        $db = $this->createSimplePdoMock();
        $db->expects(self::never())->method('fetchRow');
        $guard = $this->createGuard($db);

        $result = $guard->requireJoinToken(null);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(Response::STATUS_UNAUTHORIZED, $result->code());
        self::assertSame(
            Response::ERROR_TOKEN_MISSING,
            $result->data()['error']['code']
        );
    }

    public function testRequireJoinTokenReturnsTokenInvalidForMalformedHeader(): void
    {
        $db = $this->createSimplePdoMock();
        $db->expects(self::never())->method('fetchRow');
        $guard = $this->createGuard($db);

        $result = $guard->requireJoinToken('Basic abc');

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(Response::STATUS_UNAUTHORIZED, $result->code());
        self::assertSame(
            Response::ERROR_TOKEN_INVALID,
            $result->data()['error']['code']
        );
    }

    public function testRequireJoinTokenReturnsTokenInvalidWhenLookupMisses(): void
    {
        $db = $this->createSimplePdoMock();
        $db->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_join_tokens'))
            ->willReturn(null);
        $guard = $this->createGuard($db);
        $result = $guard->requireJoinToken('Bearer missing-token');

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(Response::STATUS_UNAUTHORIZED, $result->code());
        self::assertSame(
            Response::ERROR_TOKEN_INVALID,
            $result->data()['error']['code']
        );
    }

    public function testRequireJoinTokenReturnsJoinTokenRevokedWhenRevoked(): void
    {
        $db = $this->createSimplePdoMock();
        $db->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_join_tokens'))
            ->willReturn(new Collection([
                'join_token_id' => 1,
                'join_token_session_id' => 7,
                'join_token_hash' => hash('sha256', 'revoked-token', true),
                'join_token_prefix' => 'abc123def456',
                'join_token_revoked' => '2026-02-22 11:00:00.000000',
                'join_token_created' => '2026-02-22 10:00:00.000000',
                'join_token_updated' => '2026-02-22 11:00:00.000000',
                'join_token_last_used' => null,
            ]));
        $guard = $this->createGuard($db);
        $result = $guard->requireJoinToken('Bearer revoked-token');

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(Response::STATUS_FORBIDDEN, $result->code());
        self::assertSame(
            Response::ERROR_JOIN_TOKEN_REVOKED,
            $result->data()['error']['code']
        );
    }

    public function testRequireJoinTokenReturnsTokenRowWhenValid(): void
    {
        $db = $this->createSimplePdoMock();
        $db->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_join_tokens'))
            ->willReturn(new Collection([
                'join_token_id' => 7,
                'join_token_session_id' => 2,
                'join_token_hash' => hash('sha256', 'valid-token', true),
                'join_token_prefix' => 'validprefix12',
                'join_token_revoked' => null,
                'join_token_created' => '2026-02-22 10:00:00.000000',
                'join_token_updated' => '2026-02-22 10:00:00.000000',
                'join_token_last_used' => null,
            ]));
        $guard = $this->createGuard($db);
        $result = $guard->requireJoinToken('Bearer valid-token');

        self::assertIsArray($result);
        self::assertSame(7, $result['join_token_id']);
        self::assertFalse($result['is_revoked']);
    }

    public function testRequireSessionTokenReturnsTokenRevokedWhenRevoked(): void
    {
        $db = $this->createSimplePdoMock();
        $db->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn(new Collection([
                'token_id' => 11,
                'token_session_id' => 3,
                'token_role' => 'player',
                'token_display_name' => 'Bob',
                'token_hash' => hash('sha256', 'revoked-session', true),
                'token_prefix' => 'revokedtoken',
                'token_revoked' => '2026-02-22 11:00:00.000000',
                'token_created' => '2026-02-22 10:00:00.000000',
                'token_updated' => '2026-02-22 11:00:00.000000',
                'token_last_seen' => null,
            ]));
        $guard = $this->createGuard($db);
        $result = $guard->requireSessionToken('Bearer revoked-session');

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(Response::STATUS_FORBIDDEN, $result->code());
        self::assertSame(
            Response::ERROR_TOKEN_REVOKED,
            $result->data()['error']['code']
        );
    }

    public function testRequireSessionTokenReturnsTokenRowWhenValid(): void
    {
        $db = $this->createSimplePdoMock();
        $db->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn(new Collection([
                'token_id' => 31,
                'token_session_id' => 7,
                'token_role' => 'player',
                'token_display_name' => 'Alice',
                'token_hash' => hash('sha256', 'session-token', true),
                'token_prefix' => 'prefixtoken12',
                'token_revoked' => null,
                'token_created' => '2026-02-22 10:00:00.000000',
                'token_updated' => '2026-02-22 10:00:00.000000',
                'token_last_seen' => null,
            ]));
        $guard = $this->createGuard($db);
        $result = $guard->requireSessionToken('Bearer session-token');

        self::assertIsArray($result);
        self::assertSame(31, $result['token_id']);
        self::assertFalse($result['is_revoked']);
    }

    public function testRequireGmRoleReturnsNullForGmAndForbiddenForNonGm(): void
    {
        $db = $this->createSimplePdoMock();
        $db->expects(self::never())->method('fetchRow');
        $guard = $this->createGuard($db);

        self::assertNull($guard->requireGmRole(['token_role' => 'gm']));

        $forbidden = $guard->requireGmRole(['token_role' => 'player']);

        self::assertInstanceOf(Response::class, $forbidden);
        self::assertSame(Response::STATUS_FORBIDDEN, $forbidden->code());
        self::assertSame(
            Response::ERROR_ROLE_FORBIDDEN,
            $forbidden->data()['error']['code']
        );
    }

    private function createSimplePdoMock(): SimplePdo
    {
        return $this->getMockBuilder(SimplePdo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchRow'])
            ->getMock();
    }

    private function createGuard(SimplePdo $db): AuthGuard
    {
        return new AuthGuard(new TokenLookup($db));
    }
}

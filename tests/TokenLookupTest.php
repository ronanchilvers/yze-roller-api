<?php

declare(strict_types=1);

namespace YZERoller\Api\Tests;

use flight\database\SimplePdo;
use flight\util\Collection;
use PHPUnit\Framework\TestCase;
use YZERoller\Api\Auth\TokenLookup;

final class TokenLookupTest extends TestCase
{
    public function testFindJoinTokenReturnsNullWhenNotFound(): void
    {
        $db = $this->mockDbWithRow(null);
        $lookup = new TokenLookup($db);

        self::assertNull($lookup->findJoinTokenByOpaqueToken('opaque-token'));
    }

    public function testFindJoinTokenReturnsRowAndRevokedFlag(): void
    {
        $db = $this->mockDbWithRow(
            new Collection([
                'join_token_id' => 10,
                'join_token_session_id' => 20,
                'join_token_hash' => hash('sha256', 'opaque-token', true),
                'join_token_prefix' => 'abc123def456',
                'join_token_revoked' => null,
                'join_token_created' => '2026-02-22 10:00:00.000000',
                'join_token_updated' => '2026-02-22 10:00:00.000000',
                'join_token_last_used' => null,
            ])
        );
        $lookup = new TokenLookup($db);

        $result = $lookup->findJoinTokenByOpaqueToken('opaque-token');

        self::assertIsArray($result);
        self::assertSame(10, $result['join_token_id']);
        self::assertFalse($result['is_revoked']);
    }

    public function testFindSessionTokenReturnsRowAndRevokedFlag(): void
    {
        $db = $this->mockDbWithRow(
            new Collection([
                'token_id' => 31,
                'token_session_id' => 7,
                'token_role' => 'player',
                'token_display_name' => 'Alice',
                'token_hash' => hash('sha256', 'session-token', true),
                'token_prefix' => 'prefixtoken12',
                'token_revoked' => '2026-02-22 11:00:00.000000',
                'token_created' => '2026-02-22 10:00:00.000000',
                'token_updated' => '2026-02-22 11:00:00.000000',
                'token_last_seen' => null,
            ])
        );
        $lookup = new TokenLookup($db);

        $result = $lookup->findSessionTokenByOpaqueToken('session-token');

        self::assertIsArray($result);
        self::assertSame('player', $result['token_role']);
        self::assertTrue($result['is_revoked']);
    }

    public function testLookupUsesRawBinarySha256HashParam(): void
    {
        $db = $this->getMockBuilder(SimplePdo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchRow'])
            ->getMock();

        $db->expects(self::once())
            ->method('fetchRow')
            ->with(
                self::stringContains('FROM session_tokens'),
                self::callback(function (array $params): bool {
                    if (count($params) !== 1) {
                        return false;
                    }

                    return $params[0] === hash('sha256', 'session-token', true)
                        && strlen($params[0]) === 32;
                })
            )
            ->willReturn(null);

        $lookup = new TokenLookup($db);
        $lookup->findSessionTokenByOpaqueToken('session-token');
    }

    private function mockDbWithRow(?Collection $row): SimplePdo
    {
        $db = $this->getMockBuilder(SimplePdo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchRow'])
            ->getMock();

        $db->expects(self::once())
            ->method('fetchRow')
            ->willReturn($row);

        return $db;
    }
}

<?php

declare(strict_types=1);

namespace YZERoller\Api\Tests;

use DateTimeImmutable;
use DateTimeZone;
use flight\database\SimplePdo;
use flight\util\Collection;
use PHPUnit\Framework\TestCase;
use YZERoller\Api\Auth\AuthGuard;
use YZERoller\Api\Auth\GmSessionAuthorizer;
use YZERoller\Api\Auth\TokenLookup;
use YZERoller\Api\Response;
use YZERoller\Api\Service\GmJoinLinkRotateService;
use YZERoller\Api\Support\DateTimeFormatter;
use YZERoller\Api\Support\JoinLinkBuilder;
use YZERoller\Api\Tests\Fixture\FixedClock;
use YZERoller\Api\Tests\Fixture\FixedTokenGenerator;
use YZERoller\Api\Validation\RequestValidator;

final class GmJoinLinkRotateServiceTest extends TestCase
{
    public function testRotateReturnsTokenMissingWhenHeaderMissing(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::never())->method('fetchRow');

        $rotateDb = $this->createRotateDbMock();
        $rotateDb->expects(self::never())->method('fetchRow');
        $rotateDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $rotateDb);
        $response = $service->rotate(null, '7');

        self::assertSame(Response::STATUS_UNAUTHORIZED, $response->code());
        self::assertSame(Response::ERROR_TOKEN_MISSING, $response->data()['error']['code']);
    }

    public function testRotateReturnsRoleForbiddenWhenNotGm(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'player', sessionId: 7));

        $rotateDb = $this->createRotateDbMock();
        $rotateDb->expects(self::never())->method('fetchRow');
        $rotateDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $rotateDb);
        $response = $service->rotate('Bearer gm-token', '7');

        self::assertSame(Response::STATUS_FORBIDDEN, $response->code());
        self::assertSame(Response::ERROR_ROLE_FORBIDDEN, $response->data()['error']['code']);
    }

    public function testRotateReturnsValidationErrorForInvalidSessionId(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7));

        $rotateDb = $this->createRotateDbMock();
        $rotateDb->expects(self::never())->method('fetchRow');
        $rotateDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $rotateDb);
        $response = $service->rotate('Bearer gm-token', 'x');

        self::assertSame(Response::STATUS_UNPROCESSABLE_ENTITY, $response->code());
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $response->data()['error']['code']);
    }

    public function testRotateReturnsRoleForbiddenWhenSessionMismatch(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 8));

        $rotateDb = $this->createRotateDbMock();
        $rotateDb->expects(self::never())->method('fetchRow');
        $rotateDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $rotateDb);
        $response = $service->rotate('Bearer gm-token', '7');

        self::assertSame(Response::STATUS_FORBIDDEN, $response->code());
        self::assertSame(Response::ERROR_ROLE_FORBIDDEN, $response->data()['error']['code']);
    }

    public function testRotateReturnsSessionNotFoundWhenSessionMissing(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7));

        $rotateDb = $this->createRotateDbMock();
        $rotateDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'), [7])
            ->willReturn(null);
        $rotateDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $rotateDb);
        $response = $service->rotate('Bearer gm-token', '7');

        self::assertSame(Response::STATUS_NOT_FOUND, $response->code());
        self::assertSame(Response::ERROR_SESSION_NOT_FOUND, $response->data()['error']['code']);
    }

    public function testRotateRevokesActiveJoinTokensAndReturnsNewJoinLink(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7));

        $rotateDb = $this->createRotateDbMock();
        $rotateDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'), [7])
            ->willReturn(new Collection(['session_id' => 7]));

        $joinToken = 'token12345678ABCDEFGHIJKL';

        $rotateDb->expects(self::once())
            ->method('update')
            ->with(
                'session_join_tokens',
                [
                    'join_token_revoked' => '2026-02-23 12:34:56.789000',
                    'join_token_updated' => '2026-02-23 12:34:56.789000',
                ],
                'join_token_session_id = ? AND join_token_revoked IS NULL',
                [7]
            )
            ->willReturn(2);

        $rotateDb->expects(self::once())
            ->method('insert')
            ->with(
                'session_join_tokens',
                self::callback(function (array $data) use ($joinToken): bool {
                    return ($data['join_token_session_id'] ?? null) === 7
                        && ($data['join_token_prefix'] ?? null) === substr($joinToken, 0, 12)
                        && is_string($data['join_token_hash'] ?? null)
                        && strlen($data['join_token_hash']) === 32
                        && ($data['join_token_created'] ?? null) === '2026-02-23 12:34:56.789000'
                        && ($data['join_token_updated'] ?? null) === '2026-02-23 12:34:56.789000'
                        && array_key_exists('join_token_revoked', $data)
                        && $data['join_token_revoked'] === null
                        && array_key_exists('join_token_last_used', $data)
                        && $data['join_token_last_used'] === null;
                })
            )
            ->willReturn('45');

        $rotateDb->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(function (callable $callback) use ($rotateDb) {
                $callback($rotateDb);

                return null;
            });

        $fixedTime = new DateTimeImmutable('2026-02-23T12:34:56.789Z', new DateTimeZone('UTC'));

        $service = $this->createService($lookupDb, $rotateDb, $joinToken, $fixedTime);
        $response = $service->rotate('Bearer gm-token', '7');

        self::assertSame(Response::STATUS_OK, $response->code());
        $payload = $response->data();
        self::assertIsArray($payload);
        self::assertSame(7, $payload['session_id']);
        self::assertSame('https://example.com/join#join=' . $joinToken, $payload['join_link']);
        self::assertSame('2026-02-23T12:34:56.789Z', $payload['rotated_at']);
    }

    public function testRotateReturnsInternalErrorWhenTransactionFails(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn($this->tokenRow(role: 'gm', sessionId: 7));

        $rotateDb = $this->createRotateDbMock();
        $rotateDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM sessions'), [7])
            ->willReturn(new Collection(['session_id' => 7]));
        $rotateDb->expects(self::once())
            ->method('transaction')
            ->willThrowException(new \RuntimeException('db fail'));

        $service = $this->createService($lookupDb, $rotateDb);
        $response = $service->rotate('Bearer gm-token', '7');

        self::assertSame(Response::STATUS_INTERNAL_SERVER_ERROR, $response->code());
        self::assertSame(Response::ERROR_INTERNAL, $response->data()['error']['code']);
    }

    private function createService(
        SimplePdo $lookupDb,
        SimplePdo $rotateDb,
        ?string $fixedToken = null,
        ?DateTimeImmutable $fixedTime = null
    ): GmJoinLinkRotateService {
        $authGuard = new AuthGuard(new TokenLookup($lookupDb));
        $authorizer = new GmSessionAuthorizer($rotateDb, $authGuard, new RequestValidator());
        $tokenGenerator = $fixedToken !== null ? new FixedTokenGenerator($fixedToken) : new FixedTokenGenerator('default-token-for-test');
        $clock = $fixedTime !== null ? new FixedClock($fixedTime) : new FixedClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));

        return new GmJoinLinkRotateService(
            $rotateDb,
            $authorizer,
            $tokenGenerator,
            new JoinLinkBuilder('https://example.com'),
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

    private function createRotateDbMock(): SimplePdo
    {
        return $this->getMockBuilder(SimplePdo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchRow', 'transaction', 'update', 'insert'])
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


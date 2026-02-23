<?php

declare(strict_types=1);

namespace YZERoller\Api\Tests;

use DateTimeImmutable;
use DateTimeZone;
use flight\database\SimplePdo;
use flight\util\Collection;
use PHPUnit\Framework\TestCase;
use YZERoller\Api\Auth\AuthGuard;
use YZERoller\Api\Auth\TokenLookup;
use YZERoller\Api\Response;
use YZERoller\Api\Service\JoinService;
use YZERoller\Api\Validation\RequestValidator;

final class JoinServiceTest extends TestCase
{
    public function testJoinReturnsTokenMissingWhenHeaderMissing(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::never())->method('fetchRow');

        $joinDb = $this->createJoinDbMock();
        $joinDb->expects(self::never())->method('fetchRow');
        $joinDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $joinDb);

        $response = $service->join(null, ['display_name' => 'Alice']);

        self::assertSame(Response::STATUS_UNAUTHORIZED, $response->code());
        self::assertSame(Response::ERROR_TOKEN_MISSING, $response->data()['error']['code']);
    }

    public function testJoinReturnsValidationErrorForInvalidDisplayName(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_join_tokens'))
            ->willReturn(new Collection([
                'join_token_id' => 1,
                'join_token_session_id' => 7,
                'join_token_hash' => hash('sha256', 'join-token', true),
                'join_token_prefix' => 'joinprefix12',
                'join_token_revoked' => null,
                'join_token_created' => '2026-02-22 10:00:00.000000',
                'join_token_updated' => '2026-02-22 10:00:00.000000',
                'join_token_last_used' => null,
            ]));

        $joinDb = $this->createJoinDbMock();
        $joinDb->expects(self::never())->method('fetchRow');
        $joinDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $joinDb);

        $response = $service->join('Bearer join-token', ['display_name' => " \n "]);

        self::assertSame(Response::STATUS_UNPROCESSABLE_ENTITY, $response->code());
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $response->data()['error']['code']);
    }

    public function testJoinReturnsJoinDisabledWhenStateFalse(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_join_tokens'))
            ->willReturn(new Collection([
                'join_token_id' => 2,
                'join_token_session_id' => 9,
                'join_token_hash' => hash('sha256', 'join-token', true),
                'join_token_prefix' => 'joinprefix12',
                'join_token_revoked' => null,
                'join_token_created' => '2026-02-22 10:00:00.000000',
                'join_token_updated' => '2026-02-22 10:00:00.000000',
                'join_token_last_used' => null,
            ]));

        $joinDb = $this->createJoinDbMock();
        $joinDb->expects(self::once())
            ->method('fetchRow')
            ->with(
                self::stringContains('FROM session_state'),
                [9, 'joining_enabled']
            )
            ->willReturn(new Collection(['state_value' => 'false']));
        $joinDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $joinDb);

        $response = $service->join('Bearer join-token', ['display_name' => 'Alice']);

        self::assertSame(Response::STATUS_FORBIDDEN, $response->code());
        self::assertSame(Response::ERROR_JOIN_DISABLED, $response->data()['error']['code']);
    }

    public function testJoinReturnsCreatedPayloadOnSuccess(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_join_tokens'))
            ->willReturn(new Collection([
                'join_token_id' => 8,
                'join_token_session_id' => 7,
                'join_token_hash' => hash('sha256', 'join-token', true),
                'join_token_prefix' => 'joinprefix12',
                'join_token_revoked' => null,
                'join_token_created' => '2026-02-22 10:00:00.000000',
                'join_token_updated' => '2026-02-22 10:00:00.000000',
                'join_token_last_used' => null,
            ]));

        $joinDb = $this->createJoinDbMock();
        $joinDb->expects(self::once())
            ->method('fetchRow')
            ->with(
                self::stringContains('FROM session_state'),
                [7, 'joining_enabled']
            )
            ->willReturn(new Collection(['state_value' => 'true']));

        $insertCalls = [];
        $joinDb->expects(self::exactly(2))
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$insertCalls): string {
                $insertCalls[] = [$table, $data];
                if ($table === 'session_tokens') {
                    return '31';
                }

                return '101';
            });

        $joinDb->expects(self::once())
            ->method('update')
            ->with(
                'session_join_tokens',
                self::callback(fn(array $data): bool => isset($data['join_token_last_used'])),
                'join_token_id = ?',
                [8]
            )
            ->willReturn(1);

        $joinDb->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(function (callable $callback) use ($joinDb) {
                return $callback($joinDb);
            });

        $tokens = ['playerOpaqueToken789'];
        $tokenGenerator = static function () use (&$tokens): string {
            return array_shift($tokens);
        };
        $nowProvider = static function (): DateTimeImmutable {
            return new DateTimeImmutable('2026-02-22T20:31:00.000Z', new DateTimeZone('UTC'));
        };

        $service = $this->createService($lookupDb, $joinDb, $tokenGenerator, $nowProvider);

        $response = $service->join('Bearer join-token', ['display_name' => 'Alice']);

        self::assertSame(Response::STATUS_CREATED, $response->code());
        $payload = $response->data();
        self::assertIsArray($payload);
        self::assertSame(7, $payload['session_id']);
        self::assertSame('playerOpaqueToken789', $payload['player_token']);
        self::assertSame(31, $payload['player']['token_id']);
        self::assertSame('Alice', $payload['player']['display_name']);
        self::assertSame('player', $payload['player']['role']);

        self::assertCount(2, $insertCalls);
        self::assertSame('session_tokens', $insertCalls[0][0]);
        self::assertSame(7, $insertCalls[0][1]['token_session_id']);
        self::assertSame('player', $insertCalls[0][1]['token_role']);
        self::assertSame('Alice', $insertCalls[0][1]['token_display_name']);
        self::assertSame('playerOpaque', $insertCalls[0][1]['token_prefix']);
        self::assertSame(32, strlen($insertCalls[0][1]['token_hash']));

        self::assertSame('events', $insertCalls[1][0]);
        self::assertSame(7, $insertCalls[1][1]['event_session_id']);
        self::assertSame(31, $insertCalls[1][1]['event_actor_token_id']);
        self::assertSame('join', $insertCalls[1][1]['event_type']);

        $eventPayload = json_decode($insertCalls[1][1]['event_payload_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['token_id' => 31, 'display_name' => 'Alice'], $eventPayload);
    }

    public function testJoinReturnsInternalErrorWhenTransactionFails(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_join_tokens'))
            ->willReturn(new Collection([
                'join_token_id' => 8,
                'join_token_session_id' => 7,
                'join_token_hash' => hash('sha256', 'join-token', true),
                'join_token_prefix' => 'joinprefix12',
                'join_token_revoked' => null,
                'join_token_created' => '2026-02-22 10:00:00.000000',
                'join_token_updated' => '2026-02-22 10:00:00.000000',
                'join_token_last_used' => null,
            ]));

        $joinDb = $this->createJoinDbMock();
        $joinDb->expects(self::once())
            ->method('fetchRow')
            ->with(
                self::stringContains('FROM session_state'),
                [7, 'joining_enabled']
            )
            ->willReturn(new Collection(['state_value' => 'true']));

        $joinDb->expects(self::once())
            ->method('transaction')
            ->willThrowException(new \RuntimeException('db fail'));

        $service = $this->createService($lookupDb, $joinDb);

        $response = $service->join('Bearer join-token', ['display_name' => 'Alice']);

        self::assertSame(Response::STATUS_INTERNAL_SERVER_ERROR, $response->code());
        self::assertSame(Response::ERROR_INTERNAL, $response->data()['error']['code']);
    }

    private function createService(
        SimplePdo $lookupDb,
        SimplePdo $joinDb,
        ?callable $tokenGenerator = null,
        ?callable $nowProvider = null
    ): JoinService {
        $authGuard = new AuthGuard(new TokenLookup($lookupDb));

        return new JoinService(
            $joinDb,
            $authGuard,
            new RequestValidator(),
            $tokenGenerator,
            $nowProvider
        );
    }

    private function createLookupDbMock(): SimplePdo
    {
        return $this->getMockBuilder(SimplePdo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchRow'])
            ->getMock();
    }

    private function createJoinDbMock(): SimplePdo
    {
        return $this->getMockBuilder(SimplePdo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchRow', 'transaction', 'insert', 'update'])
            ->getMock();
    }
}

<?php

declare(strict_types=1);

namespace YZERoller\Api\Tests;

use DateTimeImmutable;
use DateTimeZone;
use flight\database\SimplePdo;
use flight\util\Collection;
use PHPUnit\Framework\TestCase;
use YZERoller\Api\Response;
use YZERoller\Api\Service\SessionBootstrapService;
use YZERoller\Api\Support\DateTimeFormatter;
use YZERoller\Api\Support\JoinLinkBuilder;
use YZERoller\Api\Tests\Fixture\FixedClock;
use YZERoller\Api\Tests\Fixture\FixedTokenGenerator;
use YZERoller\Api\Validation\RequestValidator;

final class SessionBootstrapServiceTest extends TestCase
{
    public function testCreateSessionReturnsValidationErrorForInvalidName(): void
    {
        $db = $this->createSimplePdoMock();
        $db->expects(self::never())->method('transaction');

        $service = new SessionBootstrapService(
            $db,
            new RequestValidator(),
            new FixedTokenGenerator('dummy'),
            new JoinLinkBuilder('https://example.com'),
            new DateTimeFormatter(new FixedClock(new DateTimeImmutable('now', new DateTimeZone('UTC'))))
        );

        $response = $service->createSession(['session_name' => '   ']);

        self::assertSame(Response::STATUS_UNPROCESSABLE_ENTITY, $response->code());
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $response->data()['error']['code']);
    }

    public function testCreateSessionReturnsCreatedPayloadOnSuccess(): void
    {
        $db = $this->createSimplePdoMock();

        $insertCalls = [];
        $db->expects(self::exactly(4))
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$insertCalls): string {
                $insertCalls[] = [$table, $data];
                if ($table === 'sessions') {
                    return '7';
                }

                return '1';
            });

        $db->expects(self::once())
            ->method('fetchRow')
            ->with(
                self::stringContains('SELECT session_created FROM sessions'),
                [7]
            )
            ->willReturn(new Collection([
                'session_created' => '2026-02-22 20:30:00.123456',
            ]));

        $db->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(function (callable $callback) use ($db) {
                return $callback($db);
            });

        $service = new SessionBootstrapService(
            $db,
            new RequestValidator(),
            new FixedTokenGenerator('gmOpaqueToken123', 'joinOpaqueToken456'),
            new JoinLinkBuilder('https://example.com'),
            new DateTimeFormatter(new FixedClock(new DateTimeImmutable('2026-02-22T20:30:00.000Z', new DateTimeZone('UTC'))))
        );

        $response = $service->createSession(['session_name' => '  Streetwise Night  ']);

        self::assertSame(Response::STATUS_CREATED, $response->code());
        $payload = $response->data();
        self::assertIsArray($payload);
        self::assertSame(7, $payload['session_id']);
        self::assertSame('Streetwise Night', $payload['session_name']);
        self::assertTrue($payload['joining_enabled']);
        self::assertSame('gmOpaqueToken123', $payload['gm_token']);
        self::assertSame('https://example.com/join#join=joinOpaqueToken456', $payload['join_link']);
        self::assertSame('2026-02-22T20:30:00.123Z', $payload['created_at']);

        self::assertCount(4, $insertCalls);

        self::assertSame('sessions', $insertCalls[0][0]);
        self::assertSame('Streetwise Night', $insertCalls[0][1]['session_name']);
        self::assertSame(1, $insertCalls[0][1]['session_joining_enabled']);

        self::assertSame('session_tokens', $insertCalls[1][0]);
        self::assertSame(7, $insertCalls[1][1]['token_session_id']);
        self::assertSame('gm', $insertCalls[1][1]['token_role']);
        self::assertSame('gmOpaqueToke', $insertCalls[1][1]['token_prefix']);
        self::assertSame(32, strlen($insertCalls[1][1]['token_hash']));

        self::assertSame('session_join_tokens', $insertCalls[2][0]);
        self::assertSame(7, $insertCalls[2][1]['join_token_session_id']);
        self::assertSame('joinOpaqueTo', $insertCalls[2][1]['join_token_prefix']);
        self::assertSame(32, strlen($insertCalls[2][1]['join_token_hash']));

        self::assertSame('session_state', $insertCalls[3][0]);
        self::assertCount(2, $insertCalls[3][1]);
        self::assertSame('scene_strain', $insertCalls[3][1][0]['state_name']);
        self::assertSame('0', $insertCalls[3][1][0]['state_value']);
        self::assertSame('joining_enabled', $insertCalls[3][1][1]['state_name']);
        self::assertSame('true', $insertCalls[3][1][1]['state_value']);
    }

    public function testCreateSessionReturnsInternalErrorWhenTransactionFails(): void
    {
        $db = $this->createSimplePdoMock();
        $db->expects(self::once())
            ->method('transaction')
            ->willThrowException(new \RuntimeException('db failed'));

        $service = new SessionBootstrapService(
            $db,
            new RequestValidator(),
            new FixedTokenGenerator('dummy'),
            new JoinLinkBuilder('https://example.com'),
            new DateTimeFormatter(new FixedClock(new DateTimeImmutable('now', new DateTimeZone('UTC'))))
        );

        $response = $service->createSession(['session_name' => 'Streetwise Night']);

        self::assertSame(Response::STATUS_INTERNAL_SERVER_ERROR, $response->code());
        self::assertSame(Response::ERROR_INTERNAL, $response->data()['error']['code']);
    }

    private function createSimplePdoMock(): SimplePdo
    {
        return $this->getMockBuilder(SimplePdo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['transaction', 'insert', 'fetchRow'])
            ->getMock();
    }
}

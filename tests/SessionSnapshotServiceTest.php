<?php

declare(strict_types=1);

namespace YZERoller\Api\Tests;

use flight\database\SimplePdo;
use flight\util\Collection;
use PHPUnit\Framework\TestCase;
use YZERoller\Api\Auth\AuthGuard;
use YZERoller\Api\Auth\TokenLookup;
use YZERoller\Api\Response;
use YZERoller\Api\Service\SessionSnapshotService;

final class SessionSnapshotServiceTest extends TestCase
{
    public function testGetSnapshotReturnsTokenMissingWhenHeaderMissing(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::never())->method('fetchRow');

        $snapshotDb = $this->createSnapshotDbMock();
        $snapshotDb->expects(self::never())->method('fetchRow');
        $snapshotDb->expects(self::never())->method('fetchPairs');
        $snapshotDb->expects(self::never())->method('fetchAll');

        $service = $this->createService($lookupDb, $snapshotDb);

        $response = $service->getSnapshot(null);

        self::assertSame(Response::STATUS_UNAUTHORIZED, $response->code());
        self::assertSame(Response::ERROR_TOKEN_MISSING, $response->data()['error']['code']);
    }

    public function testGetSnapshotReturnsNotFoundWhenSessionMissing(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
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

        $snapshotDb = $this->createSnapshotDbMock();
        $snapshotDb->expects(self::once())
            ->method('fetchRow')
            ->with(
                self::stringContains('FROM sessions'),
                [7]
            )
            ->willReturn(null);
        $snapshotDb->expects(self::never())->method('fetchPairs');
        $snapshotDb->expects(self::never())->method('fetchAll');

        $service = $this->createService($lookupDb, $snapshotDb);

        $response = $service->getSnapshot('Bearer session-token');

        self::assertSame(Response::STATUS_NOT_FOUND, $response->code());
        self::assertSame(Response::ERROR_SESSION_NOT_FOUND, $response->data()['error']['code']);
    }

    public function testGetSnapshotReturnsExpectedPayloadOnSuccess(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
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

        $snapshotDb = $this->createSnapshotDbMock();
        $snapshotDb->expects(self::exactly(2))
            ->method('fetchRow')
            ->willReturnCallback(function (string $sql, array $params): Collection {
                if (str_contains($sql, 'FROM sessions')) {
                    self::assertSame([7], $params);
                    return new Collection([
                        'session_id' => 7,
                        'session_name' => 'Streetwise Night',
                    ]);
                }

                if (str_contains($sql, 'MAX(event_id)')) {
                    self::assertSame([7], $params);
                    return new Collection(['latest_event_id' => '130']);
                }

                throw new \RuntimeException('Unexpected query');
            });

        $snapshotDb->expects(self::once())
            ->method('fetchPairs')
            ->with(
                self::stringContains('FROM session_state'),
                [7, ['joining_enabled', 'scene_strain']]
            )
            ->willReturn([
                'joining_enabled' => 'true',
                'scene_strain' => '3',
            ]);

        $snapshotDb->expects(self::once())
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
                ]),
            ]);

        $service = $this->createService($lookupDb, $snapshotDb);
        $response = $service->getSnapshot('Bearer session-token');

        self::assertSame(Response::STATUS_OK, $response->code());
        $payload = $response->data();
        self::assertIsArray($payload);
        self::assertSame(7, $payload['session_id']);
        self::assertSame('Streetwise Night', $payload['session_name']);
        self::assertTrue($payload['joining_enabled']);
        self::assertSame('player', $payload['role']);
        self::assertSame(31, $payload['self']['token_id']);
        self::assertSame('Alice', $payload['self']['display_name']);
        self::assertSame(3, $payload['scene_strain']);
        self::assertSame(130, $payload['latest_event_id']);
        self::assertCount(1, $payload['players']);
        self::assertSame(31, $payload['players'][0]['token_id']);
    }

    public function testGetSnapshotUsesStateAndEventFallbackRules(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::once())
            ->method('fetchRow')
            ->with(self::stringContains('FROM session_tokens'))
            ->willReturn(new Collection([
                'token_id' => 50,
                'token_session_id' => 11,
                'token_role' => 'gm',
                'token_display_name' => null,
                'token_hash' => hash('sha256', 'gm-token', true),
                'token_prefix' => 'gmtokenprefix',
                'token_revoked' => null,
                'token_created' => '2026-02-22 10:00:00.000000',
                'token_updated' => '2026-02-22 10:00:00.000000',
                'token_last_seen' => null,
            ]));

        $snapshotDb = $this->createSnapshotDbMock();
        $snapshotDb->expects(self::exactly(2))
            ->method('fetchRow')
            ->willReturnCallback(function (string $sql): Collection {
                if (str_contains($sql, 'FROM sessions')) {
                    return new Collection([
                        'session_id' => 11,
                        'session_name' => 'GM Session',
                    ]);
                }

                return new Collection(['latest_event_id' => null]);
            });

        $snapshotDb->expects(self::once())
            ->method('fetchPairs')
            ->willReturn([
                'joining_enabled' => 'invalid',
                'scene_strain' => '-1',
            ]);

        $snapshotDb->expects(self::once())
            ->method('fetchAll')
            ->willReturn([]);

        $service = $this->createService($lookupDb, $snapshotDb);
        $response = $service->getSnapshot('Bearer gm-token');
        $payload = $response->data();

        self::assertIsArray($payload);
        self::assertFalse($payload['joining_enabled']);
        self::assertSame(0, $payload['scene_strain']);
        self::assertSame(0, $payload['latest_event_id']);
        self::assertSame([], $payload['players']);
    }

    private function createService(SimplePdo $lookupDb, SimplePdo $snapshotDb): SessionSnapshotService
    {
        $authGuard = new AuthGuard(new TokenLookup($lookupDb));

        return new SessionSnapshotService($snapshotDb, $authGuard);
    }

    private function createLookupDbMock(): SimplePdo
    {
        return $this->getMockBuilder(SimplePdo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchRow'])
            ->getMock();
    }

    private function createSnapshotDbMock(): SimplePdo
    {
        return $this->getMockBuilder(SimplePdo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchRow', 'fetchPairs', 'fetchAll'])
            ->getMock();
    }
}

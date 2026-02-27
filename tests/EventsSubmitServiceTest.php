<?php

declare(strict_types=1);

namespace YZERoller\Api\Tests;

use flight\database\SimplePdo;
use flight\util\Collection;
use PDO;
use PHPUnit\Framework\TestCase;
use YZERoller\Api\Auth\AuthGuard;
use YZERoller\Api\Auth\TokenLookup;
use YZERoller\Api\Response;
use YZERoller\Api\Service\EventsSubmitService;
use YZERoller\Api\Support\DateTimeFormatter;
use YZERoller\Api\Support\SystemClock;
use YZERoller\Api\Validation\RequestValidator;

final class EventsSubmitServiceTest extends TestCase
{
    public function testSubmitReturnsTokenMissingWhenHeaderMissing(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::never())->method('fetchRow');

        $eventsDb = $this->createEventsDbMock();
        $eventsDb->expects(self::never())->method('insert');

        $service = $this->createService($lookupDb, $eventsDb);
        $response = $service->submit(null, ['type' => 'roll', 'payload' => ['successes' => 1, 'banes' => 0]]);

        self::assertSame(Response::STATUS_UNAUTHORIZED, $response->code());
        self::assertSame(Response::ERROR_TOKEN_MISSING, $response->data()['error']['code']);
    }

    public function testSubmitReturnsValidationErrorForInvalidPayload(): void
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
            ]));

        $eventsDb = $this->createEventsDbMock();
        $eventsDb->expects(self::never())->method('insert');

        $service = $this->createService($lookupDb, $eventsDb);
        $response = $service->submit('Bearer session-token', ['type' => 'roll']);

        self::assertSame(Response::STATUS_UNPROCESSABLE_ENTITY, $response->code());
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $response->data()['error']['code']);
    }

    public function testSubmitRollReturnsCreatedEvent(): void
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
            ]));

        $eventsDb = $this->createEventsDbMock();
        $eventsDb->expects(self::once())
            ->method('insert')
            ->with(
                'events',
                self::callback(function (array $data): bool {
                    if (($data['event_type'] ?? null) !== 'roll') {
                        return false;
                    }
                    $decoded = json_decode($data['event_payload_json'] ?? '', true);
                    return $decoded === ['successes' => 1, 'banes' => 0];
                })
            )
            ->willReturn('132');

        $eventsDb->expects(self::once())
            ->method('fetchRow')
            ->with(
                self::stringContains('SELECT event_created FROM events'),
                [132, 7]
            )
            ->willReturn(new Collection([
                'event_created' => '2026-02-22 20:32:20.000000',
            ]));

        $eventsDb->expects(self::never())->method('transaction');

        $service = $this->createService($lookupDb, $eventsDb);
        $response = $service->submit(
            'Bearer session-token',
            ['type' => 'roll', 'payload' => ['successes' => 1, 'banes' => 0]]
        );

        self::assertSame(Response::STATUS_CREATED, $response->code());
        $payload = $response->data();
        self::assertIsArray($payload);
        self::assertSame(132, $payload['event']['id']);
        self::assertSame('roll', $payload['event']['type']);
        self::assertSame('2026-02-22T20:32:20.000Z', $payload['event']['occurred_at']);
        self::assertSame(['successes' => 1, 'banes' => 0], $payload['event']['payload']);
        self::assertArrayNotHasKey('scene_strain', $payload);
    }

    public function testSubmitPushWithoutStrainReturnsCreatedEventWithIncrementedSceneStrain(): void
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
            ]));

        $eventsDb = $this->createEventsDbMock();
        $statement = $this->getMockBuilder(\PDOStatement::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetch'])
            ->getMock();
        $statement->expects(self::once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['state_value' => '3']);

        $eventsDb->expects(self::once())
            ->method('runQuery')
            ->with(
                self::stringContains('FOR UPDATE'),
                [7, 'scene_strain']
            )
            ->willReturn($statement);

        $eventsDb->expects(self::once())
            ->method('update')
            ->with(
                'session_state',
                ['state_value' => '4'],
                'state_session_id = ? AND state_name = ?',
                [7, 'scene_strain']
            )
            ->willReturn(1);

        $eventsDb->expects(self::once())
            ->method('insert')
            ->with(
                'events',
                self::callback(function (array $data): bool {
                    $decoded = json_decode($data['event_payload_json'] ?? '', true);
                    return $decoded === [
                        'successes' => 2,
                        'banes' => 1,
                        'strain' => false,
                        'scene_strain' => 4,
                    ];
                })
            )
            ->willReturn('200');

        $eventsDb->expects(self::once())
            ->method('fetchRow')
            ->with(
                self::stringContains('SELECT event_created FROM events'),
                [200, 7]
            )
            ->willReturn(new Collection(['event_created' => '2026-02-22 20:40:00.000000']));

        $eventsDb->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(function (callable $callback) use ($eventsDb) {
                return $callback($eventsDb);
            });

        $service = $this->createService($lookupDb, $eventsDb);
        $response = $service->submit(
            'Bearer session-token',
            ['type' => 'push', 'payload' => ['successes' => 2, 'banes' => 1, 'strain' => false]]
        );

        self::assertSame(Response::STATUS_CREATED, $response->code());
        $payload = $response->data();
        self::assertIsArray($payload);
        self::assertSame(4, $payload['scene_strain']);
        self::assertSame(4, $payload['event']['payload']['scene_strain']);
    }

    public function testSubmitPushWithStrainTrueUsesTransactionAndIncrementsByBanes(): void
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
            ]));

        $statement = $this->getMockBuilder(\PDOStatement::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetch'])
            ->getMock();
        $statement->expects(self::once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['state_value' => '3']);

        $eventsDb = $this->createEventsDbMock();
        $eventsDb->expects(self::once())
            ->method('runQuery')
            ->with(
                self::stringContains('FOR UPDATE'),
                [7, 'scene_strain']
            )
            ->willReturn($statement);

        $eventsDb->expects(self::once())
            ->method('update')
            ->with(
                'session_state',
                ['state_value' => '4'],
                'state_session_id = ? AND state_name = ?',
                [7, 'scene_strain']
            )
            ->willReturn(1);

        $eventsDb->expects(self::once())
            ->method('insert')
            ->with(
                'events',
                self::callback(function (array $data): bool {
                    $decoded = json_decode($data['event_payload_json'] ?? '', true);
                    return $decoded === [
                        'successes' => 2,
                        'banes' => 1,
                        'strain' => true,
                        'scene_strain' => 4,
                    ];
                })
            )
            ->willReturn('201');

        $eventsDb->expects(self::once())
            ->method('fetchRow')
            ->with(
                self::stringContains('SELECT event_created FROM events'),
                [201, 7]
            )
            ->willReturn(new Collection(['event_created' => '2026-02-22 20:41:00.000000']));

        $eventsDb->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(function (callable $callback) use ($eventsDb) {
                return $callback($eventsDb);
            });

        $service = $this->createService($lookupDb, $eventsDb);
        $response = $service->submit(
            'Bearer session-token',
            ['type' => 'push', 'payload' => ['successes' => 2, 'banes' => 1, 'strain' => true]]
        );

        self::assertSame(Response::STATUS_CREATED, $response->code());
        $payload = $response->data();
        self::assertIsArray($payload);
        self::assertSame(4, $payload['scene_strain']);
        self::assertSame(4, $payload['event']['payload']['scene_strain']);
    }

    public function testSubmitReturnsInternalErrorWhenDbFails(): void
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
            ]));

        $eventsDb = $this->createEventsDbMock();
        $eventsDb->expects(self::once())
            ->method('insert')
            ->willThrowException(new \RuntimeException('db fail'));

        $service = $this->createService($lookupDb, $eventsDb);
        $response = $service->submit(
            'Bearer session-token',
            ['type' => 'roll', 'payload' => ['successes' => 1, 'banes' => 0]]
        );

        self::assertSame(Response::STATUS_INTERNAL_SERVER_ERROR, $response->code());
        self::assertSame(Response::ERROR_INTERNAL, $response->data()['error']['code']);
    }

    private function createService(SimplePdo $lookupDb, SimplePdo $eventsDb): EventsSubmitService
    {
        $authGuard = new AuthGuard(new TokenLookup($lookupDb));

        return new EventsSubmitService($eventsDb, $authGuard, new RequestValidator(), new DateTimeFormatter(new SystemClock()));
    }

    private function createLookupDbMock(): SimplePdo
    {
        return $this->getMockBuilder(SimplePdo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchRow'])
            ->getMock();
    }

    private function createEventsDbMock(): SimplePdo
    {
        return $this->getMockBuilder(SimplePdo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['insert', 'fetchRow', 'transaction', 'runQuery', 'update'])
            ->getMock();
    }
}

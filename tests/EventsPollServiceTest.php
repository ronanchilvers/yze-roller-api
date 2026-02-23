<?php

declare(strict_types=1);

namespace YZERoller\Api\Tests;

use flight\database\SimplePdo;
use flight\util\Collection;
use PHPUnit\Framework\TestCase;
use YZERoller\Api\Auth\AuthGuard;
use YZERoller\Api\Auth\TokenLookup;
use YZERoller\Api\Response;
use YZERoller\Api\Service\EventsPollService;
use YZERoller\Api\Validation\RequestValidator;

final class EventsPollServiceTest extends TestCase
{
    public function testPollReturnsTokenMissingWhenHeaderMissing(): void
    {
        $lookupDb = $this->createLookupDbMock();
        $lookupDb->expects(self::never())->method('fetchRow');

        $eventsDb = $this->createEventsDbMock();
        $eventsDb->expects(self::never())->method('fetchAll');

        $service = $this->createService($lookupDb, $eventsDb);
        $response = $service->poll(null, []);

        self::assertSame(Response::STATUS_UNAUTHORIZED, $response->code());
        self::assertSame(Response::ERROR_TOKEN_MISSING, $response->data()['error']['code']);
    }

    public function testPollReturnsValidationErrorForInvalidSinceId(): void
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

        $eventsDb = $this->createEventsDbMock();
        $eventsDb->expects(self::never())->method('fetchAll');

        $service = $this->createService($lookupDb, $eventsDb);
        $response = $service->poll('Bearer session-token', ['since_id' => 'x']);

        self::assertSame(Response::STATUS_UNPROCESSABLE_ENTITY, $response->code());
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $response->data()['error']['code']);
    }

    public function testPollReturnsNoContentWhenNoEventsFound(): void
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

        $eventsDb = $this->createEventsDbMock();
        $eventsDb->expects(self::once())
            ->method('fetchAll')
            ->with(
                self::logicalAnd(
                    self::stringContains('FROM events e'),
                    self::stringContains('LIMIT 10')
                ),
                [7, 0]
            )
            ->willReturn([]);

        $service = $this->createService($lookupDb, $eventsDb);
        $response = $service->poll('Bearer session-token', []);

        self::assertSame(Response::STATUS_NO_CONTENT, $response->code());
        self::assertNull($response->data());
    }

    public function testPollReturnsEventsAndNextSinceId(): void
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

        $eventsDb = $this->createEventsDbMock();
        $eventsDb->expects(self::once())
            ->method('fetchAll')
            ->with(
                self::logicalAnd(
                    self::stringContains('FROM events e'),
                    self::stringContains('LIMIT 2')
                ),
                [7, 120]
            )
            ->willReturn([
                new Collection([
                    'event_id' => 121,
                    'event_type' => 'roll',
                    'event_session_id' => 7,
                    'event_created' => '2026-02-22 20:32:10.000000',
                    'event_payload_json' => '{"successes":1,"banes":0}',
                    'event_actor_token_id' => 31,
                    'actor_display_name' => 'Alice',
                    'actor_role' => 'player',
                ]),
                new Collection([
                    'event_id' => 122,
                    'event_type' => 'strain_reset',
                    'event_session_id' => 7,
                    'event_created' => '2026-02-22 20:33:10.500000',
                    'event_payload_json' => '{"previous_scene_strain":3,"scene_strain":0}',
                    'event_actor_token_id' => null,
                    'actor_display_name' => null,
                    'actor_role' => null,
                ]),
            ]);

        $service = $this->createService($lookupDb, $eventsDb);
        $response = $service->poll('Bearer session-token', ['since_id' => '120', 'limit' => '2']);

        self::assertSame(Response::STATUS_OK, $response->code());
        $payload = $response->data();
        self::assertIsArray($payload);
        self::assertSame(122, $payload['next_since_id']);
        self::assertCount(2, $payload['events']);

        self::assertSame(121, $payload['events'][0]['id']);
        self::assertSame('roll', $payload['events'][0]['type']);
        self::assertSame(7, $payload['events'][0]['session_id']);
        self::assertSame('2026-02-22T20:32:10.000Z', $payload['events'][0]['occurred_at']);
        self::assertSame(
            ['token_id' => 31, 'display_name' => 'Alice', 'role' => 'player'],
            $payload['events'][0]['actor']
        );
        self::assertSame(['successes' => 1, 'banes' => 0], $payload['events'][0]['payload']);

        self::assertSame(122, $payload['events'][1]['id']);
        self::assertNull($payload['events'][1]['actor']);
        self::assertSame(
            ['previous_scene_strain' => 3, 'scene_strain' => 0],
            $payload['events'][1]['payload']
        );
    }

    public function testPollReturnsInternalErrorWhenQueryFails(): void
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

        $eventsDb = $this->createEventsDbMock();
        $eventsDb->expects(self::once())
            ->method('fetchAll')
            ->willThrowException(new \RuntimeException('db error'));

        $service = $this->createService($lookupDb, $eventsDb);
        $response = $service->poll('Bearer session-token', ['since_id' => 0, 'limit' => 10]);

        self::assertSame(Response::STATUS_INTERNAL_SERVER_ERROR, $response->code());
        self::assertSame(Response::ERROR_INTERNAL, $response->data()['error']['code']);
    }

    private function createService(SimplePdo $lookupDb, SimplePdo $eventsDb): EventsPollService
    {
        $authGuard = new AuthGuard(new TokenLookup($lookupDb));

        return new EventsPollService($eventsDb, $authGuard, new RequestValidator());
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
            ->onlyMethods(['fetchAll'])
            ->getMock();
    }
}

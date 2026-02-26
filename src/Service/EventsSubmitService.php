<?php

declare(strict_types=1);

namespace YZERoller\Api\Service;

use DateTimeImmutable;
use DateTimeZone;
use flight\database\SimplePdo;
use PDO;
use Throwable;
use YZERoller\Api\Auth\AuthGuard;
use YZERoller\Api\Response;
use YZERoller\Api\Validation\RequestValidator;

final class EventsSubmitService
{
    public function __construct(
        private readonly SimplePdo $db,
        private readonly AuthGuard $authGuard,
        private readonly RequestValidator $validator
    ) {
    }

    /**
     * @param array<string,mixed> $body
     */
    public function submit(?string $authorizationHeader, array $body): Response
    {
        $sessionTokenRow = $this->authGuard->requireSessionToken($authorizationHeader);
        if ($sessionTokenRow instanceof Response) {
            return $sessionTokenRow;
        }

        $validated = $this->validator->validateEventSubmitPayload($body);
        if ($validated instanceof Response) {
            return $validated;
        }

        $sessionId = (int) ($sessionTokenRow['token_session_id'] ?? 0);
        $tokenId = (int) ($sessionTokenRow['token_id'] ?? 0);
        if ($sessionId <= 0 || $tokenId <= 0) {
            return (new Response())->withError(
                Response::ERROR_TOKEN_INVALID,
                'Authorization token is invalid.',
                Response::STATUS_UNAUTHORIZED
            );
        }

        /** @var string $type */
        $type = $validated['type'];
        /** @var array<string,mixed> $payload */
        $payload = $validated['payload'];

        try {
            if ($type === 'roll') {
                $result = $this->insertEvent(
                    $sessionId,
                    $tokenId,
                    $type,
                    [
                        'successes' => (int) $payload['successes'],
                        'banes' => (int) $payload['banes'],
                    ]
                );

                return $this->createdEventResponse(
                    sessionId: $sessionId,
                    eventId: $result['event_id'],
                    eventType: 'roll',
                    eventCreated: $result['event_created'],
                    actorTokenId: $tokenId,
                    actorDisplayName: $sessionTokenRow['token_display_name'] ?? null,
                    actorRole: (string) ($sessionTokenRow['token_role'] ?? ''),
                    eventPayload: $result['event_payload']
                );
            }

            // push
            /** @var bool $strain */
            $strain = $payload['strain'];
            $pushResult = $this->db->transaction(function (SimplePdo $db) use ($sessionId, $tokenId, $payload, $strain): array {
                $currentSceneStrain = $this->readSceneStrainForUpdate($db, $sessionId);
                $banes = (int) $payload['banes'];
                $newSceneStrain = $currentSceneStrain + $banes;
                $this->writeSceneStrain($db, $sessionId, $newSceneStrain);

                $eventPayload = [
                    'successes' => (int) $payload['successes'],
                    'banes' => $banes,
                    'strain' => $strain,
                    'scene_strain' => $newSceneStrain,
                ];

                $eventInsert = $this->insertEvent(
                    $sessionId,
                    $tokenId,
                    'push',
                    $eventPayload,
                    $db
                );

                return [
                    'event_id' => $eventInsert['event_id'],
                    'event_created' => $eventInsert['event_created'],
                    'event_payload' => $eventPayload,
                    'scene_strain' => $newSceneStrain,
                ];
            });

            return $this->createdEventResponse(
                sessionId: $sessionId,
                eventId: $pushResult['event_id'],
                eventType: 'push',
                eventCreated: $pushResult['event_created'],
                actorTokenId: $tokenId,
                actorDisplayName: $sessionTokenRow['token_display_name'] ?? null,
                actorRole: (string) ($sessionTokenRow['token_role'] ?? ''),
                eventPayload: $pushResult['event_payload'],
                sceneStrain: $pushResult['scene_strain']
            );
        } catch (Throwable) {
            return (new Response())->withError(
                Response::ERROR_INTERNAL,
                'Failed to submit event.',
                Response::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * @param array<string,mixed> $eventPayload
     * @return array{event_id:int,event_created:string,event_payload:array<string,mixed>}
     */
    private function insertEvent(
        int $sessionId,
        int $actorTokenId,
        string $eventType,
        array $eventPayload,
        ?SimplePdo $db = null
    ): array {
        $database = $db ?? $this->db;
        $eventId = (int) $database->insert('events', [
            'event_session_id' => $sessionId,
            'event_actor_token_id' => $actorTokenId,
            'event_type' => $eventType,
            'event_payload_json' => json_encode($eventPayload, JSON_THROW_ON_ERROR),
        ]);

        $eventRow = $database->fetchRow(
            'SELECT event_created FROM events WHERE event_id = ? AND event_session_id = ?',
            [$eventId, $sessionId]
        );

        $eventCreated = '';
        if ($eventRow !== null) {
            $raw = $eventRow['event_created'] ?? null;
            if (is_string($raw)) {
                $eventCreated = $raw;
            }
        }

        return [
            'event_id' => $eventId,
            'event_created' => $eventCreated,
            'event_payload' => $eventPayload,
        ];
    }

    private function readSceneStrainForUpdate(SimplePdo $db, int $sessionId): int
    {
        $stmt = $db->runQuery(
            'SELECT state_value FROM session_state WHERE state_session_id = ? AND state_name = ? FOR UPDATE',
            [$sessionId, 'scene_strain']
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $this->parseSceneStrainValue($row['state_value'] ?? null);
    }

    private function writeSceneStrain(SimplePdo $db, int $sessionId, int $sceneStrain): void
    {
        $affected = $db->update(
            'session_state',
            ['state_value' => (string) $sceneStrain],
            'state_session_id = ? AND state_name = ?',
            [$sessionId, 'scene_strain']
        );

        if ($affected === 0) {
            $db->insert('session_state', [
                'state_session_id' => $sessionId,
                'state_name' => 'scene_strain',
                'state_value' => (string) $sceneStrain,
            ]);
        }
    }

    private function parseSceneStrainValue(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^(0|[1-9]\d*)$/', $value) !== 1) {
            return 0;
        }

        return (int) $value;
    }

    /**
     * @param array<string,mixed> $eventPayload
     */
    private function createdEventResponse(
        int $sessionId,
        int $eventId,
        string $eventType,
        string $eventCreated,
        int $actorTokenId,
        mixed $actorDisplayName,
        string $actorRole,
        array $eventPayload,
        ?int $sceneStrain = null
    ): Response {
        $payload = [
            'event' => [
                'id' => $eventId,
                'type' => $eventType,
                'session_id' => $sessionId,
                'occurred_at' => $this->toRfc3339($eventCreated),
                'actor' => [
                    'token_id' => $actorTokenId,
                    'display_name' => is_string($actorDisplayName) ? $actorDisplayName : null,
                    'role' => $actorRole,
                ],
                'payload' => $eventPayload,
            ],
        ];

        if ($sceneStrain !== null) {
            $payload['scene_strain'] = $sceneStrain;
        }

        return (new Response())
            ->withCode(Response::STATUS_CREATED)
            ->withData($payload);
    }

    private function toRfc3339(string $mysqlDateTime): string
    {
        if ($mysqlDateTime === '') {
            return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        }

        $utc = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $mysqlDateTime, $utc);
        if (!$date) {
            $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysqlDateTime, $utc);
        }
        if (!$date) {
            $date = new DateTimeImmutable('now', $utc);
        }

        return $date->setTimezone($utc)->format('Y-m-d\TH:i:s.v\Z');
    }
}

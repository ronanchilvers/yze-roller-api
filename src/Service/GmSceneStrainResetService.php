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

final class GmSceneStrainResetService
{
    /**
     * @var callable():DateTimeImmutable
     */
    private $nowProvider;

    /**
     * @param callable():DateTimeImmutable|null $nowProvider
     */
    public function __construct(
        private readonly SimplePdo $db,
        private readonly AuthGuard $authGuard,
        private readonly RequestValidator $validator,
        ?callable $nowProvider = null
    ) {
        $this->nowProvider = $nowProvider ?? static function (): DateTimeImmutable {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        };
    }

    /**
     * @param array<string,mixed> $body
     */
    public function reset(?string $authorizationHeader, mixed $sessionIdInput, array $body): Response
    {
        $sessionTokenRow = $this->authGuard->requireSessionToken($authorizationHeader);
        if ($sessionTokenRow instanceof Response) {
            return $sessionTokenRow;
        }

        $gmRoleError = $this->authGuard->requireGmRole($sessionTokenRow);
        if ($gmRoleError instanceof Response) {
            return $gmRoleError;
        }

        $sessionId = $this->validator->validatePositiveId($sessionIdInput, 'session_id');
        if ($sessionId instanceof Response) {
            return $sessionId;
        }

        $bodyValidation = $this->validator->validateEmptyObjectPayload($body);
        if ($bodyValidation instanceof Response) {
            return $bodyValidation;
        }

        $actorTokenId = (int) ($sessionTokenRow['token_id'] ?? 0);
        $tokenSessionId = (int) ($sessionTokenRow['token_session_id'] ?? 0);
        if ($tokenSessionId <= 0 || $actorTokenId <= 0) {
            return (new Response())->withError(
                Response::ERROR_TOKEN_INVALID,
                'Authorization token is invalid.',
                Response::STATUS_UNAUTHORIZED
            );
        }

        if ($tokenSessionId !== $sessionId) {
            return (new Response())->withError(
                Response::ERROR_ROLE_FORBIDDEN,
                'GM token is not authorized for the requested session.',
                Response::STATUS_FORBIDDEN
            );
        }

        try {
            $session = $this->db->fetchRow(
                'SELECT session_id FROM sessions WHERE session_id = ?',
                [$sessionId]
            );
            if ($session === null) {
                return (new Response())->withError(
                    Response::ERROR_SESSION_NOT_FOUND,
                    'Session not found.',
                    Response::STATUS_NOT_FOUND
                );
            }

            $nowMysql = $this->nowMysql();
            $result = $this->db->transaction(function (SimplePdo $db) use ($sessionId, $actorTokenId, $nowMysql): array {
                $statement = $db->runQuery(
                    'SELECT state_id, state_value
                     FROM session_state
                     WHERE state_session_id = ? AND state_name = ?
                     FOR UPDATE',
                    [$sessionId, 'scene_strain']
                );
                $row = $statement->fetch(PDO::FETCH_ASSOC);

                $previousSceneStrain = $this->parseSceneStrain($row['state_value'] ?? null);
                $stateId = $this->extractStateId($row);

                if ($stateId !== null) {
                    $db->update(
                        'session_state',
                        [
                            'state_value' => '0',
                            'state_updated' => $nowMysql,
                        ],
                        'state_id = ?',
                        [$stateId]
                    );
                } else {
                    $db->insert('session_state', [
                        'state_session_id' => $sessionId,
                        'state_name' => 'scene_strain',
                        'state_value' => '0',
                        'state_created' => $nowMysql,
                        'state_updated' => $nowMysql,
                    ]);
                }

                $eventId = (int) $db->insert('events', [
                    'event_session_id' => $sessionId,
                    'event_actor_token_id' => $actorTokenId,
                    'event_type' => 'strain_reset',
                    'event_payload_json' => json_encode(
                        [
                            'previous_scene_strain' => $previousSceneStrain,
                            'scene_strain' => 0,
                        ],
                        JSON_THROW_ON_ERROR
                    ),
                ]);

                return [
                    'event_id' => $eventId,
                ];
            });
        } catch (Throwable) {
            return (new Response())->withError(
                Response::ERROR_INTERNAL,
                'Failed to reset scene strain.',
                Response::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return (new Response())
            ->withCode(Response::STATUS_OK)
            ->withData([
                'session_id' => $sessionId,
                'scene_strain' => 0,
                'event_id' => (int) ($result['event_id'] ?? 0),
            ]);
    }

    private function parseSceneStrain(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^(0|[1-9]\d*)$/', $value) !== 1) {
            return 0;
        }

        return (int) $value;
    }

    /**
     * @param array<string,mixed>|false $row
     */
    private function extractStateId(array|false $row): ?int
    {
        if (!is_array($row) || !array_key_exists('state_id', $row)) {
            return null;
        }

        $value = $row['state_id'];
        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function nowMysql(): string
    {
        return ($this->nowProvider)()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}


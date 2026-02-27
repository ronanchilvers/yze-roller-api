<?php

declare(strict_types=1);

namespace YZERoller\Api\Service;

use flight\database\SimplePdo;
use PDO;
use Throwable;
use YZERoller\Api\Auth\GmSessionAuthorizer;
use YZERoller\Api\Response;
use YZERoller\Api\Support\DateTimeFormatter;
use YZERoller\Api\Support\SessionStateParser;
use YZERoller\Api\Validation\RequestValidator;

final class GmSceneStrainResetService
{
    public function __construct(
        private readonly SimplePdo $db,
        private readonly GmSessionAuthorizer $authorizer,
        private readonly RequestValidator $validator,
        private readonly DateTimeFormatter $formatter
    ) {
    }

    /**
     * @param array<string,mixed> $body
     */
    public function reset(?string $authorizationHeader, mixed $sessionIdInput, array $body): Response
    {
        $bodyValidation = $this->validator->validateEmptyObjectPayload($body);
        if ($bodyValidation instanceof Response) {
            return $bodyValidation;
        }

        $auth = $this->authorizer->authorize($authorizationHeader, $sessionIdInput);
        if ($auth instanceof Response) {
            return $auth;
        }

        $sessionId = $auth['sessionId'];
        $actorTokenId = $auth['actorTokenId'];
        $nowMysql = $this->formatter->toMysqlDateTime();

        try {
            $result = $this->db->transaction(function (SimplePdo $db) use ($sessionId, $actorTokenId, $nowMysql): array {
                $statement = $db->runQuery(
                    'SELECT state_id, state_value
                     FROM session_state
                     WHERE state_session_id = ? AND state_name = ?
                     FOR UPDATE',
                    [$sessionId, 'scene_strain']
                );
                $row = $statement->fetch(PDO::FETCH_ASSOC);

                $previousSceneStrain = SessionStateParser::parseSceneStrain($row['state_value'] ?? null);
                $stateId = SessionStateParser::extractStateId($row);

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
}

<?php

declare(strict_types=1);

namespace YZERoller\Api\Service;

use flight\database\SimplePdo;
use PDO;
use Throwable;
use YZERoller\Api\Auth\GmSessionAuthorizer;
use YZERoller\Api\Response;
use YZERoller\Api\Support\DateTimeFormatter;
use YZERoller\Api\Validation\RequestValidator;

final class GmPlayerRevokeService
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
    public function revoke(
        ?string $authorizationHeader,
        mixed $sessionIdInput,
        mixed $tokenIdInput,
        array $body
    ): Response {
        $tokenId = $this->validator->validatePositiveId($tokenIdInput, 'token_id');
        if ($tokenId instanceof Response) {
            return $tokenId;
        }

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
            $result = $this->db->transaction(
                function (SimplePdo $db) use ($sessionId, $tokenId, $actorTokenId, $nowMysql): array {
                    $statement = $db->runQuery(
                        'SELECT token_id, token_display_name, token_revoked
                         FROM session_tokens
                         WHERE token_id = ? AND token_session_id = ? AND token_role = ?
                         FOR UPDATE',
                        [$tokenId, $sessionId, 'player']
                    );
                    $target = $statement->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($target)) {
                        return [
                            'player_found' => false,
                            'event_emitted' => false,
                            'event_id' => null,
                        ];
                    }

                    if (($target['token_revoked'] ?? null) !== null) {
                        return [
                            'player_found' => true,
                            'event_emitted' => false,
                            'event_id' => null,
                        ];
                    }

                    $db->update(
                        'session_tokens',
                        [
                            'token_revoked' => $nowMysql,
                            'token_updated' => $nowMysql,
                        ],
                        'token_id = ?',
                        [$tokenId]
                    );

                    $displayNameRaw = $target['token_display_name'] ?? null;
                    $displayName = is_string($displayNameRaw) ? $displayNameRaw : '';

                    $eventId = (int) $db->insert('events', [
                        'event_session_id' => $sessionId,
                        'event_actor_token_id' => $actorTokenId,
                        'event_type' => 'leave',
                        'event_payload_json' => json_encode(
                            [
                                'token_id' => $tokenId,
                                'display_name' => $displayName,
                                'reason' => 'revoked',
                            ],
                            JSON_THROW_ON_ERROR
                        ),
                    ]);

                    return [
                        'player_found' => true,
                        'event_emitted' => true,
                        'event_id' => $eventId,
                    ];
                }
            );

            if (($result['player_found'] ?? false) !== true) {
                return (new Response())->withError(
                    Response::ERROR_SESSION_NOT_FOUND,
                    'Player token not found.',
                    Response::STATUS_NOT_FOUND
                );
            }
        } catch (Throwable) {
            return (new Response())->withError(
                Response::ERROR_INTERNAL,
                'Failed to revoke player token.',
                Response::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        $payload = [
            'session_id' => $sessionId,
            'token_id' => $tokenId,
            'revoked' => true,
            'event_emitted' => (bool) ($result['event_emitted'] ?? false),
        ];

        if (($result['event_emitted'] ?? false) === true) {
            $payload['event_id'] = (int) ($result['event_id'] ?? 0);
        }

        return (new Response())
            ->withCode(Response::STATUS_OK)
            ->withData($payload);
    }
}

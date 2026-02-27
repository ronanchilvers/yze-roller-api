<?php

declare(strict_types=1);

namespace YZERoller\Api\Service;

use flight\database\SimplePdo;
use flight\util\Collection;
use Throwable;
use YZERoller\Api\Auth\AuthGuard;
use YZERoller\Api\Auth\BearerToken;
use YZERoller\Api\Response;
use YZERoller\Api\Security\JoinRateLimiter;
use YZERoller\Api\Support\CollectionHelper;
use YZERoller\Api\Support\DateTimeFormatter;
use YZERoller\Api\Support\TokenGenerator;
use YZERoller\Api\Validation\RequestValidator;

final class JoinService
{
    public function __construct(
        private readonly SimplePdo $db,
        private readonly AuthGuard $authGuard,
        private readonly RequestValidator $validator,
        private readonly JoinRateLimiter $joinRateLimiter,
        private readonly TokenGenerator $tokenGenerator,
        private readonly DateTimeFormatter $formatter
    ) {
    }

    /**
     * @param array<string,mixed> $body
     */
    public function join(?string $authorizationHeader, array $body, ?string $clientIp = null): Response
    {
        $joinTokenRow = $this->authGuard->requireJoinToken($authorizationHeader);
        if ($joinTokenRow instanceof Response) {
            return $joinTokenRow;
        }

        $displayName = $this->validator->validateDisplayName($body['display_name'] ?? null);
        if ($displayName instanceof Response) {
            return $displayName;
        }

        $sessionId = (int) ($joinTokenRow['join_token_session_id'] ?? 0);
        if ($sessionId <= 0) {
            return (new Response())->withError(
                Response::ERROR_TOKEN_INVALID,
                'Authorization token is invalid.',
                Response::STATUS_UNAUTHORIZED
            );
        }

        if (!$this->isJoinRequestAllowed($joinTokenRow, $sessionId, $clientIp)) {
            return (new Response())->withError(
                Response::ERROR_RATE_LIMITED,
                'Join rate limit exceeded. Please retry shortly.',
                Response::STATUS_TOO_MANY_REQUESTS
            );
        }

        if (!$this->isJoiningEnabled($sessionId)) {
            return (new Response())->withError(
                Response::ERROR_JOIN_DISABLED,
                'Joining is currently disabled for this session.',
                Response::STATUS_FORBIDDEN
            );
        }

        try {
            /** @var array<string,mixed> $payload */
            $payload = $this->db->transaction(function (SimplePdo $db) use ($sessionId, $displayName, $joinTokenRow): array {
                $playerToken = $this->tokenGenerator->generate();

                $tokenId = (int) $db->insert('session_tokens', [
                    'token_session_id' => $sessionId,
                    'token_role' => 'player',
                    'token_display_name' => $displayName,
                    'token_hash' => BearerToken::hashToken($playerToken),
                    'token_prefix' => substr($playerToken, 0, 12),
                ]);

                $db->update(
                    'session_join_tokens',
                    ['join_token_last_used' => $this->formatter->toMysqlDateTime()],
                    'join_token_id = ?',
                    [(int) $joinTokenRow['join_token_id']]
                );

                $db->insert('events', [
                    'event_session_id' => $sessionId,
                    'event_actor_token_id' => $tokenId,
                    'event_type' => 'join',
                    'event_payload_json' => json_encode(
                        [
                            'token_id' => $tokenId,
                            'display_name' => $displayName,
                        ],
                        JSON_THROW_ON_ERROR
                    ),
                ]);

                return [
                    'session_id' => $sessionId,
                    'player_token' => $playerToken,
                    'player' => [
                        'token_id' => $tokenId,
                        'display_name' => $displayName,
                        'role' => 'player',
                    ],
                ];
            });
        } catch (Throwable) {
            return (new Response())->withError(
                Response::ERROR_INTERNAL,
                'Failed to join session.',
                Response::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return (new Response())
            ->withCode(Response::STATUS_CREATED)
            ->withData($payload);
    }

    /**
     * @param array<string,mixed> $joinTokenRow
     */
    private function isJoinRequestAllowed(array $joinTokenRow, int $sessionId, ?string $clientIp): bool
    {
        $joinTokenId = (int) ($joinTokenRow['join_token_id'] ?? 0);
        $subject = $joinTokenId > 0 ? (string) $joinTokenId : (string) $sessionId;
        $ip = trim((string) ($clientIp ?? 'unknown'));
        if ($ip === '') {
            $ip = 'unknown';
        }

        $key = 'join:' . $subject . ':' . $ip;

        return $this->joinRateLimiter->allow($key);
    }

    private function isJoiningEnabled(int $sessionId): bool
    {
        $row = $this->db->fetchRow(
            'SELECT state_value FROM session_state WHERE state_session_id = ? AND state_name = ?',
            [$sessionId, 'joining_enabled']
        );
        if ($row === null) {
            return false;
        }

        $value = $this->extractStateValue($row);

        // Contract fallback for invalid stored values is false.
        return $value === 'true';
    }

    private function extractStateValue(Collection|array $row): ?string
    {
        $value = CollectionHelper::toArray($row)['state_value'] ?? null;

        return is_string($value) ? $value : null;
    }
}

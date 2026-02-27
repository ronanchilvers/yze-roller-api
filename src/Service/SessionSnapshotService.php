<?php

declare(strict_types=1);

namespace YZERoller\Api\Service;

use flight\database\SimplePdo;
use flight\util\Collection;
use Throwable;
use YZERoller\Api\Auth\AuthGuard;
use YZERoller\Api\Response;
use YZERoller\Api\Support\CollectionHelper;
use YZERoller\Api\Support\SessionStateParser;

final class SessionSnapshotService
{
    public function __construct(
        private readonly SimplePdo $db,
        private readonly AuthGuard $authGuard
    ) {
    }

    public function getSnapshot(?string $authorizationHeader): Response
    {
        $sessionTokenRow = $this->authGuard->requireSessionToken($authorizationHeader);
        if ($sessionTokenRow instanceof Response) {
            return $sessionTokenRow;
        }

        $sessionId = (int) ($sessionTokenRow['token_session_id'] ?? 0);
        if ($sessionId <= 0) {
            return (new Response())->withError(
                Response::ERROR_TOKEN_INVALID,
                'Authorization token is invalid.',
                Response::STATUS_UNAUTHORIZED
            );
        }

        try {
            $session = $this->db->fetchRow(
                'SELECT session_id, session_name FROM sessions WHERE session_id = ?',
                [$sessionId]
            );
            if ($session === null) {
                return (new Response())->withError(
                    Response::ERROR_SESSION_NOT_FOUND,
                    'Session not found.',
                    Response::STATUS_NOT_FOUND
                );
            }

            $states = $this->db->fetchPairs(
                'SELECT state_name, state_value FROM session_state WHERE state_session_id = ? AND state_name IN(?)',
                [$sessionId, ['joining_enabled', 'scene_strain']]
            );

            $latestEventRow = $this->db->fetchRow(
                'SELECT MAX(event_id) AS latest_event_id FROM events WHERE event_session_id = ?',
                [$sessionId]
            );

            $players = $this->db->fetchAll(
                'SELECT token_id, token_display_name, token_role
                 FROM session_tokens
                 WHERE token_session_id = ? AND token_role = ? AND token_revoked IS NULL
                 ORDER BY token_id ASC',
                [$sessionId, 'player']
            );
        } catch (Throwable) {
            return (new Response())->withError(
                Response::ERROR_INTERNAL,
                'Failed to fetch session snapshot.',
                Response::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        $sessionData = CollectionHelper::toArray($session);

        $payload = [
            'session_id' => (int) ($sessionData['session_id'] ?? $sessionId),
            'session_name' => (string) ($sessionData['session_name'] ?? ''),
            'joining_enabled' => SessionStateParser::parseJoiningEnabled($states['joining_enabled'] ?? null),
            'role' => (string) ($sessionTokenRow['token_role'] ?? ''),
            'self' => [
                'token_id' => (int) ($sessionTokenRow['token_id'] ?? 0),
                'display_name' => $sessionTokenRow['token_display_name'] ?? null,
                'role' => (string) ($sessionTokenRow['token_role'] ?? ''),
            ],
            'scene_strain' => SessionStateParser::parseSceneStrain($states['scene_strain'] ?? null),
            'latest_event_id' => $this->parseLatestEventId($latestEventRow),
            'players' => $this->mapPlayers($players),
        ];

        return (new Response())
            ->withCode(Response::STATUS_OK)
            ->withData($payload);
    }

    private function parseLatestEventId(Collection|array|null $latestEventRow): int
    {
        if ($latestEventRow === null) {
            return 0;
        }

        $row = CollectionHelper::toArray($latestEventRow);
        $value = $row['latest_event_id'] ?? null;
        if ($value === null) {
            return 0;
        }

        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_string($value) && preg_match('/^(0|[1-9]\d*)$/', $value) === 1) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * @param array<int, Collection|array<string,mixed>> $rows
     *
     * @return array<int,array<string,mixed>>
     */
    private function mapPlayers(array $rows): array
    {
        return array_map(function (Collection|array $row): array {
            $data = CollectionHelper::toArray($row);

            return [
                'token_id' => (int) ($data['token_id'] ?? 0),
                'display_name' => $data['token_display_name'] ?? null,
                'role' => (string) ($data['token_role'] ?? ''),
            ];
        }, $rows);
    }

}

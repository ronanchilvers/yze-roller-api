<?php

declare(strict_types=1);

namespace YZERoller\Api\Service;

use DateTimeImmutable;
use DateTimeZone;
use flight\database\SimplePdo;
use flight\util\Collection;
use Throwable;
use YZERoller\Api\Auth\AuthGuard;
use YZERoller\Api\Response;
use YZERoller\Api\Validation\RequestValidator;

final class GmPlayersListService
{
    public function __construct(
        private readonly SimplePdo $db,
        private readonly AuthGuard $authGuard,
        private readonly RequestValidator $validator
    ) {
    }

    public function listPlayers(?string $authorizationHeader, mixed $sessionIdInput): Response
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

        $tokenSessionId = (int) ($sessionTokenRow['token_session_id'] ?? 0);
        if ($tokenSessionId <= 0) {
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

            $rows = $this->db->fetchAll(
                'SELECT token_id, token_display_name, token_role, token_revoked, token_created, token_last_seen
                 FROM session_tokens
                 WHERE token_session_id = ? AND token_role = ?
                 ORDER BY token_id ASC',
                [$sessionId, 'player']
            );
        } catch (Throwable) {
            return (new Response())->withError(
                Response::ERROR_INTERNAL,
                'Failed to list players.',
                Response::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        $players = array_map(
            fn (Collection|array $row): array => $this->mapPlayer($row),
            $rows
        );

        return (new Response())
            ->withCode(Response::STATUS_OK)
            ->withData([
                'session_id' => $sessionId,
                'players' => $players,
            ]);
    }

    /**
     * @param Collection|array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function mapPlayer(Collection|array $row): array
    {
        $data = $this->toArray($row);
        $revokedAt = $this->toRfc3339Nullable($data['token_revoked'] ?? null);

        return [
            'token_id' => (int) ($data['token_id'] ?? 0),
            'display_name' => is_string($data['token_display_name'] ?? null) ? $data['token_display_name'] : null,
            'role' => (string) ($data['token_role'] ?? 'player'),
            'revoked' => $revokedAt !== null,
            'created_at' => $this->toRfc3339($data['token_created'] ?? null),
            'last_seen_at' => $this->toRfc3339Nullable($data['token_last_seen'] ?? null),
            'revoked_at' => $revokedAt,
        ];
    }

    /**
     * @param Collection|array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function toArray(Collection|array $row): array
    {
        if ($row instanceof Collection) {
            return $row->getData();
        }

        return $row;
    }

    private function toRfc3339(mixed $mysqlDateTime): string
    {
        $formatted = $this->toRfc3339Nullable($mysqlDateTime);
        if ($formatted !== null) {
            return $formatted;
        }

        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    private function toRfc3339Nullable(mixed $mysqlDateTime): ?string
    {
        if (!is_string($mysqlDateTime) || $mysqlDateTime === '') {
            return null;
        }

        $utc = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $mysqlDateTime, $utc);
        if (!$date) {
            $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysqlDateTime, $utc);
        }
        if (!$date) {
            return null;
        }

        return $date->setTimezone($utc)->format('Y-m-d\TH:i:s.v\Z');
    }
}


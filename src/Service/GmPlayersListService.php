<?php

declare(strict_types=1);

namespace YZERoller\Api\Service;

use flight\database\SimplePdo;
use flight\util\Collection;
use Throwable;
use YZERoller\Api\Auth\GmSessionAuthorizer;
use YZERoller\Api\Response;
use YZERoller\Api\Support\CollectionHelper;
use YZERoller\Api\Support\DateTimeFormatter;

final class GmPlayersListService
{
    public function __construct(
        private readonly SimplePdo $db,
        private readonly GmSessionAuthorizer $authorizer,
        private readonly DateTimeFormatter $formatter
    ) {
    }

    public function listPlayers(?string $authorizationHeader, mixed $sessionIdInput): Response
    {
        $auth = $this->authorizer->authorize($authorizationHeader, $sessionIdInput);
        if ($auth instanceof Response) {
            return $auth;
        }

        $sessionId = $auth['sessionId'];

        try {
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
        $data = CollectionHelper::toArray($row);
        $revokedAt = $this->formatter->toRfc3339OrNull($data['token_revoked'] ?? null);

        return [
            'token_id' => (int) ($data['token_id'] ?? 0),
            'display_name' => is_string($data['token_display_name'] ?? null) ? $data['token_display_name'] : null,
            'role' => (string) ($data['token_role'] ?? 'player'),
            'revoked' => $revokedAt !== null,
            'created_at' => $this->formatter->toRfc3339((string) ($data['token_created'] ?? '')),
            'last_seen_at' => $this->formatter->toRfc3339OrNull($data['token_last_seen'] ?? null),
            'revoked_at' => $revokedAt,
        ];
    }
}

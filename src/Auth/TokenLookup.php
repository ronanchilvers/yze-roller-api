<?php

declare(strict_types=1);

namespace YZERoller\Api\Auth;

use flight\database\SimplePdo;
use YZERoller\Api\Support\CollectionHelper;

final class TokenLookup
{
    public function __construct(private readonly SimplePdo $db)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findJoinTokenByOpaqueToken(string $token): ?array
    {
        $tokenHash = BearerToken::hashToken($token);

        $row = $this->db->fetchRow(
            'SELECT join_token_id, join_token_session_id, join_token_hash, join_token_prefix, join_token_revoked, join_token_created, join_token_updated, join_token_last_used
             FROM session_join_tokens
             WHERE join_token_hash = ?',
            [$tokenHash]
        );

        if ($row === null) {
            return null;
        }

        $tokenRow = CollectionHelper::toArray($row);
        $tokenRow['is_revoked'] = $tokenRow['join_token_revoked'] !== null;

        return $tokenRow;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findSessionTokenByOpaqueToken(string $token): ?array
    {
        $tokenHash = BearerToken::hashToken($token);

        $row = $this->db->fetchRow(
            'SELECT token_id, token_session_id, token_role, token_display_name, token_hash, token_prefix, token_revoked, token_created, token_updated, token_last_seen
             FROM session_tokens
             WHERE token_hash = ?',
            [$tokenHash]
        );

        if ($row === null) {
            return null;
        }

        $tokenRow = CollectionHelper::toArray($row);
        $tokenRow['is_revoked'] = $tokenRow['token_revoked'] !== null;

        return $tokenRow;
    }
}

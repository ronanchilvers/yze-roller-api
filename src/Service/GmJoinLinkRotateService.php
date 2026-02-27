<?php

declare(strict_types=1);

namespace YZERoller\Api\Service;

use flight\database\SimplePdo;
use Throwable;
use YZERoller\Api\Auth\BearerToken;
use YZERoller\Api\Auth\GmSessionAuthorizer;
use YZERoller\Api\Response;
use YZERoller\Api\Support\DateTimeFormatter;
use YZERoller\Api\Support\JoinLinkBuilder;
use YZERoller\Api\Support\TokenGenerator;

final class GmJoinLinkRotateService
{
    public function __construct(
        private readonly SimplePdo $db,
        private readonly GmSessionAuthorizer $authorizer,
        private readonly TokenGenerator $tokenGenerator,
        private readonly JoinLinkBuilder $linkBuilder,
        private readonly DateTimeFormatter $formatter
    ) {
    }

    public function rotate(?string $authorizationHeader, mixed $sessionIdInput): Response
    {
        $auth = $this->authorizer->authorize($authorizationHeader, $sessionIdInput);
        if ($auth instanceof Response) {
            return $auth;
        }

        $sessionId = $auth['sessionId'];

        try {
            $rotatedAtMysql = $this->formatter->toMysqlDateTime();
            $joinToken = $this->tokenGenerator->generate();

            $this->db->transaction(function (SimplePdo $db) use ($sessionId, $rotatedAtMysql, $joinToken): void {
                $db->update(
                    'session_join_tokens',
                    [
                        'join_token_revoked' => $rotatedAtMysql,
                        'join_token_updated' => $rotatedAtMysql,
                    ],
                    'join_token_session_id = ? AND join_token_revoked IS NULL',
                    [$sessionId]
                );

                $db->insert('session_join_tokens', [
                    'join_token_session_id' => $sessionId,
                    'join_token_hash' => BearerToken::hashToken($joinToken),
                    'join_token_prefix' => substr($joinToken, 0, 12),
                    'join_token_created' => $rotatedAtMysql,
                    'join_token_updated' => $rotatedAtMysql,
                    'join_token_revoked' => null,
                    'join_token_last_used' => null,
                ]);
            });
        } catch (Throwable) {
            return (new Response())->withError(
                Response::ERROR_INTERNAL,
                'Failed to rotate join link.',
                Response::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return (new Response())
            ->withCode(Response::STATUS_OK)
            ->withData([
                'session_id' => $sessionId,
                'join_link' => $this->linkBuilder->build($joinToken),
                'rotated_at' => $this->formatter->toRfc3339($rotatedAtMysql),
            ]);
    }
}

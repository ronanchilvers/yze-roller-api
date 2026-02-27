<?php

declare(strict_types=1);

namespace YZERoller\Api\Auth;

use flight\database\SimplePdo;
use YZERoller\Api\Response;
use YZERoller\Api\Validation\RequestValidator;

final class GmSessionAuthorizer
{
    public function __construct(
        private readonly SimplePdo $db,
        private readonly AuthGuard $authGuard,
        private readonly RequestValidator $validator
    ) {
    }

    /**
     * @return array{sessionTokenRow: array<string,mixed>, sessionId: int, actorTokenId: int}|Response
     */
    public function authorize(
        ?string $authorizationHeader,
        mixed $sessionIdInput
    ): array|Response {
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

        return [
            'sessionTokenRow' => $sessionTokenRow,
            'sessionId' => $sessionId,
            'actorTokenId' => $actorTokenId,
        ];
    }
}

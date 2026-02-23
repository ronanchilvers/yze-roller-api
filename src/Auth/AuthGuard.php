<?php

declare(strict_types=1);

namespace YZERoller\Api\Auth;

use YZERoller\Api\Response;

final class AuthGuard
{
    public function __construct(private readonly TokenLookup $tokenLookup)
    {
    }

    /**
     * @return array<string,mixed>|Response
     */
    public function requireJoinToken(?string $authorizationHeader): array|Response
    {
        if ($authorizationHeader === null || trim($authorizationHeader) === '') {
            return $this->error(
                Response::ERROR_TOKEN_MISSING,
                'Authorization token is missing.'
            );
        }

        $token = BearerToken::parseAuthorizationHeader($authorizationHeader);
        if ($token === null) {
            return $this->error(
                Response::ERROR_TOKEN_INVALID,
                'Authorization token is invalid.'
            );
        }

        $tokenRow = $this->tokenLookup->findJoinTokenByOpaqueToken($token);
        if ($tokenRow === null) {
            return $this->error(
                Response::ERROR_TOKEN_INVALID,
                'Authorization token is invalid.'
            );
        }

        if (($tokenRow['is_revoked'] ?? false) === true) {
            return $this->error(
                Response::ERROR_JOIN_TOKEN_REVOKED,
                'Join token has been revoked.'
            );
        }

        return $tokenRow;
    }

    /**
     * @return array<string,mixed>|Response
     */
    public function requireSessionToken(?string $authorizationHeader): array|Response
    {
        if ($authorizationHeader === null || trim($authorizationHeader) === '') {
            return $this->error(
                Response::ERROR_TOKEN_MISSING,
                'Authorization token is missing.'
            );
        }

        $token = BearerToken::parseAuthorizationHeader($authorizationHeader);
        if ($token === null) {
            return $this->error(
                Response::ERROR_TOKEN_INVALID,
                'Authorization token is invalid.'
            );
        }

        $tokenRow = $this->tokenLookup->findSessionTokenByOpaqueToken($token);
        if ($tokenRow === null) {
            return $this->error(
                Response::ERROR_TOKEN_INVALID,
                'Authorization token is invalid.'
            );
        }

        if (($tokenRow['is_revoked'] ?? false) === true) {
            return $this->error(
                Response::ERROR_TOKEN_REVOKED,
                'Authorization token has been revoked.'
            );
        }

        return $tokenRow;
    }

    public function requireGmRole(array $sessionTokenRow): ?Response
    {
        if (($sessionTokenRow['token_role'] ?? null) === 'gm') {
            return null;
        }

        return $this->error(
            Response::ERROR_ROLE_FORBIDDEN,
            'GM role is required for this endpoint.'
        );
    }

    private function error(string $code, string $message): Response
    {
        return (new Response())->withError($code, $message);
    }
}

<?php

declare(strict_types=1);

namespace YZERoller\Api\Service;

use DateTimeImmutable;
use DateTimeZone;
use flight\database\SimplePdo;
use Throwable;
use YZERoller\Api\Auth\AuthGuard;
use YZERoller\Api\Auth\BearerToken;
use YZERoller\Api\Response;
use YZERoller\Api\Validation\RequestValidator;

final class GmJoinLinkRotateService
{
    /**
     * @var callable():string
     */
    private $tokenGenerator;

    /**
     * @var callable():DateTimeImmutable
     */
    private $nowProvider;

    /**
     * @param callable():string|null $tokenGenerator
     * @param callable():DateTimeImmutable|null $nowProvider
     */
    public function __construct(
        private readonly SimplePdo $db,
        private readonly AuthGuard $authGuard,
        private readonly RequestValidator $validator,
        private readonly string $siteUrl,
        ?callable $tokenGenerator = null,
        ?callable $nowProvider = null
    ) {
        $this->tokenGenerator = $tokenGenerator ?? static function (): string {
            return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        };
        $this->nowProvider = $nowProvider ?? static function (): DateTimeImmutable {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        };
    }

    public function rotate(?string $authorizationHeader, mixed $sessionIdInput): Response
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

            $rotatedAtMysql = $this->nowMysql();
            $joinToken = $this->generateOpaqueToken();

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
                'join_link' => $this->buildJoinLink($joinToken),
                'rotated_at' => $this->toRfc3339($rotatedAtMysql),
            ]);
    }

    private function generateOpaqueToken(): string
    {
        $token = ($this->tokenGenerator)();
        if ($token === '') {
            throw new \RuntimeException('Token generator returned an empty token.');
        }

        return $token;
    }

    private function buildJoinLink(string $joinToken): string
    {
        return rtrim($this->siteUrl, '/') . '/join#join=' . $joinToken;
    }

    private function nowMysql(): string
    {
        return ($this->nowProvider)()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function toRfc3339(string $mysqlDateTime): string
    {
        $utc = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $mysqlDateTime, $utc);
        if (!$date) {
            $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysqlDateTime, $utc);
        }
        if (!$date) {
            $date = ($this->nowProvider)();
        }

        return $date->setTimezone($utc)->format('Y-m-d\TH:i:s.v\Z');
    }
}


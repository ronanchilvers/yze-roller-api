<?php

declare(strict_types=1);

namespace YZERoller\Api\Service;

use DateTimeImmutable;
use DateTimeZone;
use flight\database\SimplePdo;
use PDO;
use Throwable;
use YZERoller\Api\Auth\AuthGuard;
use YZERoller\Api\Response;
use YZERoller\Api\Validation\RequestValidator;

final class GmSessionJoiningService
{
    /**
     * @var callable():DateTimeImmutable
     */
    private $nowProvider;

    /**
     * @param callable():DateTimeImmutable|null $nowProvider
     */
    public function __construct(
        private readonly SimplePdo $db,
        private readonly AuthGuard $authGuard,
        private readonly RequestValidator $validator,
        ?callable $nowProvider = null
    ) {
        $this->nowProvider = $nowProvider ?? static function (): DateTimeImmutable {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        };
    }

    /**
     * @param array<string,mixed> $body
     */
    public function updateJoining(?string $authorizationHeader, mixed $sessionIdInput, array $body): Response
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

        $joiningEnabled = $this->validator->validateJoiningTogglePayload($body);
        if ($joiningEnabled instanceof Response) {
            return $joiningEnabled;
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

            $updatedAtMysql = $this->nowMysql();
            $stateValue = $joiningEnabled ? 'true' : 'false';

            $this->db->transaction(function (SimplePdo $db) use ($sessionId, $stateValue, $updatedAtMysql): void {
                $statement = $db->runQuery(
                    'SELECT state_id FROM session_state WHERE state_session_id = ? AND state_name = ? FOR UPDATE',
                    [$sessionId, 'joining_enabled']
                );
                $row = $statement->fetch(PDO::FETCH_ASSOC);
                $stateId = $this->extractStateId($row);

                if ($stateId !== null) {
                    $db->update(
                        'session_state',
                        [
                            'state_value' => $stateValue,
                            'state_updated' => $updatedAtMysql,
                        ],
                        'state_id = ?',
                        [$stateId]
                    );

                    return;
                }

                $db->insert('session_state', [
                    'state_session_id' => $sessionId,
                    'state_name' => 'joining_enabled',
                    'state_value' => $stateValue,
                    'state_created' => $updatedAtMysql,
                    'state_updated' => $updatedAtMysql,
                ]);
            });
        } catch (Throwable) {
            return (new Response())->withError(
                Response::ERROR_INTERNAL,
                'Failed to update joining state.',
                Response::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return (new Response())
            ->withCode(Response::STATUS_OK)
            ->withData([
                'session_id' => $sessionId,
                'joining_enabled' => $joiningEnabled,
                'updated_at' => $this->toRfc3339($updatedAtMysql),
            ]);
    }

    /**
     * @param array<string,mixed>|false $row
     */
    private function extractStateId(array|false $row): ?int
    {
        if (!is_array($row) || !array_key_exists('state_id', $row)) {
            return null;
        }

        $value = $row['state_id'];
        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
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


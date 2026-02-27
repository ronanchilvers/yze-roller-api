<?php

declare(strict_types=1);

namespace YZERoller\Api\Service;

use flight\database\SimplePdo;
use PDO;
use Throwable;
use YZERoller\Api\Auth\GmSessionAuthorizer;
use YZERoller\Api\Response;
use YZERoller\Api\Support\DateTimeFormatter;
use YZERoller\Api\Support\SessionStateParser;
use YZERoller\Api\Validation\RequestValidator;

final class GmSessionJoiningService
{
    public function __construct(
        private readonly SimplePdo $db,
        private readonly GmSessionAuthorizer $authorizer,
        private readonly RequestValidator $validator,
        private readonly DateTimeFormatter $formatter
    ) {
    }

    /**
     * @param array<string,mixed> $body
     */
    public function updateJoining(?string $authorizationHeader, mixed $sessionIdInput, array $body): Response
    {
        $joiningEnabled = $this->validator->validateJoiningTogglePayload($body);
        if ($joiningEnabled instanceof Response) {
            return $joiningEnabled;
        }

        $auth = $this->authorizer->authorize($authorizationHeader, $sessionIdInput);
        if ($auth instanceof Response) {
            return $auth;
        }

        $sessionId = $auth['sessionId'];
        $updatedAtMysql = $this->formatter->toMysqlDateTime();
        $stateValue = $joiningEnabled ? 'true' : 'false';

        try {
            $this->db->transaction(function (SimplePdo $db) use ($sessionId, $stateValue, $updatedAtMysql): void {
                $statement = $db->runQuery(
                    'SELECT state_id FROM session_state WHERE state_session_id = ? AND state_name = ? FOR UPDATE',
                    [$sessionId, 'joining_enabled']
                );
                $row = $statement->fetch(PDO::FETCH_ASSOC);
                $stateId = SessionStateParser::extractStateId($row);

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
                'updated_at' => $this->formatter->toRfc3339($updatedAtMysql),
            ]);
    }
}

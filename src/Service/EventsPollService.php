<?php

declare(strict_types=1);

namespace YZERoller\Api\Service;

use flight\database\SimplePdo;
use flight\util\Collection;
use Throwable;
use YZERoller\Api\Auth\AuthGuard;
use YZERoller\Api\Response;
use YZERoller\Api\Support\CollectionHelper;
use YZERoller\Api\Support\DateTimeFormatter;
use YZERoller\Api\Validation\RequestValidator;

final class EventsPollService
{
    public function __construct(
        private readonly SimplePdo $db,
        private readonly AuthGuard $authGuard,
        private readonly RequestValidator $validator,
        private readonly DateTimeFormatter $formatter
    ) {
    }

    /**
     * @param array<string,mixed> $query
     */
    public function poll(?string $authorizationHeader, array $query): Response
    {
        $sessionTokenRow = $this->authGuard->requireSessionToken($authorizationHeader);
        if ($sessionTokenRow instanceof Response) {
            return $sessionTokenRow;
        }

        $sinceId = $this->validator->validateSinceId($query['since_id'] ?? 0);
        if ($sinceId instanceof Response) {
            return $sinceId;
        }

        $limit = $this->validator->validateLimit($query['limit'] ?? null);
        if ($limit instanceof Response) {
            return $limit;
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
            $sql = sprintf(
                'SELECT
                    e.event_id,
                    e.event_type,
                    e.event_session_id,
                    e.event_created,
                    e.event_payload_json,
                    e.event_actor_token_id,
                    st.token_display_name AS actor_display_name,
                    st.token_role AS actor_role
                 FROM events e
                 LEFT JOIN session_tokens st ON st.token_id = e.event_actor_token_id
                 WHERE e.event_session_id = ? AND e.event_id > ?
                 ORDER BY e.event_id ASC
                 LIMIT %d',
                $limit
            );

            $rows = $this->db->fetchAll(
                $sql,
                [$sessionId, $sinceId]
            );
        } catch (Throwable) {
            return (new Response())->withError(
                Response::ERROR_INTERNAL,
                'Failed to poll events.',
                Response::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        if (count($rows) === 0) {
            return (new Response())->withNoContent();
        }

        $events = array_map(fn(Collection|array $row): array => $this->mapEventRow($row), $rows);
        $nextSinceId = (int) $events[array_key_last($events)]['id'];

        return (new Response())
            ->withCode(Response::STATUS_OK)
            ->withData([
                'events' => $events,
                'next_since_id' => $nextSinceId,
            ]);
    }

    /**
     * @param Collection|array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function mapEventRow(Collection|array $row): array
    {
        $data = CollectionHelper::toArray($row);
        $actorTokenId = $data['event_actor_token_id'] ?? null;

        $actor = null;
        if ($actorTokenId !== null) {
            $actor = [
                'token_id' => (int) $actorTokenId,
                'display_name' => $data['actor_display_name'] ?? null,
                'role' => $data['actor_role'] ?? null,
            ];
        }

        return [
            'id' => (int) ($data['event_id'] ?? 0),
            'type' => (string) ($data['event_type'] ?? ''),
            'session_id' => (int) ($data['event_session_id'] ?? 0),
            'occurred_at' => $this->formatter->toRfc3339((string) ($data['event_created'] ?? '')),
            'actor' => $actor,
            'payload' => $this->decodePayload($data['event_payload_json'] ?? null),
        ];
    }

    /**
     * @param mixed $payloadJson
     *
     * @return array<string,mixed>
     */
    private function decodePayload(mixed $payloadJson): array
    {
        if (!is_string($payloadJson) || $payloadJson === '') {
            return [];
        }

        $decoded = json_decode($payloadJson, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

}

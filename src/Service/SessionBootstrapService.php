<?php

declare(strict_types=1);

namespace YZERoller\Api\Service;

use flight\database\SimplePdo;
use Throwable;
use YZERoller\Api\Auth\BearerToken;
use YZERoller\Api\Response;
use YZERoller\Api\Support\CollectionHelper;
use YZERoller\Api\Support\DateTimeFormatter;
use YZERoller\Api\Support\JoinLinkBuilder;
use YZERoller\Api\Support\TokenGenerator;
use YZERoller\Api\Validation\RequestValidator;

final class SessionBootstrapService
{
    public function __construct(
        private readonly SimplePdo $db,
        private readonly RequestValidator $validator,
        private readonly TokenGenerator $tokenGenerator,
        private readonly JoinLinkBuilder $linkBuilder,
        private readonly DateTimeFormatter $formatter
    ) {
    }

    /**
     * @param array<string,mixed> $body
     */
    public function createSession(array $body): Response
    {
        $sessionName = $this->validator->validateSessionName($body['session_name'] ?? null);
        if ($sessionName instanceof Response) {
            return $sessionName;
        }

        try {
            /** @var array<string,mixed> $payload */
            $payload = $this->db->transaction(function (SimplePdo $db) use ($sessionName): array {
                $sessionId = (int) $db->insert('sessions', [
                    'session_name' => $sessionName,
                    'session_joining_enabled' => 1,
                ]);

                $gmToken = $this->tokenGenerator->generate();
                $joinToken = $this->tokenGenerator->generate();

                $db->insert('session_tokens', [
                    'token_session_id' => $sessionId,
                    'token_role' => 'gm',
                    'token_display_name' => null,
                    'token_hash' => BearerToken::hashToken($gmToken),
                    'token_prefix' => substr($gmToken, 0, 12),
                ]);

                $db->insert('session_join_tokens', [
                    'join_token_session_id' => $sessionId,
                    'join_token_hash' => BearerToken::hashToken($joinToken),
                    'join_token_prefix' => substr($joinToken, 0, 12),
                ]);

                $db->insert('session_state', [
                    [
                        'state_session_id' => $sessionId,
                        'state_name' => 'scene_strain',
                        'state_value' => '0',
                    ],
                    [
                        'state_session_id' => $sessionId,
                        'state_name' => 'joining_enabled',
                        'state_value' => 'true',
                    ],
                ]);

                $sessionRow = $db->fetchRow(
                    'SELECT session_created FROM sessions WHERE session_id = ?',
                    [$sessionId]
                );

                $data = $sessionRow !== null ? CollectionHelper::toArray($sessionRow) : [];

                return [
                    'session_id' => $sessionId,
                    'session_name' => $sessionName,
                    'joining_enabled' => true,
                    'gm_token' => $gmToken,
                    'join_link' => $this->linkBuilder->build($joinToken),
                    'created_at' => $this->formatter->toRfc3339((string) ($data['session_created'] ?? '')),
                ];
            });
        } catch (Throwable) {
            return (new Response())->withError(
                Response::ERROR_INTERNAL,
                'Failed to create session.',
                Response::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return (new Response())
            ->withCode(Response::STATUS_CREATED)
            ->withData($payload);
    }
}

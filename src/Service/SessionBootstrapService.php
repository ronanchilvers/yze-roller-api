<?php

declare(strict_types=1);

namespace YZERoller\Api\Service;

use DateTimeImmutable;
use DateTimeZone;
use flight\database\SimplePdo;
use flight\util\Collection;
use Throwable;
use YZERoller\Api\Auth\BearerToken;
use YZERoller\Api\Response;
use YZERoller\Api\Validation\RequestValidator;

final class SessionBootstrapService
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

                $gmToken = $this->generateOpaqueToken();
                $joinToken = $this->generateOpaqueToken();

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

                return [
                    'session_id' => $sessionId,
                    'session_name' => $sessionName,
                    'joining_enabled' => true,
                    'gm_token' => $gmToken,
                    'join_link' => $this->buildJoinLink($joinToken),
                    'created_at' => $this->toRfc3339($sessionRow),
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

    private function toRfc3339(Collection|array|null $sessionRow): string
    {
        $timestamp = null;
        if ($sessionRow instanceof Collection) {
            $timestamp = $sessionRow['session_created'] ?? null;
        } elseif (is_array($sessionRow)) {
            $timestamp = $sessionRow['session_created'] ?? null;
        }

        if (!is_string($timestamp) || $timestamp === '') {
            return ($this->nowProvider)()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        }

        $utc = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $timestamp, $utc);
        if (!$date) {
            $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $timestamp, $utc);
        }
        if (!$date) {
            $date = ($this->nowProvider)();
        }

        return $date->setTimezone($utc)->format('Y-m-d\TH:i:s.v\Z');
    }
}

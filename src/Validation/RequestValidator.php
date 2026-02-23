<?php

declare(strict_types=1);

namespace YZERoller\Api\Validation;

use YZERoller\Api\Response;

final class RequestValidator
{
    public const DEFAULT_LIMIT = 10;
    public const MIN_LIMIT = 1;
    public const MAX_LIMIT = 100;

    public function validateSinceId(mixed $value): int|Response
    {
        if (is_int($value)) {
            if ($value >= 0) {
                return $value;
            }

            return $this->validationError(
                'Query parameter since_id must be an integer >= 0.',
                ['field' => 'since_id']
            );
        }

        if (is_string($value) && preg_match('/^(0|[1-9]\d*)$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return $this->validationError(
            'Query parameter since_id must be an integer >= 0.',
            ['field' => 'since_id']
        );
    }

    public function validateLimit(mixed $value, int $default = self::DEFAULT_LIMIT): int|Response
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $parsed = null;
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9]\d*)$/', trim($value)) === 1) {
            $parsed = (int) trim($value);
        }

        if ($parsed === null || $parsed < self::MIN_LIMIT || $parsed > self::MAX_LIMIT) {
            return $this->validationError(
                sprintf(
                    'Query parameter limit must be an integer between %d and %d.',
                    self::MIN_LIMIT,
                    self::MAX_LIMIT
                ),
                ['field' => 'limit']
            );
        }

        return $parsed;
    }

    public function validateDisplayName(mixed $value): string|Response
    {
        if (!is_string($value)) {
            return $this->validationError(
                'display_name is required and must be a string.',
                ['field' => 'display_name']
            );
        }

        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            return $this->validationError(
                'display_name must not contain control characters.',
                ['field' => 'display_name']
            );
        }

        $displayName = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($displayName) : strlen($displayName);
        if ($length < 1 || $length > 64) {
            return $this->validationError(
                'display_name must be between 1 and 64 characters.',
                ['field' => 'display_name']
            );
        }

        return $displayName;
    }

    public function validateSessionName(mixed $value): string|Response
    {
        if (!is_string($value)) {
            return $this->validationError(
                'session_name is required and must be a string.',
                ['field' => 'session_name']
            );
        }

        $sessionName = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($sessionName) : strlen($sessionName);
        if ($length < 1 || $length > 128) {
            return $this->validationError(
                'session_name must be between 1 and 128 characters.',
                ['field' => 'session_name']
            );
        }

        return $sessionName;
    }

    /**
     * @param array<string,mixed> $body
     *
     * @return array<string,mixed>|Response
     */
    public function validateEventSubmitPayload(array $body): array|Response
    {
        $allowedTopLevelKeys = ['type', 'payload'];
        $actualTopLevelKeys = array_keys($body);
        sort($allowedTopLevelKeys);
        sort($actualTopLevelKeys);
        if ($actualTopLevelKeys !== $allowedTopLevelKeys) {
            return $this->validationError(
                'Event body must contain only type and payload.',
                ['fields' => array_keys($body)]
            );
        }

        if (!is_string($body['type'])) {
            return $this->validationError(
                'Event type must be a string.',
                ['field' => 'type']
            );
        }

        $type = $body['type'];
        if (!in_array($type, ['roll', 'push'], true)) {
            return (new Response())->withError(
                Response::ERROR_EVENT_TYPE_UNSUPPORTED,
                'Event type is not supported.',
                Response::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        if (!is_array($body['payload'])) {
            return $this->validationError(
                'Event payload must be an object.',
                ['field' => 'payload']
            );
        }

        $payload = $body['payload'];
        $validatedPayload = $type === 'roll'
            ? $this->validateRollPayload($payload)
            : $this->validatePushPayload($payload);

        if ($validatedPayload instanceof Response) {
            return $validatedPayload;
        }

        return [
            'type' => $type,
            'payload' => $validatedPayload,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>|Response
     */
    private function validateRollPayload(array $payload): array|Response
    {
        $allowedKeys = ['successes', 'banes'];
        if (!$this->hasExactlyKeys($payload, $allowedKeys)) {
            return $this->validationError(
                'roll payload must contain only successes and banes.',
                ['field' => 'payload']
            );
        }

        $successes = $this->validateIntegerRangeField($payload['successes'], 'payload.successes', 0, 99);
        if ($successes instanceof Response) {
            return $successes;
        }

        $banes = $this->validateIntegerRangeField($payload['banes'], 'payload.banes', 0, 99);
        if ($banes instanceof Response) {
            return $banes;
        }

        return [
            'successes' => $successes,
            'banes' => $banes,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>|Response
     */
    private function validatePushPayload(array $payload): array|Response
    {
        $allowedKeys = ['successes', 'banes', 'strain'];
        if (!$this->hasExactlyKeys($payload, $allowedKeys)) {
            return $this->validationError(
                'push payload must contain only successes, banes, and strain.',
                ['field' => 'payload']
            );
        }

        $successes = $this->validateIntegerRangeField($payload['successes'], 'payload.successes', 0, 99);
        if ($successes instanceof Response) {
            return $successes;
        }

        $banes = $this->validateIntegerRangeField($payload['banes'], 'payload.banes', 0, 99);
        if ($banes instanceof Response) {
            return $banes;
        }

        if (!is_bool($payload['strain'])) {
            return $this->validationError(
                'payload.strain must be a boolean.',
                ['field' => 'payload.strain']
            );
        }

        return [
            'successes' => $successes,
            'banes' => $banes,
            'strain' => $payload['strain'],
        ];
    }

    private function validateIntegerRangeField(mixed $value, string $field, int $min, int $max): int|Response
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            return $this->validationError(
                sprintf('%s must be an integer between %d and %d.', $field, $min, $max),
                ['field' => $field]
            );
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<int,string> $requiredKeys
     */
    private function hasExactlyKeys(array $payload, array $requiredKeys): bool
    {
        $actualKeys = array_keys($payload);
        sort($actualKeys);
        sort($requiredKeys);

        return $actualKeys === $requiredKeys;
    }

    /**
     * @param array<string,mixed>|null $details
     */
    private function validationError(string $message, ?array $details = null): Response
    {
        return (new Response())->withError(
            Response::ERROR_VALIDATION_ERROR,
            $message,
            Response::STATUS_UNPROCESSABLE_ENTITY,
            $details
        );
    }
}

<?php

declare(strict_types=1);

namespace YZERoller\Api;

class Response
{
    public const STATUS_OK = 200;
    public const STATUS_CREATED = 201;
    public const STATUS_NO_CONTENT = 204;
    public const STATUS_BAD_REQUEST = 400;
    public const STATUS_UNAUTHORIZED = 401;
    public const STATUS_FORBIDDEN = 403;
    public const STATUS_NOT_FOUND = 404;
    public const STATUS_CONFLICT = 409;
    public const STATUS_UNPROCESSABLE_ENTITY = 422;
    public const STATUS_TOO_MANY_REQUESTS = 429;
    public const STATUS_INTERNAL_SERVER_ERROR = 500;

    // Backward-compatible aliases.
    public const CODE_OK = self::STATUS_OK;
    public const CODE_OK_NO_BODY = self::STATUS_NO_CONTENT;
    public const CODE_BAD_REQUEST = self::STATUS_BAD_REQUEST;
    public const CODE_TOKEN_MISSING = self::STATUS_UNAUTHORIZED;
    public const CODE_TOKEN_INVALID = self::STATUS_UNAUTHORIZED;
    public const CODE_TOKEN_REVOKED = self::STATUS_FORBIDDEN;
    public const CODE_ROLE_FORBIDDEN = self::STATUS_FORBIDDEN;
    public const CODE_JOIN_DISABLED = self::STATUS_FORBIDDEN;
    public const CODE_VALIDATION_ERROR = self::STATUS_UNPROCESSABLE_ENTITY;
    public const CODE_EVENT_TYPE_UNSUPPORTED = self::STATUS_UNPROCESSABLE_ENTITY;
    public const CODE_RATE_LIMITED = self::STATUS_TOO_MANY_REQUESTS;

    public const ERROR_TOKEN_MISSING = 'TOKEN_MISSING';
    public const ERROR_TOKEN_INVALID = 'TOKEN_INVALID';
    public const ERROR_TOKEN_REVOKED = 'TOKEN_REVOKED';
    public const ERROR_ROLE_FORBIDDEN = 'ROLE_FORBIDDEN';
    public const ERROR_SESSION_NOT_FOUND = 'SESSION_NOT_FOUND';
    public const ERROR_JOIN_DISABLED = 'JOIN_DISABLED';
    public const ERROR_JOIN_TOKEN_REVOKED = 'JOIN_TOKEN_REVOKED';
    public const ERROR_VALIDATION_ERROR = 'VALIDATION_ERROR';
    public const ERROR_EVENT_TYPE_UNSUPPORTED = 'EVENT_TYPE_UNSUPPORTED';
    public const ERROR_RATE_LIMITED = 'RATE_LIMITED';
    public const ERROR_CONFLICT = 'CONFLICT';
    public const ERROR_INTERNAL = 'INTERNAL_ERROR';

    protected int $code = self::STATUS_OK;
    protected ?string $errorCode = null;
    protected ?string $errorMessage = null;
    protected ?array $errorDetails = null;

    protected array $data = [];

    public function withCode(int $code): static
    {
        $this->code = $code;
        if ($code < self::STATUS_BAD_REQUEST) {
            $this->errorCode = null;
            $this->errorMessage = null;
            $this->errorDetails = null;
        } elseif ($this->errorCode === null) {
            $this->errorCode = self::defaultErrorCodeForStatus($code);
        }

        return $this;
    }

    public function withError(
        string $errorCode,
        string $message,
        ?int $code = null,
        ?array $details = null
    ): static {
        $this->errorCode = $errorCode;
        $this->errorMessage = $message;
        $this->errorDetails = $details;
        $this->code = $code ?? self::defaultStatusForErrorCode($errorCode);

        return $this;
    }

    public function withData(array $data): static
    {
        $this->data = $data;
        if ($this->code === self::STATUS_NO_CONTENT) {
            $this->code = self::STATUS_OK;
        }

        return $this;
    }

    public function withKey(string $key, mixed $value): static
    {
        $this->data[$key] = $value;
        if ($this->code === self::STATUS_NO_CONTENT) {
            $this->code = self::STATUS_OK;
        }

        return $this;
    }

    public function withNoContent(): static
    {
        $this->code = self::STATUS_NO_CONTENT;
        $this->data = [];
        $this->errorCode = null;
        $this->errorMessage = null;
        $this->errorDetails = null;

        return $this;
    }

    public function code(): int
    {
        return $this->code;
    }

    public function data(): array|object|null
    {
        if ($this->code === self::STATUS_NO_CONTENT) {
            return null;
        }

        if ($this->code >= self::STATUS_BAD_REQUEST) {
            $error = [
                'code' => $this->errorCode ?? self::defaultErrorCodeForStatus($this->code),
                'message' => $this->errorMessage ?? 'Request failed.',
            ];
            if ($this->errorDetails !== null) {
                $error['details'] = $this->errorDetails;
            }

            return ['error' => $error];
        }

        if (empty($this->data)) {
            return (object) [];
        }

        return $this->data;
    }

    public function reset(): void
    {
        $this->code = self::STATUS_OK;
        $this->errorCode = null;
        $this->errorMessage = null;
        $this->errorDetails = null;
        $this->data = [];
    }

    private static function defaultErrorCodeForStatus(int $status): string
    {
        return match ($status) {
            self::STATUS_UNAUTHORIZED => self::ERROR_TOKEN_INVALID,
            self::STATUS_FORBIDDEN => self::ERROR_ROLE_FORBIDDEN,
            self::STATUS_NOT_FOUND => self::ERROR_SESSION_NOT_FOUND,
            self::STATUS_CONFLICT => self::ERROR_CONFLICT,
            self::STATUS_UNPROCESSABLE_ENTITY => self::ERROR_VALIDATION_ERROR,
            self::STATUS_TOO_MANY_REQUESTS => self::ERROR_RATE_LIMITED,
            default => self::ERROR_INTERNAL,
        };
    }

    private static function defaultStatusForErrorCode(string $errorCode): int
    {
        return match ($errorCode) {
            self::ERROR_TOKEN_MISSING,
            self::ERROR_TOKEN_INVALID => self::STATUS_UNAUTHORIZED,
            self::ERROR_TOKEN_REVOKED,
            self::ERROR_ROLE_FORBIDDEN,
            self::ERROR_JOIN_DISABLED,
            self::ERROR_JOIN_TOKEN_REVOKED => self::STATUS_FORBIDDEN,
            self::ERROR_SESSION_NOT_FOUND => self::STATUS_NOT_FOUND,
            self::ERROR_CONFLICT => self::STATUS_CONFLICT,
            self::ERROR_VALIDATION_ERROR,
            self::ERROR_EVENT_TYPE_UNSUPPORTED => self::STATUS_UNPROCESSABLE_ENTITY,
            self::ERROR_RATE_LIMITED => self::STATUS_TOO_MANY_REQUESTS,
            default => self::STATUS_BAD_REQUEST,
        };
    }
}

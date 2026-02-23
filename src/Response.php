<?php

declare(strict_types=1);

namespace YZERoller\Api;

class Response
{
    public const CODE_OK                       = 200;
    public const CODE_OK_NO_BODY               = 204;
    public const CODE_BAD_REQUEST              = 400;
    public const CODE_TOKEN_MISSING            = 401;
    public const CODE_TOKEN_INVALID            = 401;
    public const CODE_TOKEN_REVOKED            = 403;
    public const CODE_ROLE_FORBIDDEN           = 403;
    public const CODE_JOIN_DISABLED            = 403;
    public const CODE_VALIDATION_ERROR         = 422;
    public const CODE_EVENT_TYPE_UNSUPPORTED   = 422;
    public const CODE_RATE_LIMITED             = 429;

    protected ?int $code = null;
    protected ?string $error = null;

    protected array $data = [];

    public function __construct()
    {
        $this->code = static::CODE_OK;
    }

    public function withCode(int $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function withError(string $error, ?int $code = null): static
    {
        if (in_array($this->code, [static::CODE_OK, static::CODE_OK_NO_BODY])) {
            $this->code = $code ?? static::CODE_BAD_REQUEST;
        }
        $this->error = $error;

        return $this;
    }

    public function withData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function withKey(string $key, mixed $value): static
    {
        $this->data[$key] = $value;

        return $this;
    }

    public function code(): int
    {
        switch ($this->code) {
            case static::CODE_OK:
            case static::CODE_OK_NO_BODY:
                return (empty($this->data) ? static::CODE_OK_NO_BODY : static::CODE_OK);

            default:
                return $this->code;
        }
    }

    public function data(): ?array
    {
        switch ($this->code) {
            case static::CODE_OK:
            case static::CODE_OK_NO_BODY:
                if (empty($this->data)) {
                    return null;
                }
                return $this->data;

            default:
                return [
                    'code' => $this->code,
                    'message' => $this->error,
                ];
        }
    }

    public function reset(): void
    {
        $this->code = static::CODE_OK;
        $this->error = null;
        $this->data = [];
    }
}

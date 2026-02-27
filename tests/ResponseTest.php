<?php

declare(strict_types=1);

namespace YZERoller\Api\Tests;

use PHPUnit\Framework\TestCase;
use YZERoller\Api\Response;

final class ResponseTest extends TestCase
{
    public function testDefaultResponseIs200WithEmptyJsonObjectBody(): void
    {
        $response = new Response();

        self::assertSame(Response::STATUS_OK, $response->code());
        self::assertSame('{}', json_encode($response->data()));
    }

    public function testNoContentIsExplicitAndHasNoBody(): void
    {
        $response = (new Response())->withNoContent();

        self::assertSame(Response::STATUS_NO_CONTENT, $response->code());
        self::assertNull($response->data());
    }

    public function testDataAfterNoContentSwitchesBackToOk(): void
    {
        $response = (new Response())
            ->withNoContent()
            ->withData(['ok' => true]);

        self::assertSame(Response::STATUS_OK, $response->code());
        self::assertSame(['ok' => true], $response->data());
    }

    public function testErrorEnvelopeMatchesContractWithoutDetails(): void
    {
        $response = (new Response())->withError(
            Response::ERROR_TOKEN_INVALID,
            'Authorization token is invalid.'
        );

        self::assertSame(Response::STATUS_UNAUTHORIZED, $response->code());
        self::assertSame(
            [
                'error' => [
                    'code' => Response::ERROR_TOKEN_INVALID,
                    'message' => 'Authorization token is invalid.',
                ],
            ],
            $response->data()
        );
    }

    public function testErrorEnvelopeIncludesOptionalDetails(): void
    {
        $response = (new Response())->withError(
            Response::ERROR_VALIDATION_ERROR,
            'Validation failed.',
            null,
            ['field' => 'display_name']
        );

        self::assertSame(Response::STATUS_UNPROCESSABLE_ENTITY, $response->code());
        self::assertSame(
            [
                'error' => [
                    'code' => Response::ERROR_VALIDATION_ERROR,
                    'message' => 'Validation failed.',
                    'details' => ['field' => 'display_name'],
                ],
            ],
            $response->data()
        );
    }

    public function testStatusCanSetDefaultErrorCodeAndMessage(): void
    {
        $response = (new Response())->withCode(Response::STATUS_NOT_FOUND);

        self::assertSame(Response::STATUS_NOT_FOUND, $response->code());
        self::assertSame(
            [
                'error' => [
                    'code' => Response::ERROR_SESSION_NOT_FOUND,
                    'message' => 'Request failed.',
                ],
            ],
            $response->data()
        );
    }

    public function testSettingSuccessCodeClearsPreviousErrorState(): void
    {
        $response = (new Response())
            ->withError(Response::ERROR_RATE_LIMITED, 'Rate limit hit.')
            ->withCode(Response::STATUS_OK);

        self::assertSame(Response::STATUS_OK, $response->code());
        self::assertSame('{}', json_encode($response->data()));
    }

    public function testResetClearsResponseState(): void
    {
        $response = (new Response())
            ->withError(Response::ERROR_CONFLICT, 'Conflict.')
            ->withKey('unused', true);

        $response->reset();

        self::assertSame(Response::STATUS_OK, $response->code());
        self::assertSame('{}', json_encode($response->data()));
    }
}

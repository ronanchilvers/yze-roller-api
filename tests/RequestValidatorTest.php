<?php

declare(strict_types=1);

namespace YZERoller\Api\Tests;

use PHPUnit\Framework\TestCase;
use YZERoller\Api\Response;
use YZERoller\Api\Validation\RequestValidator;

final class RequestValidatorTest extends TestCase
{
    public function testValidateSinceIdAcceptsIntegerAndNumericString(): void
    {
        $validator = new RequestValidator();

        self::assertSame(0, $validator->validateSinceId(0));
        self::assertSame(123, $validator->validateSinceId('123'));
    }

    public function testValidateSinceIdRejectsInvalidValues(): void
    {
        $validator = new RequestValidator();

        $negative = $validator->validateSinceId(-1);
        self::assertInstanceOf(Response::class, $negative);
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $negative->data()['error']['code']);

        $string = $validator->validateSinceId('x');
        self::assertInstanceOf(Response::class, $string);
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $string->data()['error']['code']);
    }

    public function testValidateLimitDefaultsAndAcceptsRange(): void
    {
        $validator = new RequestValidator();

        self::assertSame(RequestValidator::DEFAULT_LIMIT, $validator->validateLimit(null));
        self::assertSame(1, $validator->validateLimit('1'));
        self::assertSame(100, $validator->validateLimit(100));
    }

    public function testValidateLimitRejectsOutOfRangeAndMalformed(): void
    {
        $validator = new RequestValidator();

        $tooLow = $validator->validateLimit(0);
        self::assertInstanceOf(Response::class, $tooLow);
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $tooLow->data()['error']['code']);

        $tooHigh = $validator->validateLimit(101);
        self::assertInstanceOf(Response::class, $tooHigh);
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $tooHigh->data()['error']['code']);

        $bad = $validator->validateLimit('1.5');
        self::assertInstanceOf(Response::class, $bad);
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $bad->data()['error']['code']);
    }

    public function testValidateDisplayNameTrimsAndAcceptsValidValue(): void
    {
        $validator = new RequestValidator();

        self::assertSame('Alice', $validator->validateDisplayName('  Alice  '));
    }

    public function testValidateDisplayNameRejectsInvalidInput(): void
    {
        $validator = new RequestValidator();

        $empty = $validator->validateDisplayName('  ');
        self::assertInstanceOf(Response::class, $empty);
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $empty->data()['error']['code']);

        $control = $validator->validateDisplayName("Bob\n");
        self::assertInstanceOf(Response::class, $control);
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $control->data()['error']['code']);
    }

    public function testValidateSessionNameTrimsAndAcceptsValidValue(): void
    {
        $validator = new RequestValidator();

        self::assertSame('Streetwise Night', $validator->validateSessionName('  Streetwise Night  '));
    }

    public function testValidateSessionNameRejectsInvalidInput(): void
    {
        $validator = new RequestValidator();

        $missing = $validator->validateSessionName(null);
        self::assertInstanceOf(Response::class, $missing);
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $missing->data()['error']['code']);

        $empty = $validator->validateSessionName('   ');
        self::assertInstanceOf(Response::class, $empty);
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $empty->data()['error']['code']);

        $long = $validator->validateSessionName(str_repeat('x', 129));
        self::assertInstanceOf(Response::class, $long);
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $long->data()['error']['code']);
    }

    public function testValidateEventSubmitPayloadAcceptsRoll(): void
    {
        $validator = new RequestValidator();

        $result = $validator->validateEventSubmitPayload([
            'type' => 'roll',
            'payload' => [
                'successes' => 2,
                'banes' => 1,
            ],
        ]);

        self::assertIsArray($result);
        self::assertSame('roll', $result['type']);
        self::assertSame(2, $result['payload']['successes']);
        self::assertSame(1, $result['payload']['banes']);
    }

    public function testValidateEventSubmitPayloadAcceptsPush(): void
    {
        $validator = new RequestValidator();

        $result = $validator->validateEventSubmitPayload([
            'type' => 'push',
            'payload' => [
                'successes' => 2,
                'banes' => 1,
                'strain' => true,
            ],
        ]);

        self::assertIsArray($result);
        self::assertSame('push', $result['type']);
        self::assertTrue($result['payload']['strain']);
    }

    public function testValidateEventSubmitPayloadRejectsUnknownTopLevelKeys(): void
    {
        $validator = new RequestValidator();

        $result = $validator->validateEventSubmitPayload([
            'type' => 'roll',
            'payload' => ['successes' => 1, 'banes' => 0],
            'actorId' => 'bob',
        ]);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $result->data()['error']['code']);
    }

    public function testValidateEventSubmitPayloadRejectsUnsupportedType(): void
    {
        $validator = new RequestValidator();

        $result = $validator->validateEventSubmitPayload([
            'type' => 'join',
            'payload' => [],
        ]);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(Response::ERROR_EVENT_TYPE_UNSUPPORTED, $result->data()['error']['code']);
        self::assertSame(Response::STATUS_UNPROCESSABLE_ENTITY, $result->code());
    }

    public function testValidateEventSubmitPayloadRejectsInvalidRollPayload(): void
    {
        $validator = new RequestValidator();

        $missingField = $validator->validateEventSubmitPayload([
            'type' => 'roll',
            'payload' => ['successes' => 1],
        ]);
        self::assertInstanceOf(Response::class, $missingField);
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $missingField->data()['error']['code']);

        $outOfRange = $validator->validateEventSubmitPayload([
            'type' => 'roll',
            'payload' => ['successes' => 100, 'banes' => 0],
        ]);
        self::assertInstanceOf(Response::class, $outOfRange);
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $outOfRange->data()['error']['code']);
    }

    public function testValidateEventSubmitPayloadRejectsInvalidPushPayload(): void
    {
        $validator = new RequestValidator();

        $nonBoolStrain = $validator->validateEventSubmitPayload([
            'type' => 'push',
            'payload' => ['successes' => 1, 'banes' => 0, 'strain' => 'true'],
        ]);
        self::assertInstanceOf(Response::class, $nonBoolStrain);
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $nonBoolStrain->data()['error']['code']);

        $extraField = $validator->validateEventSubmitPayload([
            'type' => 'push',
            'payload' => ['successes' => 1, 'banes' => 0, 'strain' => false, 'extra' => 1],
        ]);
        self::assertInstanceOf(Response::class, $extraField);
        self::assertSame(Response::ERROR_VALIDATION_ERROR, $extraField->data()['error']['code']);
    }
}

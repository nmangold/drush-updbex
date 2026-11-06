<?php

declare(strict_types=1);

namespace YourVendor\DrushUpdbex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use YourVendor\DrushUpdbex\ConfigStatusResponse;

final class ConfigStatusResponseTest extends TestCase
{
    public function testDecodesBooleanStatus(): void
    {
        self::assertTrue(ConfigStatusResponse::decode('{"has_changes":true}'));
        self::assertFalse(ConfigStatusResponse::decode('{"has_changes":false}'));
    }

    public function testRejectsMalformedJson(): void
    {
        $this->expectException(UnexpectedValueException::class);
        ConfigStatusResponse::decode('not-json');
    }

    public function testRejectsMissingOrNonBooleanStatus(): void
    {
        foreach (['{}', '{"has_changes":"yes"}', '[]'] as $response) {
            try {
                ConfigStatusResponse::decode($response);
                self::fail('Expected invalid response to be rejected.');
            } catch (UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
    }
}
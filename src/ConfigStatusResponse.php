<?php

declare(strict_types=1);

namespace YourVendor\DrushUpdbex;

use JsonException;
use UnexpectedValueException;

final class ConfigStatusResponse
{
    public static function decode(string $output): bool
    {
        try {
            $response = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('The configuration status command returned invalid JSON.', 0, $exception);
        }

        if (!is_array($response) || !array_key_exists('has_changes', $response) || !is_bool($response['has_changes'])) {
            throw new UnexpectedValueException('The configuration status command returned an invalid response.');
        }

        return $response['has_changes'];
    }
}
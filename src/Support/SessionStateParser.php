<?php

declare(strict_types=1);

namespace YZERoller\Api\Support;

final class SessionStateParser
{
    public static function parseSceneStrain(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^(0|[1-9]\d*)$/', $value) !== 1) {
            return 0;
        }

        return (int) $value;
    }

    /**
     * @param array<string,mixed>|false $row
     */
    public static function extractStateId(array|false $row): ?int
    {
        if (!is_array($row) || !array_key_exists('state_id', $row)) {
            return null;
        }

        $value = $row['state_id'];
        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    public static function parseJoiningEnabled(mixed $value): bool
    {
        return is_string($value) && $value === 'true';
    }
}

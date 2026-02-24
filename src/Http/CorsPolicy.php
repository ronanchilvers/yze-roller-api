<?php

declare(strict_types=1);

namespace YZERoller\Api\Http;

final class CorsPolicy
{
    /**
     * @param array<string,mixed> $config
     * @return array<string,string>
     */
    public static function resolve(array $config, ?string $origin): array
    {
        if (!self::isEnabled($config)) {
            return [];
        }

        $allowedOrigins = self::normalizeOrigins($config['origins'] ?? null);
        $requestOrigin = self::normalizeOrigin($origin ?? '');
        if ($requestOrigin === null) {
            return [];
        }

        $allowOrigin = self::resolveAllowOrigin($allowedOrigins, $requestOrigin);
        if ($allowOrigin === null) {
            return [];
        }

        $methods = self::normalizeStringList($config['methods'] ?? ['GET', 'POST', 'OPTIONS']);
        if ($methods === []) {
            $methods = ['GET', 'POST', 'OPTIONS'];
        }

        $headers = self::normalizeStringList($config['headers'] ?? ['Content-Type', 'Authorization']);
        if ($headers === []) {
            $headers = ['Content-Type', 'Authorization'];
        }

        $resolved = [
            'Access-Control-Allow-Origin' => $allowOrigin,
            'Vary' => 'Origin',
            'Access-Control-Allow-Methods' => implode(', ', $methods),
            'Access-Control-Allow-Headers' => implode(', ', $headers),
        ];

        $exposedHeaders = self::normalizeStringList($config['expose_headers'] ?? null);
        if ($exposedHeaders !== []) {
            $resolved['Access-Control-Expose-Headers'] = implode(', ', $exposedHeaders);
        }

        if (($config['allow_credentials'] ?? false) === true) {
            $resolved['Access-Control-Allow-Credentials'] = 'true';
        }

        $maxAge = $config['max_age'] ?? null;
        if (is_int($maxAge) && $maxAge >= 0) {
            $resolved['Access-Control-Max-Age'] = (string) $maxAge;
        }

        return $resolved;
    }

    /**
     * @param array<string,mixed> $config
     */
    public static function isEnabled(array $config): bool
    {
        $origins = self::normalizeOrigins($config['origins'] ?? null);

        return $origins !== [];
    }

    /**
     * @param array<int,string> $allowedOrigins
     */
    private static function resolveAllowOrigin(array $allowedOrigins, string $requestOrigin): ?string
    {
        if (in_array('*', $allowedOrigins, true)) {
            return $requestOrigin;
        }

        return in_array($requestOrigin, $allowedOrigins, true) ? $requestOrigin : null;
    }

    /**
     * @return array<int,string>
     */
    private static function normalizeOrigins(mixed $value): array
    {
        $origins = self::normalizeStringList($value);
        $normalized = [];
        foreach ($origins as $origin) {
            $canonical = self::normalizeOrigin($origin);
            if ($canonical === null) {
                continue;
            }

            $normalized[] = $canonical;
        }

        return array_values(array_unique($normalized));
    }

    private static function normalizeOrigin(string $origin): ?string
    {
        $trimmed = trim($origin);
        if ($trimmed === '') {
            return null;
        }

        if ($trimmed === '*' || $trimmed === 'null') {
            return $trimmed;
        }

        $parts = parse_url($trimmed);
        if (!is_array($parts)) {
            return rtrim($trimmed, '/');
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        if (!is_string($scheme) || $scheme === '' || !is_string($host) || $host === '') {
            return rtrim($trimmed, '/');
        }

        $normalized = strtolower($scheme) . '://' . strtolower($host);
        if (isset($parts['port']) && is_int($parts['port'])) {
            $normalized .= ':' . $parts['port'];
        }

        return $normalized;
    }

    /**
     * @return array<int,string>
     */
    private static function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }

            $trimmed = trim($item);
            if ($trimmed === '') {
                continue;
            }

            $normalized[] = $trimmed;
        }

        return array_values(array_unique($normalized));
    }
}

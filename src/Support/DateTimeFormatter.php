<?php

declare(strict_types=1);

namespace YZERoller\Api\Support;

use DateTimeImmutable;
use DateTimeZone;

final class DateTimeFormatter
{
    public function __construct(
        private readonly Clock $clock
    ) {
    }

    public function toRfc3339(string $mysqlDateTime): string
    {
        if ($mysqlDateTime === '') {
            return $this->formatDateTime($this->clock->now());
        }

        $date = $this->parseMysqlDateTime($mysqlDateTime);
        if ($date === null) {
            return $this->formatDateTime($this->clock->now());
        }

        return $this->formatDateTime($date);
    }

    public function toRfc3339OrNull(?string $mysqlDateTime): ?string
    {
        if (!is_string($mysqlDateTime) || $mysqlDateTime === '') {
            return null;
        }

        $date = $this->parseMysqlDateTime($mysqlDateTime);
        if ($date === null) {
            return null;
        }

        return $this->formatDateTime($date);
    }

    public function toMysqlDateTime(?DateTimeImmutable $dt = null): string
    {
        $dateTime = $dt ?? $this->clock->now();

        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function parseMysqlDateTime(string $value): ?DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, $utc);
        if ($date !== false) {
            return $date;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $utc);
        if ($date !== false) {
            return $date;
        }

        return null;
    }

    private function formatDateTime(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}

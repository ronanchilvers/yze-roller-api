<?php

declare(strict_types=1);

namespace YZERoller\Api\Support;

use flight\util\Collection;

final class CollectionHelper
{
    /**
     * @param Collection|array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function toArray(Collection|array $row): array
    {
        if ($row instanceof Collection) {
            return $row->getData();
        }

        return $row;
    }
}

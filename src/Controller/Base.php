<?php

declare(strict_types=1);

namespace YZERoller\Api\Controller;

use Flight;
use YZERoller\Api\Response;

abstract class Base
{
    protected function sendResponse(Response $response): void
    {
        $status = $response->code();
        $data = $response->data();

        if ($data === null) {
            Flight::response()->status($status);

            return;
        }

        Flight::json($data, $status);
    }
}

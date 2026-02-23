<?php

declare(strict_types=1);

namespace YZERoller\Api\Controller;

use Flight;
use YZERoller\Api\Response;
use YZERoller\Api\Service\SessionBootstrapService;

final class SessionsController
{
    public function __construct(private readonly SessionBootstrapService $sessionBootstrapService)
    {
    }

    public function create(): void
    {
        $request = Flight::request();
        $data = $request->data->getData();

        $response = $this->sessionBootstrapService->createSession($data);
        $this->sendResponse($response);
    }

    private function sendResponse(Response $response): void
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

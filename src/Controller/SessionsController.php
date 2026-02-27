<?php

declare(strict_types=1);

namespace YZERoller\Api\Controller;

use Flight;
use YZERoller\Api\Service\SessionBootstrapService;

final class SessionsController extends Base
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
}

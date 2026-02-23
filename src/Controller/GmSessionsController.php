<?php

declare(strict_types=1);

namespace YZERoller\Api\Controller;

use Flight;
use flight\net\Request;
use YZERoller\Api\Service\GmSessionJoiningService;

final class GmSessionsController extends Base
{
    public function __construct(private readonly GmSessionJoiningService $gmSessionJoiningService)
    {
    }

    public function updateJoining(string $sessionId): void
    {
        $request = Flight::request();
        $data = $request->data->getData();
        $authorizationHeader = Request::getHeader('Authorization', '');

        $response = $this->gmSessionJoiningService->updateJoining($authorizationHeader, $sessionId, $data);
        $this->sendResponse($response);
    }
}

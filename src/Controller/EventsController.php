<?php

declare(strict_types=1);

namespace YZERoller\Api\Controller;

use Flight;
use flight\net\Request;
use YZERoller\Api\Service\EventsPollService;

final class EventsController extends Base
{
    public function __construct(private readonly EventsPollService $eventsPollService)
    {
    }

    public function index(): void
    {
        $request = Flight::request();
        $query = $request->query->getData();
        $authorizationHeader = Request::getHeader('Authorization', '');

        $response = $this->eventsPollService->poll($authorizationHeader, $query);
        $this->sendResponse($response);
    }
}

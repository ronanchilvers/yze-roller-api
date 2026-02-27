<?php

declare(strict_types=1);

namespace YZERoller\Api\Controller;

use Flight;
use flight\net\Request;
use YZERoller\Api\Service\EventsPollService;
use YZERoller\Api\Service\EventsSubmitService;

final class EventsController extends Base
{
    public function __construct(
        private readonly EventsPollService $eventsPollService,
        private readonly EventsSubmitService $eventsSubmitService
    )
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

    public function create(): void
    {
        $request = Flight::request();
        $data = $request->data->getData();
        $authorizationHeader = Request::getHeader('Authorization', '');

        $response = $this->eventsSubmitService->submit($authorizationHeader, $data);
        $this->sendResponse($response);
    }
}

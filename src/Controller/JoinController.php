<?php

declare(strict_types=1);

namespace YZERoller\Api\Controller;

use Flight;
use flight\net\Request;
use YZERoller\Api\Service\JoinService;

final class JoinController extends Base
{
    public function __construct(private readonly JoinService $joinService)
    {
    }

    public function create(): void
    {
        $request = Flight::request();
        $data = $request->data->getData();
        $authorizationHeader = Request::getHeader('Authorization', '');

        $response = $this->joinService->join($authorizationHeader, $data);
        $this->sendResponse($response);
    }
}

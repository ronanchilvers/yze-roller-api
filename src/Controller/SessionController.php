<?php

declare(strict_types=1);

namespace YZERoller\Api\Controller;

use flight\net\Request;
use YZERoller\Api\Service\SessionSnapshotService;

final class SessionController extends Base
{
    public function __construct(private readonly SessionSnapshotService $sessionSnapshotService)
    {
    }

    public function show(): void
    {
        $authorizationHeader = Request::getHeader('Authorization', '');
        $response = $this->sessionSnapshotService->getSnapshot($authorizationHeader);
        $this->sendResponse($response);
    }
}

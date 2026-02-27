<?php

declare(strict_types=1);

namespace YZERoller\Api\Controller;

use Flight;
use flight\net\Request;
use YZERoller\Api\Service\GmJoinLinkRotateService;
use YZERoller\Api\Service\GmPlayerRevokeService;
use YZERoller\Api\Service\GmPlayersListService;
use YZERoller\Api\Service\GmSceneStrainResetService;
use YZERoller\Api\Service\GmSessionJoiningService;

final class GmSessionsController extends Base
{
    public function __construct(
        private readonly GmSessionJoiningService $gmSessionJoiningService,
        private readonly GmJoinLinkRotateService $gmJoinLinkRotateService,
        private readonly GmPlayersListService $gmPlayersListService,
        private readonly GmPlayerRevokeService $gmPlayerRevokeService,
        private readonly GmSceneStrainResetService $gmSceneStrainResetService
    ) {
    }

    public function updateJoining(string $sessionId): void
    {
        $request = Flight::request();
        $data = $request->data->getData();
        $authorizationHeader = Request::getHeader('Authorization', '');

        $response = $this->gmSessionJoiningService->updateJoining($authorizationHeader, $sessionId, $data);
        $this->sendResponse($response);
    }

    public function rotateJoinLink(string $sessionId): void
    {
        $authorizationHeader = Request::getHeader('Authorization', '');

        $response = $this->gmJoinLinkRotateService->rotate($authorizationHeader, $sessionId);
        $this->sendResponse($response);
    }

    public function listPlayers(string $sessionId): void
    {
        $authorizationHeader = Request::getHeader('Authorization', '');

        $response = $this->gmPlayersListService->listPlayers($authorizationHeader, $sessionId);
        $this->sendResponse($response);
    }

    public function revokePlayer(string $sessionId, string $tokenId): void
    {
        $request = Flight::request();
        $data = $request->data->getData();
        $authorizationHeader = Request::getHeader('Authorization', '');

        $response = $this->gmPlayerRevokeService->revoke($authorizationHeader, $sessionId, $tokenId, $data);
        $this->sendResponse($response);
    }

    public function resetSceneStrain(string $sessionId): void
    {
        $request = Flight::request();
        $data = $request->data->getData();
        $authorizationHeader = Request::getHeader('Authorization', '');

        $response = $this->gmSceneStrainResetService->reset($authorizationHeader, $sessionId, $data);
        $this->sendResponse($response);
    }
}

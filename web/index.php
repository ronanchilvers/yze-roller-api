<?php

declare(strict_types=1);

use flight\Container;
use flight\net\Request;
use YZERoller\Api\Controller\EventsController;
use YZERoller\Api\Controller\GmSessionsController;
use YZERoller\Api\Controller\JoinController;
use YZERoller\Api\Controller\SessionController;
use YZERoller\Api\Controller\SessionsController;
use YZERoller\Api\Http\CorsPolicy;

require '../vendor/autoload.php';
$settings = include '../config/settings.php';

// Configure the container
$container = new Container();
include '../config/services.php';
Flight::registerContainerHandler([$container, 'get']);

$corsConfig = is_array($settings['cors'] ?? null) ? $settings['cors'] : [];
if (CorsPolicy::isEnabled($corsConfig)) {
    Flight::before('start', function () use ($corsConfig): void {
        $origin = Request::getHeader('Origin', '');
        $corsHeaders = CorsPolicy::resolve($corsConfig, $origin);
        foreach ($corsHeaders as $name => $value) {
            Flight::response()->header($name, $value);
        }
    });

    Flight::route('OPTIONS *', function (): void {
        Flight::response()->status(204)->send();
    });
}

// Configure routing
Flight::post('/api/sessions', [SessionsController::class, 'create']);
Flight::route('GET /api/session', [SessionController::class, 'show']);
Flight::route('GET /api/events', [EventsController::class, 'index']);
Flight::route('POST /api/events', [EventsController::class, 'create']);
Flight::route('POST /api/sessions/@session_id/join-link/rotate', [GmSessionsController::class, 'rotateJoinLink']);
Flight::route('GET /api/gm/sessions/@session_id/players', [GmSessionsController::class, 'listPlayers']);
Flight::route('POST /api/gm/sessions/@session_id/players/@token_id/revoke', [GmSessionsController::class, 'revokePlayer']);
Flight::route('POST /api/gm/sessions/@session_id/joining', [GmSessionsController::class, 'updateJoining']);
Flight::route('POST /api/gm/sessions/@session_id/reset_scene_strain', [GmSessionsController::class, 'resetSceneStrain']);

Flight::post('/api/join', [JoinController::class, 'create']);

Flight::start();

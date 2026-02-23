<?php

declare(strict_types=1);

use flight\Container;
use YZERoller\Api\Controller\EventsController;
use YZERoller\Api\Controller\JoinController;
use YZERoller\Api\Controller\SessionController;
use YZERoller\Api\Controller\SessionsController;

require '../vendor/autoload.php';
$settings = include '../config/settings.php';

// Configure the container
$container = new Container();
include '../config/services.php';
Flight::registerContainerHandler([$container, 'get']);

// Configure routing
Flight::post('/api/sessions', [SessionsController::class, 'create']);
Flight::route('GET /api/session', [SessionController::class, 'show']);
Flight::route('GET /api/events', [EventsController::class, 'index']);

Flight::post('/api/join', [JoinController::class, 'create']);

Flight::start();

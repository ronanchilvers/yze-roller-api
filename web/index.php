<?php

declare(strict_types=1);

use flight\Container;
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
Flight::post('/api/join', [JoinController::class, 'create']);
Flight::get('/api/session', [SessionController::class, 'show']);

Flight::start();

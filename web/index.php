<?php

declare(strict_types=1);

use flight\Container;

require '../vendor/autoload.php';
$settings = include '../config/settings.php';

// Configure the container
$container = new Container();
include '../config/services.php';
Flight::registerContainerHandler([$container, 'get']);

// Configure routing
Flight::route("/api", function () {
    // API Routes go here
});

Flight::start();

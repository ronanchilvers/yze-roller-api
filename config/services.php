<?php

declare(strict_types=1);

// Variables available for registering services:
// - $container - A flightphp/Container instance
// - $settings - The application configuration array

use flight\database\SimplePdo;
use YZERoller\Api\Auth\AuthGuard;
use YZERoller\Api\Auth\TokenLookup;

// The SimplePdo database connection
$container->set(
    SimplePdo::class,
    function () use ($settings) {
        return new SimplePdo(
            sprintf(
                "%s:host=%s;dbname=%s;charset=utf8mb4",
                $settings["database"]["adapter"],
                $settings["database"]["host"],
                $settings["database"]["name"]
            ),
            $settings["database"]["username"],
            $settings["database"]["password"]
        );
    }
);

// Token lookup helper
$container->set(
    TokenLookup::class,
    function () use ($container) {
        return new TokenLookup($container->get(SimplePdo::class));
    }
);

// Auth guard helper
$container->set(
    AuthGuard::class,
    function () use ($container) {
        return new AuthGuard($container->get(TokenLookup::class));
    }
);

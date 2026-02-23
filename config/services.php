<?php

declare(strict_types=1);

// Variables available for registering services:
// - $container - A flightphp/Container instance
// - $settings - The application configuration array

use flight\database\SimplePdo;
use YZERoller\Api\Auth\AuthGuard;
use YZERoller\Api\Auth\TokenLookup;
use YZERoller\Api\Service\EventsPollService;
use YZERoller\Api\Service\EventsSubmitService;
use YZERoller\Api\Service\GmJoinLinkRotateService;
use YZERoller\Api\Service\GmPlayersListService;
use YZERoller\Api\Service\GmSessionJoiningService;
use YZERoller\Api\Service\JoinService;
use YZERoller\Api\Service\SessionSnapshotService;
use YZERoller\Api\Service\SessionBootstrapService;
use YZERoller\Api\Validation\RequestValidator;

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

// Request validation helper
$container->set(
    RequestValidator::class,
    function () {
        return new RequestValidator();
    }
);

// Session bootstrap service (POST /api/sessions)
$container->set(
    SessionBootstrapService::class,
    function () use ($container, $settings) {
        return new SessionBootstrapService(
            $container->get(SimplePdo::class),
            $container->get(RequestValidator::class),
            $settings['site']['url']
        );
    }
);

// Player self-join service (POST /api/join)
$container->set(
    JoinService::class,
    function () use ($container) {
        return new JoinService(
            $container->get(SimplePdo::class),
            $container->get(AuthGuard::class),
            $container->get(RequestValidator::class)
        );
    }
);

// Session snapshot service (GET /api/session)
$container->set(
    SessionSnapshotService::class,
    function () use ($container) {
        return new SessionSnapshotService(
            $container->get(SimplePdo::class),
            $container->get(AuthGuard::class)
        );
    }
);

// Event polling service (GET /api/events)
$container->set(
    EventsPollService::class,
    function () use ($container) {
        return new EventsPollService(
            $container->get(SimplePdo::class),
            $container->get(AuthGuard::class),
            $container->get(RequestValidator::class)
        );
    }
);

// Event submit service (POST /api/events)
$container->set(
    EventsSubmitService::class,
    function () use ($container) {
        return new EventsSubmitService(
            $container->get(SimplePdo::class),
            $container->get(AuthGuard::class),
            $container->get(RequestValidator::class)
        );
    }
);

// GM session joining toggle service (POST /api/gm/sessions/:session_id/joining)
$container->set(
    GmSessionJoiningService::class,
    function () use ($container) {
        return new GmSessionJoiningService(
            $container->get(SimplePdo::class),
            $container->get(AuthGuard::class),
            $container->get(RequestValidator::class)
        );
    }
);

// GM join-link rotate service (POST /api/sessions/:session_id/join-link/rotate)
$container->set(
    GmJoinLinkRotateService::class,
    function () use ($container, $settings) {
        return new GmJoinLinkRotateService(
            $container->get(SimplePdo::class),
            $container->get(AuthGuard::class),
            $container->get(RequestValidator::class),
            $settings['site']['url']
        );
    }
);

// GM players list service (GET /api/gm/sessions/:session_id/players)
$container->set(
    GmPlayersListService::class,
    function () use ($container) {
        return new GmPlayersListService(
            $container->get(SimplePdo::class),
            $container->get(AuthGuard::class),
            $container->get(RequestValidator::class)
        );
    }
);

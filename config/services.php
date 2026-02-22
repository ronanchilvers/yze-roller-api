<?php

declare(strict_types=1);

// Variables available for registering services:
// - $container - A flightphp/Container instance
// - $settings - The application configuration array

// The PDO database connection
$container->set(
    PDO::class,
    function () use ($settings) {
        return new PDO(
            sprintf(
                "%s:host=%s;dbname=%s;charset=utf8mb4",
                $settings["database"]["adaptor"],
                $settings["database"]["host"],
                $settings["database"]["name"]
            ),
            $settings["database"]["username"],
            $settings["database"]["password"]
        );
    }
);

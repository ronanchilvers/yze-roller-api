<?php

declare(strict_types=1);

$settings = [
    "site" => [
        "url" => "http://localhost:8080",
    ],
    "database" => [
        "adapter" => null,
        "host" => null,
        "username" => null,
        "password" => null,
        "name" => null,
    ],
    "cors" => [],
];

if (file_exists(__DIR__ . "/../.env.php")) {
    $env = include __DIR__ . "/../.env.php";
    $settings = array_merge($settings, $env);
}

return $settings;

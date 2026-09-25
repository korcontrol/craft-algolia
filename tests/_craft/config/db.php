<?php
declare(strict_types=1);

return [
    'driver'      => $_ENV['CRAFT_DB_DRIVER'] ?? 'pgsql',
    'server'      => $_ENV['CRAFT_DB_SERVER'] ?? '127.0.0.1',
    'port'        => (int) ($_ENV['CRAFT_DB_PORT'] ?? 5432),
    'database'    => $_ENV['CRAFT_DB_DATABASE'] ?? 'craft_algolia_test',
    'user'        => $_ENV['CRAFT_DB_USER'] ?? 'craft_algolia_test',
    'password'    => $_ENV['CRAFT_DB_PASSWORD'] ?? 'craft_algolia_test',
    'schema'      => $_ENV['CRAFT_DB_SCHEMA'] ?? 'public',
    'tablePrefix' => $_ENV['CRAFT_DB_TABLE_PREFIX'] ?? '',
];
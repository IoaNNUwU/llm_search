<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env('POSTGRES_HOST', 'postgres');
    $port = env('POSTGRES_PORT', '5432');
    $dbname = env('POSTGRES_DB', 'postgres');
    $user = env('POSTGRES_USER', 'postgres');
    $password = env('POSTGRES_PASSWORD', 'postgres');

    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

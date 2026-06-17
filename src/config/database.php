<?php
/**
 * Konfiguracja polaczenia z MySQL dla Raporty 2.0.
 * Dane sa pobierane z pliku .env przekazanego do kontenera przez docker-compose.
 */

function raport2_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

function raport2_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = raport2_env('MYSQL_HOST', 'db');
    $port = raport2_env('MYSQL_PORT', '3306');
    $name = raport2_env('MYSQL_DATABASE', 'raporty_db');
    $user = raport2_env('MYSQL_USER', 'raport_user');
    $pass = raport2_env('MYSQL_PASSWORD', 'raport_pass');

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);

    return $pdo;
}

function raport2_db_available(): bool
{
    try {
        raport2_db()->query('SELECT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = cfg('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

/** Миграция схемы (idempotent). Запускается при старте приложения. */
function db_migrate(): void
{
    static $done = false;
    if ($done) { return; }
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    if ($sql === false) { throw new RuntimeException('schema.sql not found'); }
    db()->exec($sql);
    $done = true;
}

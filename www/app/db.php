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

/**
 * Миграция схемы (idempotent). Запускается при старте приложения.
 *
 * 1) schema.sql создаёт недостающие таблицы (CREATE TABLE IF NOT EXISTS).
 * 2) Затем "догоняющие" ALTER'ы для уже существующих таблиц — версия хранится
 *    в schema_meta, чтобы не дёргать тяжёлые проверки на каждом запросе.
 */
function db_migrate(): void
{
    static $done = false;
    if ($done) { return; }
    $pdo = db();

    $sql = file_get_contents(__DIR__ . '/schema.sql');
    if ($sql === false) { throw new RuntimeException('schema.sql not found'); }
    $pdo->exec($sql);

    // schema_meta должна существовать (создаётся в schema.sql)
    $version = (int)($pdo->query("SELECT v FROM schema_meta WHERE k = 'schema_version'")->fetchColumn() ?: 0);

    if ($version < 1) {
        // v1: scheduled_at + generated audience_* (для БД, созданных до iter 003)
        $cols = $pdo->query("SHOW COLUMNS FROM moderation_queue")->fetchAll();
        $have = array_column($cols, 'Field');
        if (!in_array('scheduled_at', $have, true)) {
            $pdo->exec("ALTER TABLE moderation_queue ADD COLUMN scheduled_at DATETIME NULL AFTER payload");
        }
        if (!in_array('audience_id', $have, true)) {
            $pdo->exec("ALTER TABLE moderation_queue
                ADD COLUMN audience_id VARCHAR(190) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(payload, '$.audience.id'))) STORED,
                ADD COLUMN audience_name VARCHAR(190) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(payload, '$.audience.name'))) STORED");
        }
        $idx = $pdo->query("SHOW INDEX FROM moderation_queue")->fetchAll();
        $idxNames = array_column($idx, 'Key_name');
        if (!in_array('idx_sched', $idxNames, true)) {
            $pdo->exec("ALTER TABLE moderation_queue ADD KEY idx_sched (status, scheduled_at)");
        }
        if (!in_array('idx_audience', $idxNames, true)) {
            $pdo->exec("ALTER TABLE moderation_queue ADD KEY idx_audience (audience_id)");
        }
        $pdo->exec("INSERT INTO schema_meta (k, v) VALUES ('schema_version', '1')
                    ON DUPLICATE KEY UPDATE v = VALUES(v)");
    }

    if ($version < 2) {
        // v2: generated template_id/template_name из payload.template (см. audience)
        $cols = $pdo->query("SHOW COLUMNS FROM moderation_queue")->fetchAll();
        $have = array_column($cols, 'Field');
        if (!in_array('template_id', $have, true)) {
            $pdo->exec("ALTER TABLE moderation_queue
                ADD COLUMN template_id VARCHAR(190) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(payload, '$.template.id'))) STORED,
                ADD COLUMN template_name VARCHAR(190) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(payload, '$.template.name'))) STORED");
        }
        $idx = $pdo->query("SHOW INDEX FROM moderation_queue")->fetchAll();
        if (!in_array('idx_template', array_column($idx, 'Key_name'), true)) {
            $pdo->exec("ALTER TABLE moderation_queue ADD KEY idx_template (template_id)");
        }
        $pdo->exec("INSERT INTO schema_meta (k, v) VALUES ('schema_version', '2')
                    ON DUPLICATE KEY UPDATE v = VALUES(v)");
    }

    $done = true;
}

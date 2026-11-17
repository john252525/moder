<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/markdown.php';

final class ApiError extends RuntimeException
{
    public function __construct(public int $httpCode, public string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}

/** Конфиг (кешируется). */
function cfg(?string $key = null): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
    }
    if ($key === null) { return $cfg; }
    return $cfg[$key] ?? null;
}

/** Определяет роль по заголовку Authorization / X-Moder-Token. Возвращает null, если токена нет. */
function auth_role(): ?string
{
    $token = null;
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (is_string($hdr) && preg_match('/^Bearer\s+(.+)$/i', trim($hdr), $m)) {
        $token = trim($m[1]);
    }
    if ($token === null && isset($_SERVER['HTTP_X_MODER_TOKEN'])) {
        $token = trim((string)$_SERVER['HTTP_X_MODER_TOKEN']);
    }
    if ($token === null && isset($_GET['token'])) {
        $token = trim((string)$_GET['token']);
    }
    if ($token === null || $token === '') {
        return null;
    }
    foreach (cfg('tokens') as $role => $tokens) {
        foreach ($tokens as $t) {
            if (hash_equals($t, $token)) {
                return $role;
            }
        }
    }
    return null;
}

/** Требует роль; отдаёт JSON 401/403 и завершает. */
function require_role(string ...$roles): string
{
    $role = auth_role();
    if ($role === null) {
        throw new ApiError(401, 'unauthorized', 'Missing or invalid token');
    }
    if ($roles !== [] && !in_array($role, $roles, true)) {
        throw new ApiError(403, 'forbidden', 'Insufficient role: ' . $role);
    }
    return $role;
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    try {
        $d = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new ApiError(400, 'bad_json', 'Invalid JSON: ' . $e->getMessage());
    }
    if (!is_array($d)) {
        throw new ApiError(400, 'bad_json', 'JSON body must be an object');
    }
    return $d;
}

function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function str_req(array $d, string $k, int $max = 0): string
{
    if (!isset($d[$k]) || !is_string($d[$k]) || trim($d[$k]) === '') {
        throw new ApiError(422, 'validation', "Field '{$k}' is required and must be a non-empty string");
    }
    $v = trim($d[$k]);
    if ($max > 0 && mb_strlen($v) > $max) {
        throw new ApiError(422, 'validation', "Field '{$k}' exceeds {$max} chars");
    }
    return $v;
}

/** Запись события (аудит). */
function log_event(string $itemId, string $actor, string $action, ?string $comment = null): void
{
    $st = db()->prepare('INSERT INTO moderation_events (item_id, actor, action, comment) VALUES (?,?,?,?)');
    $st->execute([$itemId, $actor, $action, $comment]);
}

/** Получить запись или 404. */
function fetch_item(string $id): array
{
    $st = db()->prepare('SELECT * FROM moderation_queue WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        throw new ApiError(404, 'not_found', 'Item not found');
    }
    return $row;
}

/** Приводит строку из БД к JSON-виду для API/шаблонов. */
function item_to_array(array $r, bool $withPayload = true): array
{
    $out = [
        'id' => $r['id'],
        'idempotency_key' => $r['idempotency_key'],
        'type' => $r['type'],
        'project' => $r['project'],
        'agent_name' => $r['agent_name'],
        'title' => $r['title'],
        'reason' => $r['reason'],
        'status' => $r['status'],
        'reviewed_by' => $r['reviewed_by'],
        'reviewed_at' => $r['reviewed_at'],
        'rejection_reason' => $r['rejection_reason'],
        'applied_at' => $r['applied_at'],
        'apply_error' => $r['apply_error'],
        'apply_attempts' => (int)$r['apply_attempts'],
        'created_at' => $r['created_at'],
        'updated_at' => $r['updated_at'],
    ];
    if ($withPayload) {
        $out['payload'] = json_decode((string)$r['payload'], true);
    }
    return $out;
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function build_filters(array $q, string $role): array
{
    $w = [];
    $a = [];
    if (isset($q['status']) && $q['status'] !== '') {
        $st = (string)$q['status'];
        if ($st === 'active') { $w[] = "status IN ('pending','approved')"; }
        elseif ($st === 'archive') { $w[] = "status IN ('rejected','applied','failed','expired')"; }
        elseif (in_array($st, cfg('statuses'), true)) { $w[] = 'status = ?'; $a[] = $st; }
    }
    foreach (['project', 'type', 'agent_name'] as $f) {
        if (isset($q[$f]) && $q[$f] !== '') { $w[] = "{$f} = ?"; $a[] = (string)$q[$f]; }
    }
    if (isset($q['q']) && trim((string)$q['q']) !== '') {
        $w[] = '(title LIKE ? OR reason LIKE ? OR project LIKE ? OR agent_name LIKE ?)';
        $like = '%' . trim((string)$q['q']) . '%';
        array_push($a, $like, $like, $like, $like);
    }
    // агент видит только свои записи
    if ($role === 'agent') {
        $who = isset($q['agent_name']) && $q['agent_name'] !== '' ? (string)$q['agent_name'] : null;
        if ($who === null) {
            $w[] = '1=0'; // без указания agent_name — пусто (безопасный дефолт)
        }
    }
    return [$w === [] ? '1=1' : implode(' AND ', $w), $a];
}

function query_items(string $where, array $args, array $q): array
{
    $limit = max(1, min(200, (int)($q['limit'] ?? 50)));
    $offset = max(0, (int)($q['offset'] ?? 0));
    $order = 'created_at DESC';
    if (($q['sort'] ?? '') === 'oldest') { $order = 'created_at ASC'; }

    $pdo = db();
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM moderation_queue WHERE {$where}");
    $cnt->execute($args);
    $total = (int)$cnt->fetchColumn();

    $st = $pdo->prepare("SELECT * FROM moderation_queue WHERE {$where} ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}");
    $st->execute($args);
    return [$total, $st->fetchAll()];
}

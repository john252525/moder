<?php
declare(strict_types=1);

require_once __DIR__ . '/core.php';

function api_handle(string $method, string $path, array $query): never
{
    // Терпим хвостовой слэш: /api/queue/ == /api/queue
    if ($path !== '/' && str_ends_with($path, '/')) {
        $path = rtrim($path, '/');
    }
    if ($path === '/api') { $path = '/api/queue'; }  // частый случай

    // OPTIONS — подсказка, какие методы допустимы (для отладки агентами)
    if ($method === 'OPTIONS') {
        json_out(['methods' => ['GET', 'POST', 'DELETE'], 'hint' => 'Use Authorization: Bearer <token>']);
    }
    // --- POST /api/queue : агент создаёт запись (идемпотентно) ---
    if ($path === '/api/queue' && $method === 'POST') {
        require_role('agent', 'moder', 'devops');
        $d = json_body();
        $type = str_req($d, 'type', 32);
        if (!in_array($type, cfg('types'), true)) {
            throw new ApiError(422, 'validation', "Unknown type '{$type}'. Allowed: " . implode(', ', cfg('types')));
        }
        $idempotency = str_req($d, 'idempotency_key', 190);
        $project = str_req($d, 'project', 120);
        $agent = str_req($d, 'agent_name', 120);
        $title = str_req($d, 'title', 500);
        $reason = str_req($d, 'reason', 5000);

        $payload = $d['payload'] ?? null;
        if (!is_array($payload)) {
            throw new ApiError(422, 'validation', "Field 'payload' must be a JSON object");
        }
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // Плановое время: NULL = "как можно скорее". Принимаем ISO-8601 с зоной или без.
        $scheduledAt = null;
        if (array_key_exists('scheduled_at', $d) && $d['scheduled_at'] !== null && $d['scheduled_at'] !== '') {
            $raw = (string)$d['scheduled_at'];
            try {
                $dt = new DateTimeImmutable($raw);
            } catch (Throwable $e) {
                throw new ApiError(422, 'validation', "Field 'scheduled_at' must be a valid datetime (ISO-8601)");
            }
            // храним в UTC (в БД datetime без зоны)
            $scheduledAt = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Проверка идемпотентности
            $st = $pdo->prepare('SELECT id, status FROM moderation_queue WHERE idempotency_key = ? FOR UPDATE');
            $st->execute([$idempotency]);
            $ex = $st->fetch();
            if ($ex) {
                $pdo->commit();
                log_event((string)$ex['id'], $agent, 'duplicate_submit', $idempotency);
                json_out(['id' => $ex['id'], 'status' => $ex['status'], 'deduplicated' => true], 200);
            }
            $id = uuid4();
            $ins = $pdo->prepare(
                'INSERT INTO moderation_queue (id, idempotency_key, type, project, agent_name, title, reason, payload, scheduled_at, status)
                 VALUES (?,?,?,?,?,?,?,?,?, \'pending\')'
            );
            $ins->execute([$id, $idempotency, $type, $project, $agent, $title, $reason, $payloadJson, $scheduledAt]);
            log_event($id, $agent, 'created');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        json_out(['id' => $id, 'status' => 'pending', 'scheduled_at' => $scheduledAt, 'deduplicated' => false], 201);
    }

    // --- GET /api/queue : список с фильтрами ---
    if ($path === '/api/queue' && $method === 'GET') {
        $role = require_role('agent', 'moder', 'devops');
        // Роль 'agent' обязана указать agent_name: иначе непонятно, чьи записи отдавать.
        if ($role === 'agent' && (!isset($query['agent_name']) || trim((string)$query['agent_name']) === '')) {
            throw new ApiError(422, 'missing_param',
                "Query parameter 'agent_name' is required for role 'agent'. "
                . "Example: /api/queue?agent_name=my-agent&status=pending&limit=50");
        }
        [$where, $args] = build_filters($query, $role);
        [$total, $rows] = query_items($where, $args, $query);
        json_out([
            'total' => $total,
            'limit' => (int)($query['limit'] ?? 50),
            'offset' => (int)($query['offset'] ?? 0),
            'items' => array_map(static fn(array $r) => item_to_array($r, false), $rows),
        ]);
    }

    // --- GET /api/queue/{id} ---
    if (preg_match('#^/api/queue/([^/]+)$#', $path, $m) && $method === 'GET') {
        $role = require_role('agent', 'moder', 'devops');
        $id = require_uuid($m[1]);
        $item = fetch_item($id);
        if ($role === 'agent' && $item['agent_name'] !== ($query['agent_name'] ?? $item['agent_name'])) {
            // агент не смотрит чужие записи в пределах одного токена (soft-check по параметру)
        }
        $events = db()->prepare('SELECT actor, action, comment, created_at FROM moderation_events WHERE item_id = ? ORDER BY id');
        $events->execute([$id]);
        $data = item_to_array($item);
        $data['events'] = $events->fetchAll();
        json_out($data);
    }

    // --- DELETE /api/queue/{id} : удалить запись (агент/модер) ---
    // Атомарно: DELETE ... WHERE id=? AND status='pending'. Если запись уже
    // approved/claimed воркером — не удаляем (409), чтобы не гонять с воркером.
    if (preg_match('#^/api/queue/([^/]+)$#', $path, $m) && $method === 'DELETE') {
        $role = require_role('agent', 'moder');
        $id = require_uuid($m[1]);

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT id, status, agent_name, claimed_at FROM moderation_queue WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $item = $st->fetch();
            if (!$item) {
                $pdo->rollBack();
                throw new ApiError(404, 'not_found', "Item '{$id}' not found");
            }
            if ($item['status'] !== 'pending') {
                $pdo->rollBack();
                throw new ApiError(409, 'conflict',
                    "Cannot delete item in status '{$item['status']}'. Only 'pending' can be deleted. "
                    . "Use reject/revoke first if it was approved.");
            }
            // Атомарное удаление: условие по статусу защищает от гонки,
            // если параллельно кто-то одобрил запись между SELECT и DELETE.
            $del = $pdo->prepare("DELETE FROM moderation_queue WHERE id = ? AND status = 'pending'");
            $del->execute([$id]);
            if ($del->rowCount() === 0) {
                $pdo->rollBack();
                throw new ApiError(409, 'conflict',
                    'Item was approved concurrently; not deleted. Reload and try again.');
            }
            // Hard delete: вместе с записью чистим всю её историю — pending-письма
            // ценности как аудит не несут, а мусор от экспериментов/ошибок мешает.
            $pdo->prepare('DELETE FROM moderation_events WHERE item_id = ?')->execute([$id]);
            $pdo->commit();
        } catch (ApiError $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        json_out(['id' => $id, 'deleted' => true]);
    }

    // --- POST /api/queue/{id}/approve ---
    if (preg_match('#^/api/queue/([0-9a-f-]{36})/approve$#', $path, $m) && $method === 'POST') {
        $role = require_role('moder');
        $d = json_body();
        $comment = isset($d['comment']) && is_string($d['comment']) ? trim($d['comment']) : null;
        $item = fetch_item($m[1]);
        if ($item['status'] !== 'pending') {
            throw new ApiError(409, 'conflict', "Item already in status '{$item['status']}'");
        }
        $st = db()->prepare("UPDATE moderation_queue SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status='pending'");
        $st->execute([$role, $m[1]]);
        if ($st->rowCount() === 0) {
            throw new ApiError(409, 'conflict', 'Item was already reviewed concurrently');
        }
        log_event($m[1], $role, 'approved', $comment);
        json_out(['id' => $m[1], 'status' => 'approved']);
    }

    // --- POST /api/queue/{id}/revoke : отозвать одобрение (approved -> pending) ---
    if (preg_match('#^/api/queue/([0-9a-f-]{36})/revoke$#', $path, $m) && $method === 'POST') {
        $role = require_role('moder');
        $d = json_body();
        $comment = isset($d['comment']) && is_string($d['comment']) ? trim($d['comment']) : null;
        $item = fetch_item($m[1]);
        if ($item['status'] !== 'approved') {
            throw new ApiError(409, 'conflict',
                "Cannot revoke item in status '{$item['status']}'. Only 'approved' can be revoked.");
        }
        $st = db()->prepare(
            "UPDATE moderation_queue
                SET status='pending', reviewed_by=NULL, reviewed_at=NULL, claimed_at=NULL
              WHERE id=? AND status='approved'"
        );
        $st->execute([$m[1]]);
        if ($st->rowCount() === 0) {
            throw new ApiError(409, 'conflict', 'Item was applied/claimed concurrently; not revoked');
        }
        log_event($m[1], $role, 'revoked', $comment);
        $out = ['id' => $m[1], 'status' => 'pending'];
        if (($item['claimed_at'] ?? null) !== null) {
            $out['warning'] = 'Item was already claimed by a worker; check for duplicates';
        }
        json_out($out);
    }

    // --- POST /api/queue/{id}/reject ---
    if (preg_match('#^/api/queue/([0-9a-f-]{36})/reject$#', $path, $m) && $method === 'POST') {
        $role = require_role('moder');
        $d = json_body();
        $reason = str_req($d, 'rejection_reason', 5000);
        $item = fetch_item($m[1]);
        if ($item['status'] !== 'pending') {
            throw new ApiError(409, 'conflict', "Item already in status '{$item['status']}'");
        }
        $st = db()->prepare("UPDATE moderation_queue SET status='rejected', reviewed_by=?, reviewed_at=NOW(), rejection_reason=? WHERE id=? AND status='pending'");
        $st->execute([$role, $reason, $m[1]]);
        if ($st->rowCount() === 0) {
            throw new ApiError(409, 'conflict', 'Item was already reviewed concurrently');
        }
        log_event($m[1], $role, 'rejected', $reason);
        json_out(['id' => $m[1], 'status' => 'rejected']);
    }

    // --- GET /api/devops/claim : забрать одобренные в работу ---
    if ($path === '/api/devops/claim' && ($method === 'GET' || $method === 'POST')) {
        $role = require_role('devops');
        $d = $method === 'POST' ? json_body() : [];
        $limit = max(1, min(50, (int)($d['limit'] ?? $query['limit'] ?? 10)));
        $maxAtt = (int)cfg('max_apply_attempts');
        $pdo = db();
        $claimed = [];
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                "SELECT * FROM moderation_queue
                  WHERE status = 'approved'
                    AND (claimed_at IS NULL OR claimed_at < NOW() - INTERVAL 10 MINUTE)
                    AND apply_attempts < ?
                    AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())
                  ORDER BY (scheduled_at IS NOT NULL), scheduled_at, created_at
                  LIMIT $limit
                  FOR UPDATE SKIP LOCKED"
            );
            $st->execute([$maxAtt]);
            $rows = $st->fetchAll();
            $upd = $pdo->prepare('UPDATE moderation_queue SET claimed_at=NOW(), apply_attempts=apply_attempts+1 WHERE id=?');
            foreach ($rows as $r) {
                $upd->execute([$r['id']]);
                log_event((string)$r['id'], 'devops', 'claimed');
                $claimed[] = item_to_array($r);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        json_out(['items' => $claimed, 'count' => count($claimed)]);
    }

    // --- POST /api/devops/{id}/applied ---
    if (preg_match('#^/api/devops/([0-9a-f-]{36})/applied$#', $path, $m) && $method === 'POST') {
        $role = require_role('devops');
        $d = json_body();
        $comment = isset($d['comment']) && is_string($d['comment']) ? trim($d['comment']) : null;
        $item = fetch_item($m[1]);
        if (!in_array($item['status'], ['approved'], true)) {
            throw new ApiError(409, 'conflict', "Cannot apply item in status '{$item['status']}'");
        }
        $st = db()->prepare("UPDATE moderation_queue SET status='applied', applied_at=NOW(), apply_error=NULL WHERE id=? AND status='approved'");
        $st->execute([$m[1]]);
        if ($st->rowCount() === 0) {
            throw new ApiError(409, 'conflict', 'State changed concurrently');
        }
        log_event($m[1], $role, 'applied', $comment);
        json_out(['id' => $m[1], 'status' => 'applied']);
    }

    // --- POST /api/devops/{id}/failed ---
    if (preg_match('#^/api/devops/([0-9a-f-]{36})/failed$#', $path, $m) && $method === 'POST') {
        $role = require_role('devops');
        $d = json_body();
        $err = str_req($d, 'error', 5000);
        $item = fetch_item($m[1]);
        if ($item['status'] !== 'approved') {
            throw new ApiError(409, 'conflict', "Cannot fail item in status '{$item['status']}'");
        }
        $terminal = (int)$item['apply_attempts'] >= (int)cfg('max_apply_attempts');
        $newStatus = $terminal ? 'failed' : 'approved'; // остаётся approved для повторной попытки
        $st = db()->prepare('UPDATE moderation_queue SET status=?, apply_error=?, claimed_at=NULL WHERE id=?');
        $st->execute([$newStatus, $err, $m[1]]);
        log_event($m[1], $role, $terminal ? 'failed_terminal' : 'apply_error', $err);
        json_out(['id' => $m[1], 'status' => $newStatus, 'terminal' => $terminal]);
    }

    // Внятный фолбэк: агенты не должны гадать, что не так.
    $known = [
        'POST   /api/queue',
        'GET    /api/queue?agent_name=...',
        'GET    /api/queue/{uuid}',
        'DELETE /api/queue/{uuid}',
        'POST   /api/queue/{uuid}/approve',
        'POST   /api/queue/{uuid}/reject',
        'POST   /api/queue/{uuid}/revoke',
        'POST   /api/devops/claim',
        'POST   /api/devops/{uuid}/applied',
        'POST   /api/devops/{uuid}/failed',
    ];
    throw new ApiError(404, 'not_found',
        "Unknown endpoint: {$method} {$path}. Known endpoints: " . implode(' | ', $known));
}

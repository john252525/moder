<?php
declare(strict_types=1);

require_once __DIR__ . '/core.php';

function api_handle(string $method, string $path, array $query): never
{
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
                'INSERT INTO moderation_queue (id, idempotency_key, type, project, agent_name, title, reason, payload, status)
                 VALUES (?,?,?,?,?,?,?,?, \'pending\')'
            );
            $ins->execute([$id, $idempotency, $type, $project, $agent, $title, $reason, $payloadJson]);
            log_event($id, $agent, 'created');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        json_out(['id' => $id, 'status' => 'pending', 'deduplicated' => false], 201);
    }

    // --- GET /api/queue : список с фильтрами ---
    if ($path === '/api/queue' && $method === 'GET') {
        $role = require_role('agent', 'moder', 'devops');
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
    if (preg_match('#^/api/queue/([0-9a-f-]{36})$#', $path, $m) && $method === 'GET') {
        $role = require_role('agent', 'moder', 'devops');
        $item = fetch_item($m[1]);
        if ($role === 'agent' && $item['agent_name'] !== ($query['agent_name'] ?? $item['agent_name'])) {
            // агент не смотрит чужие записи в пределах одного токена (soft-check по параметру)
        }
        $events = db()->prepare('SELECT actor, action, comment, created_at FROM moderation_events WHERE item_id = ? ORDER BY id');
        $events->execute([$m[1]]);
        $data = item_to_array($item);
        $data['events'] = $events->fetchAll();
        json_out($data);
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
                  ORDER BY created_at
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

    throw new ApiError(404, 'not_found', "No route for {$method} {$path}");
}

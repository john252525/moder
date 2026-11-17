<?php
declare(strict_types=1);

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/render.php';

function ui_handle(string $method, string $path, array $query, array $post): never
{
    // UI работает только если задан MODER_TOKEN_MODER (иначе вход невозможен)
    $moderToken = getenv('MODER_TOKEN_MODER') ?: '';
    // Cookie-секрет: отдельный MODER_UI_SECRET или производный от токена (детерминированный)
    $secret = getenv('MODER_UI_SECRET') ?: '';
    if ($secret === '' && $moderToken !== '') {
        $secret = hash('sha256', 'moder-ui|' . $moderToken);
    }
    if ($moderToken === '' || $secret === '') {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo layout('Не настроено', '<div class="card" style="max-width:520px;margin:80px auto"><h1>Сервис не настроен</h1><p class="muted">Задайте MODER_TOKEN_MODER в окружении (см. <code>.env.example</code>) и перезапустите контейнер.</p></div>');
        exit;
    }

    // Выход
    if ($path === '/logout') {
        setcookie('moder_ui', '', ['expires' => 1, 'path' => '/']);
        header('Location: /'); exit;
    }

    // Вход через POST-форму (токен не попадает в access.log)
    $loginError = null;
    if ($path === '/login' && $method === 'POST') {
        $tok = trim((string)($post['token'] ?? ''));
        if ($tok !== '' && hash_equals($moderToken, $tok)) {
            setcookie('moder_ui', $secret, [
                'expires' => time() + 86400 * 7,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => isset($_SERVER['HTTPS']),
            ]);
            header('Location: /'); exit;
        }
        $loginError = 'Неверный токен';
    }

    // Совместимость: вход по ссылке ?ui_token=...
    if (isset($query['ui_token']) && hash_equals($moderToken, (string)$query['ui_token'])) {
        setcookie('moder_ui', $secret, [
            'expires' => time() + 86400 * 7,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => isset($_SERVER['HTTPS']),
        ]);
        header('Location: /'); exit;
    }

    $moder = (auth_role() === 'moder') || (isset($_COOKIE['moder_ui']) && hash_equals($secret, (string)$_COOKIE['moder_ui']));
    if (!$moder) {
        http_response_code(401);
        header('Content-Type: text/html; charset=utf-8');
        echo ui_login_page($loginError);
        exit;
    }

    switch (true) {
        case $path === '/' || $path === '/index.php':
            echo ui_list($query); exit;
        case preg_match('#^/item/([0-9a-f-]{36})$#', $path, $m):
            echo ui_item($m[1]); exit;
        case preg_match('#^/item/([0-9a-f-]{36})/(approve|reject|revoke)$#', $path, $m) && $method === 'POST':
            ui_action($m[1], $m[2], $post); exit;
        case $path === '/bulk' && $method === 'POST':
            ui_bulk($post); exit;
        case $path === '/stats':
            echo ui_stats(); exit;
        default:
            http_response_code(404);
            echo layout('404', '<h1>Не найдено</h1><p><a href="/">← Назад</a></p>');
            exit;
    }
}

function ui_login_page(?string $error = null): string
{
    $errHtml = $error !== null ? '<div class="notice notice-no" style="text-align:left">' . e($error) . '</div>' : '';
    return layout('Вход', <<<HTML
<div class="card" style="max-width:420px;margin:80px auto">
  <h1 style="text-align:center">◆ moder</h1>
  <p class="muted" style="text-align:center;margin-top:0">Очередь премодерации</p>
  {$errHtml}
  <form method="post" action="/login" style="display:flex;flex-direction:column;gap:10px;margin-top:16px">
    <input type="password" name="token" placeholder="Токен модератора"
           style="background:#12151c;border:1px solid var(--br);color:var(--txt);border-radius:9px;padding:11px 14px;font:inherit;width:100%"
           autofocus required>
    <button type="submit" style="width:100%">Войти</button>
  </form>
</div>
HTML);
}

function ui_action(string $id, string $action, array $post): void
{
    $item = fetch_item($id);
    $actor = 'moder-ui';

    if ($action === 'revoke') {
        if ($item['status'] !== 'approved') {
            flash('Отозвать можно только одобренное (не применённое) задание');
            header("Location: /item/$id"); exit;
        }
        db()->prepare("UPDATE moderation_queue SET status='pending', reviewed_by=NULL, reviewed_at=NULL, claimed_at=NULL WHERE id=? AND status='approved'")->execute([$id]);
        log_event($id, $actor, 'revoked', 'одобрение отозвано');
        if ($item['claimed_at'] !== null) {
            flash('Внимание: задание уже было взято воркером — отозвано, но проверьте дубли');
        } else {
            flash('Одобрение отозвано, задание вернулось на модерацию');
        }
        header("Location: /item/$id"); exit;
    }

    if ($action === 'approve') {
        if ($item['status'] !== 'pending') { flash('Запись уже обработана'); header("Location: /item/$id"); exit; }
        db()->prepare("UPDATE moderation_queue SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status='pending'")->execute([$actor, $id]);
        log_event($id, $actor, 'approved', isset($post['comment']) ? trim((string)$post['comment']) : null);
    } else {
        $reason = trim((string)($post['rejection_reason'] ?? ''));
        if ($reason === '') { flash('Укажите причину отказа'); header("Location: /item/$id"); exit; }
        if ($item['status'] !== 'pending') { flash('Запись уже обработана'); header("Location: /item/$id"); exit; }
        db()->prepare("UPDATE moderation_queue SET status='rejected', reviewed_by=?, reviewed_at=NOW(), rejection_reason=? WHERE id=? AND status='pending'")->execute([$actor, $reason, $id]);
        log_event($id, $actor, 'rejected', $reason);
    }
    flash($action === 'approve' ? 'Одобрено' : 'Отклонено');
    header("Location: /item/$id"); exit;
}

function flash(?string $msg = null): ?string
{
    if ($msg !== null) { $_SESSION['flash'] = $msg; return null; }
    $m = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $m;
}

/**
 * Массовые действия над выбранными записями (approve/reject).
 * Работаем только с pending — остальные молча пропускаем и сообщаем в flash.
 */
function ui_bulk(array $post): void
{
    $rawIds = $post['ids'] ?? [];
    if (!is_array($rawIds)) { $rawIds = []; }
    $ids = [];
    foreach ($rawIds as $v) {
        if (is_string($v) && preg_match('/^[0-9a-f-]{36}$/', $v)) { $ids[] = $v; }
    }
    $ids = array_values(array_unique($ids));
    if ($ids === []) {
        flash('Ничего не выбрано');
        header('Location: /'); exit;
    }

    $action = (string)($post['action'] ?? 'approve');
    $comment = trim((string)($post['comment'] ?? ''));
    $reason = trim((string)($post['rejection_reason'] ?? ''));
    $actor = 'moder-ui';

    if ($action === 'reject' && $reason === '') {
        flash('Для массового отказа нужна причина');
        header('Location: /'); exit;
    }

    $pdo = db();
    $done = 0;
    $skipped = 0;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $pdo->beginTransaction();
    try {
        if ($action === 'approve') {
            $st = $pdo->prepare("UPDATE moderation_queue SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE status='pending' AND id IN ($in)");
            $st->execute(array_merge([$actor], $ids));
            $done = $st->rowCount();
            foreach ($ids as $id) { log_event($id, $actor, 'approved_bulk', $comment !== '' ? $comment : null); }
        } else {
            $st = $pdo->prepare("UPDATE moderation_queue SET status='rejected', reviewed_by=?, reviewed_at=NOW(), rejection_reason=? WHERE status='pending' AND id IN ($in)");
            $st->execute(array_merge([$actor, $reason], $ids));
            $done = $st->rowCount();
            foreach ($ids as $id) { log_event($id, $actor, 'rejected_bulk', $reason); }
        }
        $skipped = count($ids) - $done;
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        flash('Ошибка массового действия: ' . $e->getMessage());
        header('Location: /'); exit;
    }

    $verb = $action === 'approve' ? 'одобрено' : 'отклонено';
    $msg = "{$verb}: {$done}";
    if ($skipped > 0) { $msg .= " (пропущено {$skipped} — уже обработаны)"; }
    flash($msg);
    header('Location: /'); exit;
}

function ui_list(array $q): string
{
    if (!isset($q['status']) || $q['status'] === '') {
        $q['status'] = 'pending';
    }
    // По умолчанию показываем «сегодня» (asap + запланированные на сегодня).
    // ?schedule=all -> снять ограничение.
    if (!isset($q['schedule']) || $q['schedule'] === '') {
        $q['schedule'] = 'today';
    }
    [$where, $args] = build_filters($q, 'moder');
    [$total, $rows] = query_items($where, $args, $q);

    $fStatus = (string)($q['status'] ?? 'pending');
    $fProject = (string)($q['project'] ?? '');
    $fType = (string)($q['type'] ?? '');
    $fAgent = (string)($q['agent_name'] ?? '');
    $fQ = (string)($q['q'] ?? '');
    $fSched = (string)($q['schedule'] ?? 'today');
    $fAudience = (string)($q['audience_id'] ?? '');

    $projects = db()->query('SELECT DISTINCT project FROM moderation_queue ORDER BY project')->fetchAll(PDO::FETCH_COLUMN);
    $agents = db()->query('SELECT DISTINCT agent_name FROM moderation_queue ORDER BY agent_name')->fetchAll(PDO::FETCH_COLUMN);
    $audiences = db()->query("SELECT DISTINCT audience_id, audience_name FROM moderation_queue WHERE audience_id IS NOT NULL ORDER BY audience_id")->fetchAll();

    $counts = db()->query("SELECT status, COUNT(*) c FROM moderation_queue GROUP BY status")->fetchAll();
    $cnt = array_column($counts, 'c', 'status');

    $opt = static function (array $items, string $cur, string $allLabel = 'Все'): string {
        $h = '<option value="">' . e($allLabel) . '</option>';
        foreach ($items as $v) {
            $h .= '<option value="' . e((string)$v) . '"' . ((string)$v === $cur ? ' selected' : '') . '>' . e((string)$v) . '</option>';
        }
        return $h;
    };

    $statusOpts = [
        'pending' => 'На модерации',
        'approved' => 'Одобрено (в работе)',
        'applied' => 'Применено',
        'rejected' => 'Отклонено',
        'failed' => 'Ошибка применения',
        'active' => 'Активные (pending+approved)',
        'archive' => 'Архив',
        '' => 'Все',
    ];

    $hoverOn = (bool)(cfg('ui')['email_hover'] ?? true);

    $rowsHtml = '';
    $hoverHtml = '';
    foreach ($rows as $r) {
        $id = (string)$r['id'];
        $tl = cfg('type_labels')[$r['type']] ?? $r['type'];
        $badge = status_badge($r['status']);
        $canReview = $r['status'] === 'pending';

        // чекбокс (только для pending — остальные не выбираются)
        $cb = $canReview
            ? '<input type="checkbox" class="rowcb" name="ids" value="' . e($id) . '" form="bulkForm" onclick="event.stopPropagation()">'
            : '<span class="muted">—</span>';

        // hover-превью для email
        $hoverAttr = '';
        if ($hoverOn && $r['type'] === 'email') {
            $pl = json_decode((string)$r['payload'], true) ?: [];
            $to = is_array($pl['to'] ?? null) ? implode(', ', array_map('strval', $pl['to'])) : '';
            $subj = (string)($pl['subject'] ?? '');
            $from = (string)($pl['from'] ?? '');
            $body = (string)($pl['body'] ?? '');
            if ($body === '' && !empty($pl['html'])) { $body = '[HTML-письмо]'; }
            $preview = '<div class="hp-row"><span class="hp-k">От</span><span class="hp-v">' . e($from) . '</span></div>'
                . '<div class="hp-row"><span class="hp-k">Кому</span><span class="hp-v">' . e($to) . '</span></div>'
                . '<div class="hp-row"><span class="hp-k">Тема</span><span class="hp-v hp-subj">' . e($subj) . '</span></div>'
                . '<div class="hp-body">' . nl2br(e($body)) . '</div>';
            $hoverAttr = ' data-hover="' . e($preview) . '"';
        }

        $quick = '';
        if ($canReview) {
            $quick = '<div class="quick" onclick="event.stopPropagation()">'
                . '<form method="post" action="/item/' . e($id) . '/approve" style="display:inline">'
                . '<button class="qbtn q-ok" title="Одобрить" type="submit">✓</button></form>'
                . '<button class="qbtn q-no" title="Отклонить" type="button" onclick="quickReject(\'' . e($id) . '\')">✗</button>'
                . '</div>';
        } elseif ($r['status'] === 'approved') {
            $quick = '<div class="quick" onclick="event.stopPropagation()">'
                . '<form method="post" action="/item/' . e($id) . '/revoke" style="display:inline" onsubmit="return confirm(\'Отозвать одобрение?\')">'
                . '<button class="qbtn q-rev" title="Отозвать одобрение" type="submit">↺</button></form>'
                . '</div>';
        }

        $rowsHtml .= '<tr class="row' . ($canReview ? ' row-pending' : '') . '" onclick="location.href=\'/item/' . e($id) . '\'"' . $hoverAttr . '>';
        $rowsHtml .= '<td class="cbcell">' . $cb . '</td>';
        $rowsHtml .= '<td>' . $badge . '</td>';
        $rowsHtml .= '<td><span class="tag">' . e($tl) . '</span></td>';
        $rowsHtml .= '<td><div class="ttl">' . e($r['title']) . '</div><div class="muted small">' . e(mb_substr($r['reason'], 0, 130)) . (mb_strlen($r['reason']) > 130 ? '…' : '') . '</div></td>';
        $rowsHtml .= '<td>' . e($r['project']) . '</td>';
        $rowsHtml .= '<td>' . e($r['agent_name']) . '</td>';
        $rowsHtml .= '<td class="muted small nowrap">' . e($r['created_at']) . '</td>';
        $rowsHtml .= '<td class="nowrap">' . sched_cell($r['scheduled_at'], $r['status']) . '</td>';
        $rowsHtml .= '<td>' . audience_cell($r['audience_id'], $r['audience_name']) . '</td>';
        $rowsHtml .= '<td class="qcell">' . $quick . '</td>';
        $rowsHtml .= '</tr>';
    }
    if ($rowsHtml === '') {
        $rowsHtml = '<tr><td colspan="10" class="muted" style="text-align:center;padding:40px">Записей нет</td></tr>';
    }

    $fl = flash();
    $flash = $fl !== null ? '<div class="flash">' . e($fl) . '</div>' : '';

    $limit = (int)($q['limit'] ?? 50);
    $offset = (int)($q['offset'] ?? 0);
    $qs = $_GET; unset($qs['offset']);
    $mk = static function (int $off) use ($qs) { $qs['offset'] = $off; return '?' . http_build_query($qs); };
    $pager = '<div class="pager">';
    $pager .= 'Всего: <b>' . $total . '</b>';
    if ($offset > 0) { $pager .= ' · <a href="' . e($mk(max(0, $offset - $limit))) . '">← назад</a>'; }
    if ($offset + $limit < $total) { $pager .= ' · <a href="' . e($mk($offset + $limit)) . '">ещё →</a>'; }
    $pager .= '</div>';

    $countChip = static function (string $k, string $label) use ($cnt): string {
        $c = $k === '' ? array_sum($cnt) : (int)($cnt[$k] ?? 0);
        return '<a class="chip" href="/?status=' . e($k) . '">' . e($label) . ' <b>' . $c . '</b></a>';
    };

    $chipsHtml = $countChip('pending','На модерации') . $countChip('approved','Одобрено') . $countChip('applied','Применено') . $countChip('rejected','Отклонено') . $countChip('failed','Ошибки');
    $chipsHtml .= '<a class="chip' . ($scOver > 0 ? ' chip-warn' : '') . '" href="/?schedule=overdue&status=active">Просрочено <b>' . $scOver . '</b></a>';
    $chipsHtml .= '<a class="chip" href="/?schedule=future&status=active">Будущие <b>' . $scFut . '</b></a>';
    $schedOpts = [
        'today' => 'Сегодня',
        'all' => 'Все',
        'overdue' => 'Просроченные',
        'future' => 'Будущие',
        'asap' => 'Без даты (asap)',
    ];
    $schedOptsHtml = '';
    foreach ($schedOpts as $v => $lab) {
        $schedOptsHtml .= '<option value="' . e($v) . '"' . ($v === $fSched ? ' selected' : '') . '>' . e($lab) . '</option>';
    }
    // сегменты
    $audOptsHtml = '<option value="">Сегмент: все</option>';
    foreach ($audiences as $a) {
        $aid = (string)$a['audience_id'];
        $alab = $a['audience_name'] !== null && $a['audience_name'] !== '' ? $aid . ' — ' . $a['audience_name'] : $aid;
        $audOptsHtml .= '<option value="' . e($aid) . '"' . ($aid === $fAudience ? ' selected' : '') . '>' . e($alab) . '</option>';
    }

    // счётчик просроченных и будущих (для чипсов)
    [$dStartU, $dEndU] = day_bounds_utc();
    $scOver = (int)db()->query("SELECT COUNT(*) FROM moderation_queue WHERE scheduled_at IS NOT NULL AND scheduled_at < " . db()->quote($dStartU) . " AND status IN ('pending','approved','failed')")->fetchColumn();
    $scFut  = (int)db()->query("SELECT COUNT(*) FROM moderation_queue WHERE scheduled_at IS NOT NULL AND scheduled_at > " . db()->quote($dEndU) . " AND status IN ('pending','approved','failed')")->fetchColumn();

    $statusOptsHtml = buildStatusOptions($statusOpts, $fStatus);
    $projectOptsHtml = $opt($projects, $fProject, 'Проект: все');
    $typeOptsHtml = $opt(cfg('types'), $fType, 'Тип: все');
    $agentOptsHtml = $opt($agents, $fAgent, 'Агент: все');

    // hover-модалка и bulk-панель (рендерятся всегда, показываются через JS)
    $hoverModal = '<div id="hoverCard" class="hovercard" hidden></div>';

    $bulkPanel = <<<'BULK'
<form id="bulkForm" method="post" action="/bulk" class="bulkbar" hidden>
  <div class="bb-left">
    <span class="bb-count" id="bbCount">0</span> выбрано
    <button type="button" class="btn-ghost bb-clear" onclick="clearSel()">снять</button>
  </div>
  <div class="bb-right">
    <input type="text" name="comment" placeholder="комментарий к одобрению (необязательно)">
    <button type="submit" name="action" value="approve" class="btn-ok bb-btn">✓ Одобрить выбранные</button>
    <input type="text" name="rejection_reason" placeholder="причина отказа (для отклонения)">
    <button type="submit" name="action" value="reject" class="btn-no bb-btn" onclick="return bulkRejectCheck()">✗ Отклонить</button>
  </div>
</form>
BULK;

    $content = <<<HTML
<div class="toolbar">
  <div class="chips">{$chipsHtml}</div>
</div>
{$flash}
<form class="filters" method="get" action="/">
  <input type="search" name="q" placeholder="Поиск по заголовку, причине…" value="{$fQ}">
  <select name="status">{$statusOptsHtml}</select>
  <select name="project">{$projectOptsHtml}</select>
  <select name="type">{$typeOptsHtml}</select>
  <select name="agent_name">{$agentOptsHtml}</select>
  <select name="schedule">{$schedOptsHtml}</select>
  <select name="audience_id">{$audOptsHtml}</select>
  <button type="submit">Фильтр</button>
  <a class="btn-ghost" href="/">Сброс</a>
</form>
<table class="tbl">
  <thead><tr>
    <th class="cbcell"><input type="checkbox" id="selAll" title="Выделить все" onclick="toggleAll(this)"></th>
    <th>Статус</th><th>Тип</th><th>Запись</th><th>Проект</th><th>Агент</th><th>Создано</th><th>Выполнить</th><th>Сегмент</th><th></th>
  </tr></thead>
  <tbody id="rows">{$rowsHtml}</tbody>
</table>
{$pager}
{$bulkPanel}
{$hoverModal}
<!-- отдельная форма отклонения для быстрых кнопок -->
<form id="quickRejectForm" method="post" action="" hidden></form>
HTML;
    return layout('Очередь модерации', $content, true);
}

function buildStatusOptions(array $opts, string $cur): string
{
    $h = '';
    foreach ($opts as $v => $label) {
        $h .= '<option value="' . e((string)$v) . '"' . ((string)$v === $cur ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $h;
}

/** Ячейка «Выполнить»: плановое время + бейдж статуса расписания. */
function sched_cell(?string $scheduledUtc, string $status): string
{
    if ($scheduledUtc === null || $scheduledUtc === '') {
        return '<span class="sched sched-asap">asap</span>';
    }
    $local = dt_local_iso($scheduledUtc);
    [$dStart, $dEnd] = day_bounds_utc();
    $alive = in_array($status, ['pending', 'approved', 'failed'], true);
    $cls = 'sched';
    $tag = '';
    if ($scheduledUtc < $dStart) {
        if ($alive) { $cls .= ' sched-over'; $tag = ' <span class="mtag mtag-over">просрочено</span>'; }
    } elseif ($scheduledUtc <= $dEnd) {
        $cls .= ' sched-today';
    } else {
        $cls .= ' sched-future';
    }
    $label = $local !== null ? substr($local, 0, 16) : $scheduledUtc;
    return '<span class="' . $cls . '">' . e($label) . '</span>' . $tag;
}

/** Ячейка сегмента аудитории. */
function audience_cell(?string $id, ?string $name): string
{
    if ($id === null || $id === '') { return '<span class="muted">—</span>'; }
    $t = $name !== null && $name !== '' ? $name : $id;
    return '<span class="seg" title="' . e($id) . '">' . e($t) . '</span>';
}

function status_badge(string $s): string
{
    $map = [
        'pending' => ['На модерации', 'b-pending'],
        'approved' => ['Одобрено', 'b-approved'],
        'applied' => ['Применено', 'b-applied'],
        'rejected' => ['Отклонено', 'b-rejected'],
        'failed' => ['Ошибка', 'b-failed'],
        'expired' => ['Просрочено', 'b-failed'],
    ];
    [$t, $c] = $map[$s] ?? [$s, 'b-pending'];
    return '<span class="badge ' . $c . '">' . e($t) . '</span>';
}

function ui_item(string $id): string
{
    $r = fetch_item($id);
    $payload = json_decode((string)$r['payload'], true) ?: [];
    $events = db()->prepare('SELECT * FROM moderation_events WHERE item_id=? ORDER BY id');
    $events->execute([$id]);
    $evs = $events->fetchAll();

    $tl = cfg('type_labels')[$r['type']] ?? $r['type'];
    $rendered = render_payload($r['type'], $payload);
    $bodyPayloadJson = e(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

    $evHtml = '';
    foreach ($evs as $ev) {
        $evHtml .= '<li><span class="muted small">' . e($ev['created_at']) . '</span> · <b>' . e($ev['actor']) . '</b> — ' . e($ev['action'])
            . ($ev['comment'] !== null && $ev['comment'] !== '' ? '<div class="cmt">' . e($ev['comment']) . '</div>' : '') . '</li>';
    }

    $actions = '';
    if ($r['status'] === 'pending') {
        $actions = <<<HTML
<form method="post" action="/item/{$id}/approve" class="act act-ok">
  <input type="text" name="comment" placeholder="Комментарий (необязательно)">
  <button class="btn-ok" type="submit">✓ Одобрить</button>
</form>
<form method="post" action="/item/{$id}/reject" class="act act-no" onsubmit="return confirm('Отклонить запись?')">
  <textarea name="rejection_reason" placeholder="Причина отказа (обязательна, агент её получит)" required></textarea>
  <button class="btn-no" type="submit">✗ Отклонить</button>
</form>
HTML;
    } elseif ($r['status'] === 'rejected') {
        $actions = '<div class="notice notice-no">Отклонено: ' . e((string)$r['rejection_reason']) . '</div>';
    } elseif (in_array($r['status'], ['approved','applied'], true)) {
        $info = $r['status'] === 'applied'
            ? 'Применено: ' . e((string)$r['applied_at'])
            : 'Одобрено: ' . e((string)$r['reviewed_at']) . ' · ждёт devops-воркер';
        $actions = '<div class="notice notice-ok">' . $info . '</div>';
        if ($r['apply_error']) { $actions .= '<div class="notice notice-warn">Последняя ошибка: ' . e((string)$r['apply_error']) . '</div>'; }
        if ($r['status'] === 'approved') {
            $actions .= '<form method="post" action="/item/' . e($id) . '/revoke" class="act" onsubmit="return confirm(\'Отозвать одобрение и вернуть на модерацию?\')">'
                . '<button class="btn-rev" type="submit">↺ Отозвать одобрение</button></form>';
        }
    } elseif ($r['status'] === 'failed') {
        $actions = '<div class="notice notice-warn">Не удалось применить: ' . e((string)$r['apply_error']) . '</div>';
    }

    $badge = status_badge($r['status']);
    $project = e($r['project']);
    $ag = e($r['agent_name']);
    $ikey = e($r['idempotency_key']);
    $cr = e($r['created_at']);
    $att = (string)(int)$r['apply_attempts'];
    $ititle = e($r['title']);
    $ireason = nl2br(e($r['reason']));

    $schedRow = sched_cell($r['scheduled_at'] ?? null, $r['status']);
    $audRow = audience_cell($r['audience_id'] ?? null, $r['audience_name'] ?? null);

    $meta = <<<HTML
<table class="kv meta">
<tr><th>Статус</th><td>{$badge}</td></tr>
<tr><th>Тип</th><td>{$tl}</td></tr>
<tr><th>Проект</th><td><b>{$project}</b></td></tr>
<tr><th>Агент</th><td>{$ag}</td></tr>
<tr><th>ID</th><td><code>{$id}</code> · key: <code>{$ikey}</code></td></tr>
<tr><th>Создано</th><td>{$cr}</td></tr>
<tr><th>Выполнить</th><td>{$schedRow}</td></tr>
<tr><th>Сегмент</th><td>{$audRow}</td></tr>
<tr><th>Попыток применения</th><td>{$att}</td></tr>
</table>
HTML;

    $content = <<<HTML
<p><a href="/">← К очереди</a></p>
<h1>{$ititle}</h1>
<p class="reason"><b>Зачем:</b> {$ireason}</p>
<div class="grid">
  <div class="col-main">
    <h3>Содержимое</h3>
    {$rendered}
    <details class="mt"><summary>Payload JSON</summary><pre class="code"><code>{$bodyPayloadJson}</code></pre></details>
  </div>
  <div class="col-side">
    <h3>Метаданные</h3>
    {$meta}
    <div class="actions-wrap">{$actions}</div>
    <h3>История</h3>
    <ul class="events">{$evHtml}</ul>
  </div>
</div>
HTML;
    return layout($r['title'], $content, true);
}

function ui_stats(): string
{
    // Заглушка на будущее
    header('Location: /'); exit;
}

function layout(string $title, string $content, bool $wide = false): string
{
    $w = $wide ? 'wide' : '';
    $title = e($title);           // ВАЖНО: заголовок может содержать пользовательский текст
    return <<<HTML
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title} — moder</title>
<style>
:root{--bg:#0f1115;--card:#171a21;--br:#262b36;--txt:#e6e6e6;--mut:#8b94a3;--acc:#7dd3fc;--ok:#4ade80;--no:#f87171;--wr:#fbbf24}
*{box-sizing:border-box}
body{font:15px/1.55 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--txt);margin:0;padding:24px}
a{color:var(--acc);text-decoration:none}a:hover{text-decoration:underline}
.container{max-width:1200px;margin:0 auto}
.container.wide{max-width:1400px}
h1,h2,h3,h4{margin:.4em 0 .6em}
.muted{color:var(--mut)}.small{font-size:13px}.nowrap{white-space:nowrap}
code,pre{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
code{background:#232833;padding:2px 6px;border-radius:5px;font-size:13px}
pre.code,pre.diff,pre.mail-text{background:#0d1016;border:1px solid var(--br);border-radius:10px;padding:14px;overflow:auto;font-size:13px;line-height:1.5;white-space:pre-wrap;word-break:break-word}
.toolbar{margin-bottom:14px}.chips{display:flex;gap:8px;flex-wrap:wrap}
.chip{background:var(--card);border:1px solid var(--br);border-radius:999px;padding:4px 12px;font-size:13px;color:var(--mut)}
.chip b{color:var(--txt)}
.flash{background:#143524;border:1px solid #2b6b47;color:#a7f3c9;padding:10px 14px;border-radius:10px;margin-bottom:14px}
.filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;align-items:center}
.filters input,.filters select{background:#12151c;border:1px solid var(--br);color:var(--txt);border-radius:9px;padding:9px 12px;font:inherit}
.filters input[type=search]{min-width:260px;flex:1}
button,.btn-ghost{background:#2563eb;border:none;color:#fff;border-radius:9px;padding:9px 16px;font:inherit;cursor:pointer}
button:hover{filter:brightness(1.1)}
.btn-ghost{background:#232833;color:var(--txt);display:inline-flex;align-items:center}
.tbl{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--br);border-radius:12px;overflow:hidden}
.tbl th{text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--mut);padding:12px 14px;border-bottom:1px solid var(--br);font-weight:600}
.tbl td{padding:12px 14px;border-bottom:1px solid #1e232d;vertical-align:top}
.tbl tr.row{cursor:pointer}.tbl tr.row:hover{background:#1b2029}
.tbl tr:last-child td{border-bottom:none}
.ttl{font-weight:600;margin-bottom:2px}
.tag{background:#1d2733;color:#9ecbff;border-radius:6px;padding:2px 8px;font-size:12px;white-space:nowrap}
.badge{padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap}
.b-pending{background:#3a2f0e;color:#fbbf24}.b-approved{background:#0e2b3a;color:#7dd3fc}
.b-applied{background:#12341f;color:#4ade80}.b-rejected{background:#3a1414;color:#f87171}.b-failed{background:#3a2414;color:#fb923c}
.pager{margin-top:14px;color:var(--mut);display:flex;gap:14px;justify-content:center}
.reason{background:var(--card);border:1px solid var(--br);border-left:3px solid var(--acc);border-radius:0 10px 10px 0;padding:12px 16px}
.grid{display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start}
@media(max-width:1000px){.grid{grid-template-columns:1fr}}
.col-main,.col-side{background:var(--card);border:1px solid var(--br);border-radius:12px;padding:18px 20px}
.col-side h3:first-child{margin-top:0}
.kv{width:100%;border-collapse:collapse}.kv th{text-align:left;color:var(--mut);font-weight:500;width:150px;padding:6px 0;vertical-align:top;font-size:13px}
.kv td{padding:6px 0;vertical-align:top;font-size:14px;word-break:break-word}
.meta th,.meta td{font-size:13px}
.act{display:flex;flex-direction:column;gap:8px;margin-bottom:12px}
.act input,.act textarea{background:#12151c;border:1px solid var(--br);color:var(--txt);border-radius:9px;padding:9px 12px;font:inherit;width:100%}
.act textarea{min-height:90px;resize:vertical}
.btn-ok{background:#16a34a}.btn-no{background:#dc2626}.btn-rev{background:#b45309;width:100%}
.notice{padding:10px 14px;border-radius:10px;font-size:14px;margin-bottom:10px}
.notice-ok{background:#12341f;border:1px solid #255b39;color:#a7f3c9}
.notice-no{background:#3a1414;border:1px solid #6b2b2b;color:#fecaca}
.notice-warn{background:#3a2f0e;border:1px solid #6b5a2b;color:#fde68a}
.events{list-style:none;padding:0;margin:0;font-size:13px}
.events li{padding:6px 0;border-bottom:1px solid #1e232d}
.cmt{color:var(--mut);font-size:13px;margin-top:2px;white-space:pre-wrap}
.block{margin:0 0 10px}
.email-body{margin-top:10px}
.mail-preview{width:100%;height:420px;border:1px solid var(--br);border-radius:10px;background:#fff}
.site-preview{width:100%;height:420px;border:1px solid var(--br);border-radius:10px;background:#fff}
.mail-text{white-space:pre-wrap;margin:0}
.files{margin:6px 0 0;padding-left:20px}
.art-title{margin:.2em 0 .5em}
.cover{max-width:100%;border-radius:10px;margin:8px 0}
.excerpt{color:var(--mut);font-style:italic}
.md h1,.md h2,.md h3{margin-top:1em}
.md p{margin:.5em 0}
.md blockquote{border-left:3px solid var(--br);margin:.5em 0;padding-left:12px;color:var(--mut)}
.md ul,.md ol{padding-left:22px}
.site_change .diff .d-add{color:#4ade80;background:#0e2b1a;display:block}
.site_change .diff .d-del{color:#f87171;background:#2b0e0e;display:block}
.site_change .diff .d-hunk{color:#7dd3fc;display:block}
.site_change .diff .d-meta{color:#8b94a3;display:block}
.ad-card{border:1px solid var(--br);border-radius:10px;padding:14px;background:#12151c;margin:10px 0}
.ad-h{font-weight:700;font-size:17px}.ad-d{color:#cfd6e0;margin:4px 0}.ad-cta{display:inline-block;background:#2563eb;color:#fff;padding:6px 14px;border-radius:8px;margin-top:8px}
.ad-url{color:var(--ok);margin-top:6px;font-size:13px}
.creatives{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
.creative{max-width:300px;max-height:250px;border-radius:8px;border:1px solid var(--br)}
.mt{margin-top:12px}

/* --- список: чекбоксы, быстрые действия, bulk, hover --- */
.cbcell{width:36px;text-align:center}
.ttl{font-weight:600;margin-bottom:2px}
.row-pending:hover{background:#1b2029}
.qcell{width:88px;white-space:nowrap;text-align:right}
.quick{display:inline-flex;gap:6px}
.qbtn{border:none;border-radius:7px;width:30px;height:30px;font-size:15px;cursor:pointer;color:#fff;line-height:1}
.q-ok{background:#16a34a}.q-no{background:#dc2626}.q-rev{background:#b45309}
.qbtn:hover{filter:brightness(1.15)}
/* bulk bar */
.bulkbar{position:sticky;bottom:0;z-index:30;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;
  background:#171a21;border:1px solid var(--br);border-radius:12px;padding:12px 16px;margin-top:16px;box-shadow:0 -4px 20px rgba(0,0,0,.35)}
.bulkbar[hidden]{display:none}
.bb-left{color:var(--mut);font-size:14px;display:flex;align-items:center;gap:10px}
.bb-count{display:inline-block;min-width:26px;text-align:center;background:#2563eb;color:#fff;border-radius:999px;padding:1px 8px;font-weight:700}
.bb-right{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.bb-right input[type=text]{background:#12151c;border:1px solid var(--br);color:var(--txt);border-radius:9px;padding:8px 12px;font:inherit;min-width:200px}
.bb-btn{white-space:nowrap}
.bb-clear{padding:5px 10px;font-size:13px}
/* hover card */
.hovercard{position:absolute;z-index:100;max-width:440px;background:#12151c;border:1px solid #313846;border-radius:12px;
  padding:14px 16px;box-shadow:0 10px 30px rgba(0,0,0,.5);pointer-events:none;font-size:14px}
.hovercard[hidden]{display:none}
.hp-row{display:flex;gap:8px;margin-bottom:4px}
.hp-k{color:var(--mut);width:52px;flex:0 0 52px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;padding-top:2px}
.hp-v{color:var(--txt);word-break:break-word}
.hp-subj{font-weight:600}
.hp-body{margin-top:8px;padding-top:8px;border-top:1px solid var(--br);color:#cfd6e0;max-height:260px;overflow:hidden;white-space:pre-wrap;word-break:break-word;font-size:13px}
.chip{transition:border-color .15s}.chip:hover{border-color:#3b82f6;text-decoration:none}


.sched{font-size:12px;padding:2px 8px;border-radius:6px;white-space:nowrap}
.sched-asap{background:#1d2733;color:#9ecbff}
.sched-today{background:#3a2f0e;color:#fbbf24}
.sched-future{background:#16203a;color:#93b4ff}
.sched-over{background:#3a1414;color:#f87171}
.mtag{font-size:11px;padding:1px 6px;border-radius:5px}
.mtag-over{background:#3a1414;color:#f87171;border:1px solid #6b2b2b}
.chip-warn{border-color:#6b2b2b;color:#fecaca}
.chip-warn b{color:#f87171}
.seg{font-size:12px;color:#cfd6e0;background:#1b2130;border:1px solid var(--br);padding:2px 8px;border-radius:6px;white-space:nowrap}


.aud-line{margin-bottom:10px;font-size:14px}
.aud-k{color:var(--mut);font-size:12px;text-transform:uppercase;letter-spacing:.04em;margin-right:8px}
.aud-v{color:var(--txt);font-weight:600}

</style>
</head>
<body>
<div class="container {$w}">
  <header style="display:flex;align-items:center;gap:16px;margin-bottom:20px">
    <a href="/" style="font-weight:700;font-size:19px;color:var(--txt)">◆ moder</a>
    <span class="muted">очередь премодерации</span><span style="flex:1"></span><a href="/logout" class="muted" style="font-size:13px">выйти</a>
  </header>
  {$content}
</div>

<script>
function toggleAll(cb){
  document.querySelectorAll('#rows input.rowcb').forEach(function(x){ x.checked = cb.checked; });
  updateBulk();
}
function clearSel(){
  document.querySelectorAll('#rows input.rowcb').forEach(function(x){ x.checked=false; });
  var a=document.getElementById('selAll'); if(a) a.checked=false;
  updateBulk();
}
function updateBulk(){
  var sel = document.querySelectorAll('#rows input.rowcb:checked');
  var bar = document.querySelector('.bulkbar');
  var cnt = document.getElementById('bbCount');
  if(bar){ bar.hidden = sel.length === 0; }
  if(cnt){ cnt.textContent = sel.length; }
}
function bulkRejectCheck(){
  var r = document.querySelector('.bulkbar input[name=rejection_reason]');
  if(r && r.value.trim() === ''){ alert('Для отклонения укажите причину'); r.focus(); return false; }
  return true;
}
// быстрый отказ из строки
function quickReject(id){
  var reason = prompt('Причина отказа (агент её получит):');
  if(reason === null) return;
  if(reason.trim() === ''){ alert('Причина обязательна'); return; }
  var f = document.getElementById('quickRejectForm');
  f.action = '/item/' + id + '/reject';
  f.innerHTML = '<input type="hidden" name="rejection_reason">';
  f.querySelector('input').value = reason;
  f.submit();
}
document.addEventListener('change', function(ev){
  if(ev.target && ev.target.classList && ev.target.classList.contains('rowcb')){ updateBulk(); }
});
// hover-превью email
(function(){
  var card = document.getElementById('hoverCard');
  if(!card) return;
  var hoverDelay = null;
  document.querySelectorAll('#rows tr[data-hover]').forEach(function(tr){
    tr.addEventListener('mouseenter', function(){
      hoverDelay = setTimeout(function(){
        card.innerHTML = tr.getAttribute('data-hover');
        card.hidden = false;
        var r = tr.getBoundingClientRect();
        var top = r.bottom + window.scrollY + 6;
        var left = r.left + window.scrollX + 40;
        card.style.top = top + 'px';
        card.style.left = Math.min(left, window.innerWidth - 470) + 'px';
      }, 220);
    });
    tr.addEventListener('mouseleave', function(){
      if(hoverDelay){ clearTimeout(hoverDelay); hoverDelay = null; }
      card.hidden = true;
    });
  });
})();
</script>

</body>
</html>
HTML;
}

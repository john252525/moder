<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/core.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// при монтировании под /phpmyadmin и т.п. — нормализуем base path
$base = getenv('APP_BASE_PATH') ?: '';
if ($base !== '' && str_starts_with($uri, $base)) {
    $uri = substr($uri, strlen($base)) ?: '/';
}

// --- API ---
if (str_starts_with($uri, '/api/')) {
    require_once __DIR__ . '/../app/api.php';
    db_migrate();
    try {
        api_handle($method, $uri, $_GET);
    } catch (ApiError $e) {
        json_out(['error' => $e->errorCode, 'message' => $e->getMessage()], $e->httpCode);
    } catch (Throwable $e) {
        error_log('[moder api] ' . $e);
        json_out(['error' => 'internal', 'message' => 'Internal error'], 500);
    }
}

// --- healthcheck (без авторизации) ---
if ($uri === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    $ok = false; $err = null; $ver = null;
    try {
        db_migrate();
        $ver = (string)db()->query('SELECT VERSION()')->fetchColumn();
        $ok = true;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    http_response_code($ok ? 200 : 503);
    echo json_encode([
        'status' => $ok ? 'ok' : 'degraded',
        'php' => PHP_VERSION,
        'mysql' => $ver,
        'time' => date('c'),
        'error' => $err,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- UI ---
db_migrate();
require_once __DIR__ . '/../app/ui.php';
ui_handle($method, $uri, $_GET, $_POST);

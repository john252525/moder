<?php
declare(strict_types=1);

/**
 * Конфигурация приложения (секреты берутся из ENV, см. .env.example).
 * Файл — ЧИСТЫЙ `return [...]`, без объявления функций и побочных эффектов,
 * чтобы его можно было безопасно require-ить.
 */
$tok = static function (string $env): array {
    $raw = getenv($env);
    if (!is_string($raw) || trim($raw) === '') { return []; }
    return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $v): bool => $v !== ''));
};

return [
    'db' => [
        'host' => getenv('MYSQL_HOST') ?: 'mysql',
        'port' => (int)(getenv('MYSQL_PORT') ?: 3306),
        'name' => getenv('MYSQL_DATABASE') ?: 'moder',
        'user' => getenv('MYSQL_USER') ?: 'moder',
        'pass' => getenv('MYSQL_PASSWORD') ?: '',
    ],
    // API-токены. Пустая переменная = роль отключена (fail-closed).
    'tokens' => [
        'agent' => $tok('MODER_TOKEN_AGENT'),
        'moder' => $tok('MODER_TOKEN_MODER'),
        'devops' => $tok('MODER_TOKEN_DEVOPS'),
    ],
    'max_apply_attempts' => 3,
    'types' => ['email', 'article', 'site_change', 'ad', 'other'],
    'type_labels' => [
        'email' => 'Письмо',
        'article' => 'Статья',
        'site_change' => 'Изменение сайта',
        'ad' => 'Реклама',
        'other' => 'Другое',
    ],
    'statuses' => ['pending', 'approved', 'rejected', 'applied', 'failed', 'expired', 'sent'],

    // UI-настройки (можно выключить через ENV, не трогая код)
    'ui' => [
        // hover-превью письма в списке (модалка при наведении на заголовок)
        // Внимание: getenv() возвращает строку; "0" в PHP falsy -> нельзя `?:`.
        // Часовой пояс модератора (влияет на границы «сегодня» и отображение времени)
        'timezone' => getenv('MODER_TZ') ?: 'Europe/Moscow',
        'email_hover' => (static function (): bool {
            $v = getenv('MODER_UI_EMAIL_HOVER');
            if (!is_string($v) || trim($v) === '') { return true; } // дефолт = вкл
            return trim($v) !== '0' && strtolower(trim($v)) !== 'false';
        })(),
    ],
];

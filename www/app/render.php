<?php
declare(strict_types=1);

require_once __DIR__ . '/markdown.php';

/** Безопасный вывод произвольного поля payload как строки. */
function p_str(array $p, string $k, string $def = ''): string
{
    $v = $p[$k] ?? $def;
    if (is_array($v) || is_object($v)) { return ''; }
    return (string)$v;
}

function p_list(array $p, string $k): array
{
    $v = $p[$k] ?? [];
    return is_array($v) ? array_values(array_filter($v, 'is_scalar')) : [];
}

/**
 * Полиморфный рендер payload по типу записи.
 * Возвращает HTML (уже безопасный: все значения экранируются).
 */
function render_payload(string $type, array $p): string
{
    $aud = '';
    if (isset($p['audience']) && is_array($p['audience'])) {
        $aid = (string)($p['audience']['id'] ?? '');
        $anm = (string)($p['audience']['name'] ?? '');
        if ($aid !== '' || $anm !== '') {
            $aud = '<div class="aud-line"><span class="aud-k">Сегмент</span> '
                 . '<span class="aud-v">' . e($anm !== '' ? $anm : $aid) . '</span>'
                 . ($anm !== '' && $aid !== '' ? ' <code>' . e($aid) . '</code>' : '')
                 . '</div>';
        }
    }
    return $aud . match ($type) {
        'email' => render_email($p),
        'article' => render_article($p),
        'site_change' => render_site_change($p),
        'ad' => render_ad($p),
        default => render_generic($p),
    };
}

function render_email(array $p): string
{
    $to = p_list($p, 'to');
    $cc = p_list($p, 'cc');
    $bcc = p_list($p, 'bcc');
    $subject = p_str($p, 'subject');
    $from = p_str($p, 'from');
    $html = p_str($p, 'html');
    $text = p_str($p, 'body');
    $att = $p['attachments'] ?? [];

    $o = '<div class="block email">';
    $o .= '<table class="kv">';
    if ($from !== '') { $o .= '<tr><th>От</th><td>' . e($from) . '</td></tr>'; }
    $o .= '<tr><th>Кому</th><td>' . e(implode(', ', $to)) . '</td></tr>';
    if ($cc) { $o .= '<tr><th>Копия</th><td>' . e(implode(', ', $cc)) . '</td></tr>'; }
    if ($bcc) { $o .= '<tr><th>Скрытая</th><td>' . e(implode(', ', $bcc)) . '</td></tr>'; }
    $o .= '<tr><th>Тема</th><td><b>' . e($subject) . '</b></td></tr>';
    $o .= '</table>';

    $o .= '<div class="email-body">';
    if ($html !== '') {
        // HTML письма рендерим в песочнице iframe (srcdoc), а не в основном DOM
        $src = 'data:text/html;charset=utf-8;base64,' . base64_encode($html);
        $o .= '<iframe class="mail-preview" sandbox referrerpolicy="no-referrer" srcdoc="'
            . e('<base target="_blank"><style>body{font:14px/1.5 system-ui,Arial,sans-serif;padding:12px;color:#111;background:#fff}</style>' . $html)
            . '"></iframe>';
        $o .= '<details class="mt"><summary>HTML источника</summary><pre class="code"><code>' . e($html) . '</code></pre></details>';
    } elseif ($text !== '') {
        $o .= '<pre class="mail-text">' . e($text) . '</pre>';
    } else {
        $o .= '<p class="muted">Нет тела письма</p>';
    }
    $o .= '</div>';

    if (is_array($att) && $att !== []) {
        $o .= '<div class="mt"><b>Вложения:</b><ul class="files">';
        foreach ($att as $a) {
            if (is_array($a)) {
                $name = e((string)($a['filename'] ?? 'file'));
                $url = (string)($a['url'] ?? '');
                $size = isset($a['size']) ? (int)$a['size'] : null;
                $sz = $size !== null ? ' <span class="muted">(' . e(number_format($size)) . ' б)</span>' : '';
                $o .= '<li>' . ($url !== '' && preg_match('#^https?://#i', $url)
                    ? '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer nofollow">' . $name . '</a>' . $sz
                    : $name . $sz) . '</li>';
            } else {
                $o .= '<li>' . e((string)$a) . '</li>';
            }
        }
        $o .= '</ul></div>';
    }
    $o .= '</div>';
    return $o;
}

function render_article(array $p): string
{
    $title = p_str($p, 'title');
    $slug = p_str($p, 'slug');
    $cover = p_str($p, 'cover');
    $excerpt = p_str($p, 'excerpt');
    $md = p_str($p, 'markdown') ?: p_str($p, 'body');

    $o = '<div class="block article">';
    if ($slug !== '') { $o .= '<p class="muted">slug: <code>' . e($slug) . '</code></p>'; }
    if ($title !== '') { $o .= '<h2 class="art-title">' . e($title) . '</h2>'; }
    if ($cover !== '' && preg_match('#^https?://#i', $cover)) {
        $o .= '<img class="cover" src="' . e($cover) . '" alt="cover" loading="lazy">';
    }
    if ($excerpt !== '') { $o .= '<p class="excerpt">' . e($excerpt) . '</p>'; }
    $o .= '<div class="md">' . md_to_html($md) . '</div>';
    if ($md !== '') {
        $o .= '<details class="mt"><summary>Markdown источника</summary><pre class="code"><code>' . e($md) . '</code></pre></details>';
    }
    $o .= '</div>';
    return $o;
}

function render_site_change(array $p): string
{
    $o = '<div class="block site_change">';
    $url = p_str($p, 'preview_url');
    if ($url !== '' && preg_match('#^https?://#i', $url)) {
        $o .= '<p><a href="' . e($url) . '" target="_blank" rel="noopener noreferrer nofollow">Открыть превью ↗</a></p>';
        $o .= '<iframe class="site-preview" src="' . e($url) . '" sandbox="allow-scripts allow-same-origin" referrerpolicy="no-referrer" loading="lazy"></iframe>';
    }
    $pr = p_str($p, 'pr_url');
    if ($pr !== '' && preg_match('#^https?://#i', $pr)) {
        $o .= '<p>PR: <a href="' . e($pr) . '" target="_blank" rel="noopener noreferrer nofollow">' . e($pr) . '</a></p>';
    }
    $branch = p_str($p, 'branch');
    $commit = p_str($p, 'commit');
    $files = p_list($p, 'files');
    if ($branch !== '' || $commit !== '' || $files) {
        $o .= '<table class="kv">';
        if ($branch !== '') { $o .= '<tr><th>Ветка</th><td><code>' . e($branch) . '</code></td></tr>'; }
        if ($commit !== '') { $o .= '<tr><th>Коммит</th><td><code>' . e($commit) . '</code></td></tr>'; }
        if ($files) { $o .= '<tr><th>Файлы</th><td>' . e(implode(', ', $files)) . '</td></tr>'; }
        $o .= '</table>';
    }
    $diff = p_str($p, 'diff');
    if ($diff !== '') {
        $o .= '<h4>Diff</h4><pre class="diff">' . render_diff($diff) . '</pre>';
    }
    $o .= '</div>';
    return $o;
}

/** Раскраска unified diff без исполнения чего-либо. */
function render_diff(string $diff): string
{
    $lines = preg_split('/\r?\n/', $diff) ?: [];
    $out = [];
    foreach ($lines as $ln) {
        $cls = '';
        if (str_starts_with($ln, '+++') || str_starts_with($ln, '---')) { $cls = 'd-meta'; }
        elseif (str_starts_with($ln, '+')) { $cls = 'd-add'; }
        elseif (str_starts_with($ln, '-')) { $cls = 'd-del'; }
        elseif (str_starts_with($ln, '@@')) { $cls = 'd-hunk'; }
        $out[] = $cls !== '' ? '<span class="' . $cls . '">' . e($ln) . '</span>' : e($ln);
    }
    return implode("\n", $out);
}

function render_ad(array $p): string
{
    $o = '<div class="block ad">';
    $o .= '<table class="kv">';
    if (($b = p_str($p, 'budget')) !== '') { $o .= '<tr><th>Бюджет</th><td>' . e($b) . '</td></tr>'; }
    if (($per = p_str($p, 'period')) !== '') { $o .= '<tr><th>Период</th><td>' . e($per) . '</td></tr>'; }
    if (($ch = p_str($p, 'channel')) !== '') { $o .= '<tr><th>Канал</th><td>' . e($ch) . '</td></tr>'; }
    $t = p_list($p, 'targeting');
    if ($t) { $o .= '<tr><th>Таргет</th><td>' . e(implode(', ', $t)) . '</td></tr>'; }
    $o .= '</table>';
    $headline = p_str($p, 'headline');
    $dtext = p_str($p, 'description');
    $cta = p_str($p, 'cta');
    $landing = p_str($p, 'landing_url');
    if ($headline !== '' || $dtext !== '') {
        $o .= '<div class="ad-card">';
        if ($headline !== '') { $o .= '<div class="ad-h">' . e($headline) . '</div>'; }
        if ($dtext !== '') { $o .= '<div class="ad-d">' . e($dtext) . '</div>'; }
        if ($cta !== '') { $o .= '<div class="ad-cta">' . e($cta) . '</div>'; }
        if ($landing !== '' && preg_match('#^https?://#i', $landing)) {
            $o .= '<div class="ad-url">' . e($landing) . '</div>';
        }
        $o .= '</div>';
    }
    $creatives = $p['creatives'] ?? [];
    if (is_array($creatives) && $creatives !== []) {
        $o .= '<div class="creatives">';
        foreach ($creatives as $c) {
            $u = is_array($c) ? (string)($c['url'] ?? '') : (string)$c;
            if ($u !== '' && preg_match('#^https?://#i', $u)) {
                $o .= '<img class="creative" src="' . e($u) . '" alt="creative" loading="lazy">';
            }
        }
        $o .= '</div>';
    }
    $txt = p_str($p, 'text');
    if ($txt !== '') { $o .= '<pre class="mail-text">' . e($txt) . '</pre>'; }
    $o .= '</div>';
    return $o;
}

function render_generic(array $p): string
{
    $o = '<div class="block generic"><pre class="code"><code>'
        . e(json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}')
        . '</code></pre></div>';
    return $o;
}

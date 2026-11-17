<?php
declare(strict_types=1);

/** Мини-markdown: безопасно (сначала escape, потом разметка). */
function md_to_html(string $md): string
{
    $blocks = [];
    // 1) вырезаем ```code``` до escaping
    $md = preg_replace_callback('/```[a-zA-Z0-9_+-]*\r?\n(.*?)```/s', static function (array $m) use (&$blocks): string {
        $i = count($blocks);
        $blocks[] = '<pre class="code"><code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code></pre>';
        return "\n\n%%BLOCK{$i}%%\n\n";
    }, $md) ?? $md;

    // 2) инлайн-код тоже вырезаем до escaping
    $md = preg_replace_callback('/`([^`\n]+)`/', static function (array $m) use (&$blocks): string {
        $i = count($blocks);
        $blocks[] = '<code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code>';
        return "%%BLOCK{$i}%%";
    }, $md) ?? $md;

    // 3) escape всего остального
    $esc = htmlspecialchars($md, ENT_QUOTES, 'UTF-8');

    // 4) блочная разметка построчно
    $out = [];
    $para = [];
    $list = null; // 'ul' | 'ol'

    $flushPara = static function () use (&$para, &$out): void {
        if ($para !== []) {
            $out[] = '<p>' . md_inline(implode("\n", $para)) . '</p>';
            $para = [];
        }
    };
    $flushList = static function () use (&$list, &$out): void {
        if ($list !== null) {
            $out[] = "</{$list}>";
            $list = null;
        }
    };

    foreach (preg_split('/\r?\n/', $esc) ?: [] as $line) {
        if (preg_match('/^%%BLOCK(\d+)%%$/', $line, $m)) {
            $flushPara();
            $flushList();
            $out[] = $blocks[(int)$m[1]] ?? '';
            continue;
        }
        if (trim($line) === '') {
            $flushPara();
            $flushList();
            continue;
        }
        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
            $flushPara();
            $flushList();
            $lvl = strlen($m[1]);
            $out[] = "<h{$lvl}>" . md_inline($m[2]) . "</h{$lvl}>";
            continue;
        }
        if (preg_match('/^\s*(?:---+|\*\*\*+)\s*$/', $line)) {
            $flushPara();
            $flushList();
            $out[] = '<hr>';
            continue;
        }
        if (preg_match('/^\s*[-*+]\s+(.*)$/', $line, $m)) {
            $flushPara();
            if ($list !== 'ul') { $flushList(); $out[] = '<ul>'; $list = 'ul'; }
            $out[] = '<li>' . md_inline($m[1]) . '</li>';
            continue;
        }
        if (preg_match('/^\s*\d+[.)]\s+(.*)$/', $line, $m)) {
            $flushPara();
            if ($list !== 'ol') { $flushList(); $out[] = '<ol>'; $list = 'ol'; }
            $out[] = '<li>' . md_inline($m[1]) . '</li>';
            continue;
        }
        if (preg_match('/^&gt;\s?(.*)$/', $line, $m)) {
            $flushPara();
            $flushList();
            $out[] = '<blockquote>' . md_inline($m[1]) . '</blockquote>';
            continue;
        }
        $flushList();
        $para[] = $line;
    }
    $flushPara();
    $flushList();

    return implode("\n", $out);
}

function md_inline(string $s): string
{
    $s = preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $s) ?? $s;
    $s = preg_replace('/(?<![\*\w])\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $s) ?? $s;
    $s = preg_replace('/\[([^\]\n]+)\]\((https?:\/\/[^)\s]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer nofollow">$1</a>', $s) ?? $s;
    return $s;
}

<?php
/** @var list<array<string, mixed>> $body */
foreach ($body as $block) {
    if (!is_array($block)) {
        continue;
    }
    $type = (string)($block['type'] ?? 'p');
    if ($type === 'p') {
        echo '<p>' . wwm_escape((string)($block['text'] ?? '')) . '</p>';
        continue;
    }
    if ($type === 'ul' || $type === 'ol') {
        $items = is_array($block['items'] ?? null) ? $block['items'] : [];
        echo $type === 'ol' ? '<ol class="admin-guide-list">' : '<ul class="admin-guide-list">';
        foreach ($items as $item) {
            echo '<li>' . wwm_escape((string)$item) . '</li>';
        }
        echo $type === 'ol' ? '</ol>' : '</ul>';
        continue;
    }
    if ($type === 'info' || $type === 'warning') {
        $class = $type === 'warning' ? 'alert alert-warning' : 'alert alert-info';
        echo '<div class="' . $class . ' admin-guide-callout">' . wwm_escape((string)($block['text'] ?? '')) . '</div>';
        continue;
    }
    if ($type === 'link') {
        $href = (string)($block['href'] ?? '');
        if ($href !== '' && str_starts_with($href, '/')) {
            echo '<p><a class="btn btn-ghost btn-sm" href="' . wwm_escape($href) . '">' . wwm_escape((string)($block['label'] ?? 'Open')) . '</a></p>';
        }
    }
}

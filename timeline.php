<?php
declare(strict_types=1);

/** Logo for an education or experience entry: the uploaded image, else a neutral initials tile. */
function org_tile(array $item, string $lang): string {
    $logo = trim((string) ($item['logo'] ?? ''));
    if ($logo !== '') {
        return '<span class="tl-slot"><img class="tl-logo" src="' . e($logo) . '" alt="" loading="lazy"></span>';
    }
    $name = trim(loc($item, 'org', $lang)) !== '' ? loc($item, 'org', $lang) : loc($item, 'title', $lang);
    $words = array_values(array_filter(preg_split('/\s+/u', trim($name)) ?: [], 'strlen'));
    $initials = mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2))));
    return '<span class="tl-slot"><span class="tl-logo tl-initials" style="--hue:' . (crc32($name) % 360) . '" aria-hidden="true">' . e($initials !== '' ? $initials : '•') . '</span></span>';
}

/** Dot opacity for work-history entries, counted from the oldest (bottom) up: the oldest is faintest and the newest at the top is full brand blue. Edit the list to change the fade. */
const TIMELINE_DOT_OPACITY = [0.2, 0.5];
function timeline_dot_opacity(int $fromOldest): string {
    return (string) (TIMELINE_DOT_OPACITY[$fromOldest] ?? 1.0);
}

/** One education entry: logo, then every detail. The fade class is kept for the entry's position, oldest faintest. */
function education_item_html(array $item, int $index, string $lang): string {
    $period = loc($item, 'period', $lang);
    $city = loc($item, 'city', $lang);
    $details = loc($item, 'details', $lang);
    $bullets = timeline_bullets($item, $lang);
    $org = loc($item, 'org', $lang);
    $text = '<p class="edu-title">' . e(loc($item, 'title', $lang)) . '</p>'
        . ($org !== '' ? '<p class="edu-org">' . e($org) . '</p>' : '')
        . (trim($period) !== '' ? '<p class="edu-when">' . e($period) . '</p>' : '')
        . (trim($city) !== '' ? '<p class="edu-city">' . e($city) . '</p>' : '')
        . (trim($details) !== '' ? '<p class="edu-details">' . nl2br(e($details)) . '</p>' : '')
        . ($bullets ? '<ul class="edu-bullets">' . implode('', array_map(fn($b) => '<li>' . e($b) . '</li>', $bullets)) . '</ul>' : '')
        . timeline_site($item, $lang);
    return '<li class="edu-item" style="--dot-o: ' . timeline_dot_opacity($index) . '">'
        . '<div class="edu-logo">' . org_tile($item, $lang) . '</div>'
        . '<div class="edu-text">' . $text . '</div></li>';
}

/** Bullet points, one per line; falls back to the description paragraph when there are none. */
function timeline_bullets(array $item, string $lang): array {
    $text = loc($item, 'bullets', $lang);
    return array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), 'strlen'));
}

/** A link to the organisation's website, only for http(s) addresses. */
function timeline_site(array $item, string $lang): string {
    $url = trim((string) ($item['website'] ?? ''));
    if ($url === '' || !preg_match('~^https?://~i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return '';
    }
    $host = (string) preg_replace('~^www\.~i', '', (string) parse_url($url, PHP_URL_HOST));
    return '<a class="tl-site" href="' . e($url) . '" target="_blank" rel="noopener">'
        . '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/></svg>'
        . '<span>' . e($host) . '</span><span class="sr-only"> (' . e(t('website_link', $lang)) . ')</span></a>';
}

/**
 * Skills as "name | icon key | parent:Name | more" lines. Entries without a brand icon get initials instead of a made-up logo.
 * "parent:" puts a skill inside its parent's expandable panel.
 */
function skill_items(string $text): array {
    $items = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $parts = array_map('trim', explode('|', $line));
        $name = $parts[0];
        if ($name === '') {
            continue;
        }
        $item = ['name' => $name, 'icon' => strtolower($parts[1] ?? ''), 'parent' => ''];
        foreach (array_slice($parts, 2) as $flag) {
            if (stripos($flag, 'parent:') === 0) {
                $item['parent'] = trim(substr($flag, 7));
            }
        }
        $items[] = $item;
    }
    return $items;
}

/** Groups skills into top-level cards with their children. A child whose parent is missing is shown as top-level. */
function skill_groups(array $items): array {
    $parents = [];
    foreach ($items as $item) {
        if ($item['parent'] === '') {
            $parents[strtolower($item['name'])] = true;
        }
    }
    $children = [];
    foreach ($items as $item) {
        $parent = strtolower($item['parent']);
        if ($parent !== '' && isset($parents[$parent])) {
            $children[$parent][] = $item;
        }
    }
    $cards = [];
    foreach ($items as $item) {
        $parent = strtolower($item['parent']);
        if ($parent !== '' && isset($parents[$parent])) {
            continue;
        }
        $cards[] = ['item' => $item, 'children' => $children[strtolower($item['name'])] ?? []];
    }
    return $cards;
}

/** One skill card. A card with children becomes a native disclosure, so it works with keyboard and screen readers without script. */
function skill_card_html(array $group): string {
    $item = $group['item'];
    $svg = skill_icon_svg($item['icon']);
    $icon = $svg !== null
        ? '<span class="skill-icon">' . $svg . '</span>'
        : '<span class="skill-icon skill-initials" aria-hidden="true">' . e(mb_strtoupper(mb_substr($item['name'], 0, 2))) . '</span>';
    $head = $icon . '<span class="skill-name">' . e($item['name']) . '</span>';
    if (!$group['children']) {
        return '<li class="skill">' . $head . '</li>';
    }
    $panelId = 'skill-panel-' . substr(md5($item['name']), 0, 8);
    $list = '';
    foreach ($group['children'] as $index => $child) {
        $childSvg = skill_icon_svg($child['icon']);
        $childIcon = $childSvg !== null
            ? '<span class="skill-icon skill-icon-sm">' . $childSvg . '</span>'
            : '<span class="skill-icon skill-icon-sm skill-initials" aria-hidden="true">' . e(mb_strtoupper(mb_substr($child['name'], 0, 2))) . '</span>';
        $list .= '<li class="skill-child" style="--i:' . $index . '">' . $childIcon . '<span>' . e($child['name']) . '</span></li>';
    }
    return '<li class="skill skill-parent"><button type="button" class="skill-head" aria-expanded="false" aria-controls="' . $panelId . '">' . $head . '</button>'
        . '<ul class="skill-panel" id="' . $panelId . '">' . $list . '</ul></li>';
}

/** Languages as "language | level" lines. A certificate or other sub-item is "name | | parent:Language" and sits in that language's panel. */
function language_items(string $text): array {
    $items = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $parts = array_map('trim', explode('|', $line));
        $name = $parts[0];
        if ($name === '') {
            continue;
        }
        $item = ['name' => $name, 'level' => '', 'parent' => '', 'icon' => ''];
        foreach (array_slice($parts, 1) as $part) {
            if (stripos($part, 'parent:') === 0) {
                $item['parent'] = trim(substr($part, 7));
            } elseif ($part !== '' && $item['level'] === '') {
                $item['level'] = $part;
            }
        }
        $items[] = $item;
    }
    return $items;
}

/** One language card. A language with sub-items opens a panel with them, the same way as a skill group. */
function language_card_html(array $group, string $lang = 'en'): string {
    $item = $group['item'];
    // The Cambridge C1 Advanced badge shows beside the English C1 level only; the Persian page keeps its text-only level.
    $badge = ($lang === 'en' && stripos($item['level'], 'C1') !== false) ? '<img class="lang-badge" src="assets/img/lang/c1-advanced.webp" alt="" width="28" height="28" loading="lazy">' : '';
    $text = '<span class="lang-text"><span class="lang-name">' . e($item['name']) . '</span>'
        . ($item['level'] !== '' ? '<span class="lang-level">' . $badge . e($item['level']) . '</span>' : '') . '</span>';
    if (!$group['children']) {
        return '<li class="skill lang-card">' . $text . '</li>';
    }
    $panelId = 'lang-panel-' . substr(md5($item['name']), 0, 8);
    $list = '';
    foreach ($group['children'] as $index => $child) {
        $list .= '<li class="skill-child" style="--i:' . $index . '"><span>' . e($child['name']) . '</span></li>';
    }
    return '<li class="skill skill-parent lang-card"><button type="button" class="skill-head" aria-expanded="false" aria-controls="' . $panelId . '">' . $text . '</button>'
        . '<ul class="skill-panel" id="' . $panelId . '">' . $list . '</ul></li>';
}

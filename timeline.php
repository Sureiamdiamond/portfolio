<?php
declare(strict_types=1);

/** Logo for an education or experience entry: the uploaded image, else a neutral initials tile. */
function org_tile(array $item, string $lang): string {
    $logo = trim((string) ($item['logo'] ?? ''));
    if ($logo !== '') {
        return '<img class="tl-logo" src="' . e($logo) . '" alt="" width="56" height="56" loading="lazy">';
    }
    $name = trim(loc($item, 'org', $lang)) !== '' ? loc($item, 'org', $lang) : loc($item, 'title', $lang);
    $words = array_values(array_filter(preg_split('/\s+/u', trim($name)) ?: [], 'strlen'));
    $initials = mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2))));
    return '<span class="tl-logo tl-initials" style="--hue:' . (crc32($name) % 360) . '" aria-hidden="true">' . e($initials !== '' ? $initials : '•') . '</span>';
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

/** Skills as "name | icon key" lines. Entries without a brand icon get initials instead of a made-up logo. */
function skill_items(string $text): array {
    $items = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        [$name, $icon] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
        if ($name !== '') {
            $items[] = ['name' => $name, 'icon' => strtolower($icon)];
        }
    }
    return $items;
}

/** Languages as "language | level" lines. */
function language_items(string $text): array {
    $items = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        [$name, $level] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
        if ($name !== '') {
            $items[] = ['name' => $name, 'level' => $level];
        }
    }
    return $items;
}

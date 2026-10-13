<?php
declare(strict_types=1);

/**
 * Site theme: colour presets or custom colours, corner radius, section spacing, motion and the default mode.
 * Published settings live in the profile table; a draft lives in data/theme_draft.json until it is published.
 */

const THEME_PRESETS = [
    'electric' => ['label' => 'Electric Blue (current)', 'light' => '#0052FF', 'accent' => '#4D7CFF', 'dark' => '#7DA2FF'],
    'ocean' => ['label' => 'Ocean', 'light' => '#0369A1', 'accent' => '#0EA5E9', 'dark' => '#7DD3FC'],
    'emerald' => ['label' => 'Emerald', 'light' => '#047857', 'accent' => '#10B981', 'dark' => '#6EE7B7'],
    'sunset' => ['label' => 'Sunset', 'light' => '#C2410C', 'accent' => '#F97316', 'dark' => '#FDBA74'],
    'violet' => ['label' => 'Violet', 'light' => '#6D28D9', 'accent' => '#A78BFA', 'dark' => '#C4B5FD'],
    'rose' => ['label' => 'Rose', 'light' => '#BE123C', 'accent' => '#F43F5E', 'dark' => '#FDA4AF'],
    'mono' => ['label' => 'Mono', 'light' => '#0F172A', 'accent' => '#475569', 'dark' => '#E2E8F0'],
    'custom' => ['label' => 'Custom colours', 'light' => '#0052FF', 'accent' => '#4D7CFF', 'dark' => '#7DA2FF'],
];
const THEME_RADII = ['sharp' => '6px', 'soft' => '18px', 'round' => '28px'];
const THEME_DENSITY = ['compact' => '60px', 'comfortable' => '88px', 'airy' => '120px'];
const THEME_MODES = ['system', 'light', 'dark'];
const THEME_BG = ['#FAFAFA', '#0B1222'];
/** Hero photo card background presets: type, three colours, angle and centre of the radial gradient. */
const HERO_PRESETS = [
    'blue' => ['label' => 'Electric Blue (current)', 'type' => 'radial', 'c1' => '#4D7CFF', 'c2' => '#0052FF', 'c3' => '#0A2A8C', 'angle' => 135, 'x' => 50, 'y' => 42],
    'orange-purple' => ['label' => 'Orange and purple', 'type' => 'linear', 'c1' => '#FB923C', 'c2' => '#C026D3', 'c3' => '#4C1D95', 'angle' => 145, 'x' => 50, 'y' => 50],
    'emerald' => ['label' => 'Emerald', 'type' => 'radial', 'c1' => '#34D399', 'c2' => '#047857', 'c3' => '#064E3B', 'angle' => 135, 'x' => 50, 'y' => 42],
    'sunset' => ['label' => 'Sunset', 'type' => 'linear', 'c1' => '#FDBA74', 'c2' => '#F97316', 'c3' => '#BE123C', 'angle' => 160, 'x' => 50, 'y' => 50],
    'mono' => ['label' => 'Mono', 'type' => 'linear', 'c1' => '#475569', 'c2' => '#1E293B', 'c3' => '#0F172A', 'angle' => 180, 'x' => 50, 'y' => 50],
];
const HERO_TYPES = ['radial', 'linear', 'mesh', 'solid'];
const HERO_PATTERNS = ['none', 'dots', 'grid', 'noise'];

function theme_defaults(): array {
    return ['theme_preset' => 'electric', 'theme_primary' => '#0052FF', 'theme_accent' => '#4D7CFF',
        'theme_radius' => 'soft', 'theme_density' => 'comfortable', 'theme_motion' => 'on', 'theme_mode' => 'system',
        'theme_bg_preset' => 'blue', 'theme_bg_type' => 'radial', 'theme_bg_c1' => '#4D7CFF', 'theme_bg_c2' => '#0052FF',
        'theme_bg_c3' => '#0A2A8C', 'theme_bg_angle' => '135', 'theme_bg_x' => '50', 'theme_bg_y' => '42',
        'theme_bg_pattern' => 'none', 'theme_bg_image' => ''];
}

/** Keeps only valid values; anything else falls back to the default. */
function theme_clean(array $in): array {
    $d = theme_defaults();
    $hex = static fn($v, $fallback) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtoupper($v) : $fallback;
    return [
        'theme_preset' => array_key_exists($in['theme_preset'] ?? '', THEME_PRESETS) ? (string) $in['theme_preset'] : $d['theme_preset'],
        'theme_primary' => $hex($in['theme_primary'] ?? null, $d['theme_primary']),
        'theme_accent' => $hex($in['theme_accent'] ?? null, $d['theme_accent']),
        'theme_radius' => array_key_exists($in['theme_radius'] ?? '', THEME_RADII) ? (string) $in['theme_radius'] : $d['theme_radius'],
        'theme_density' => array_key_exists($in['theme_density'] ?? '', THEME_DENSITY) ? (string) $in['theme_density'] : $d['theme_density'],
        'theme_motion' => ($in['theme_motion'] ?? '') === 'on' ? 'on' : 'off',
        'theme_mode' => in_array($in['theme_mode'] ?? '', THEME_MODES, true) ? (string) $in['theme_mode'] : $d['theme_mode'],
        'theme_bg_preset' => array_key_exists($in['theme_bg_preset'] ?? '', HERO_PRESETS) || ($in['theme_bg_preset'] ?? '') === 'custom' ? (string) $in['theme_bg_preset'] : $d['theme_bg_preset'],
        'theme_bg_type' => in_array($in['theme_bg_type'] ?? '', HERO_TYPES, true) ? (string) $in['theme_bg_type'] : $d['theme_bg_type'],
        'theme_bg_c1' => $hex($in['theme_bg_c1'] ?? null, $d['theme_bg_c1']),
        'theme_bg_c2' => $hex($in['theme_bg_c2'] ?? null, $d['theme_bg_c2']),
        'theme_bg_c3' => $hex($in['theme_bg_c3'] ?? null, $d['theme_bg_c3']),
        'theme_bg_angle' => (string) max(0, min(360, (int) ($in['theme_bg_angle'] ?? $d['theme_bg_angle']))),
        'theme_bg_x' => (string) max(0, min(100, (int) ($in['theme_bg_x'] ?? $d['theme_bg_x']))),
        'theme_bg_y' => (string) max(0, min(100, (int) ($in['theme_bg_y'] ?? $d['theme_bg_y']))),
        'theme_bg_pattern' => in_array($in['theme_bg_pattern'] ?? '', HERO_PATTERNS, true) ? (string) $in['theme_bg_pattern'] : $d['theme_bg_pattern'],
        'theme_bg_image' => is_string($in['theme_bg_image'] ?? null) && preg_match('~^uploads/[A-Za-z0-9_./-]+$~', $in['theme_bg_image']) && !str_contains($in['theme_bg_image'], '..') ? $in['theme_bg_image'] : '',
    ];
}

/** The colours in use: a preset's, or the custom pair the editor chose. */
function theme_colors(array $s): array {
    if ($s['theme_preset'] === 'custom') {
        return ['light' => $s['theme_primary'], 'accent' => $s['theme_accent'], 'dark' => theme_lighten($s['theme_accent'], 0.3)];
    }
    $p = THEME_PRESETS[$s['theme_preset']];
    return ['light' => $p['light'], 'accent' => $p['accent'], 'dark' => $p['dark']];
}

function theme_lighten(string $hex, float $amount): string {
    [$r, $g, $b] = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    $mix = static fn(int $c) => (int) round($c + (255 - $c) * $amount);
    return sprintf('#%02X%02X%02X', $mix($r), $mix($g), $mix($b));
}

/** WCAG relative luminance and contrast ratio. */
function theme_luminance(string $hex): float {
    $channel = static function (int $c): float {
        $c /= 255;
        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * $channel(hexdec(substr($hex, 1, 2))) + 0.7152 * $channel(hexdec(substr($hex, 3, 2))) + 0.0722 * $channel(hexdec(substr($hex, 5, 2)));
}

function theme_contrast(string $a, string $b): float {
    $la = theme_luminance($a);
    $lb = theme_luminance($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/** Warnings for text that would be hard to read (below 4.5:1). */
function theme_warnings(array $s): array {
    $c = theme_colors($s);
    $checks = [
        ['White button text on the primary colour', '#FFFFFF', $c['light']],
        ['Primary colour as text on the light background', $c['light'], THEME_BG[0]],
        ['Dark-mode accent as text on the dark background', $c['dark'], THEME_BG[1]],
    ];
    $warnings = [];
    foreach ($checks as [$label, $fg, $bg]) {
        $ratio = theme_contrast($fg, $bg);
        if ($ratio < 4.5) {
            $warnings[] = sprintf('%s is %.1f:1; it should be at least 4.5:1.', $label, $ratio);
        }
    }
    return $warnings;
}

/** The hero photo card background as one CSS background value (overlay layers first, base last). */
function theme_hero_background(array $s, string $prefix = ''): string {
    [$c1, $c2, $c3] = [$s['theme_bg_c1'], $s['theme_bg_c2'], $s['theme_bg_c3']];
    $x = (int) $s['theme_bg_x'];
    $y = (int) $s['theme_bg_y'];
    $base = match ($s['theme_bg_type']) {
        'linear' => sprintf('linear-gradient(%ddeg, %s 0%%, %s 55%%, %s 100%%)', (int) $s['theme_bg_angle'], $c1, $c2, $c3),
        'mesh' => sprintf('radial-gradient(40%% 40%% at 20%% 20%%, %s 0%%, transparent 60%%), radial-gradient(45%% 45%% at 80%% 30%%, %s 0%%, transparent 60%%), radial-gradient(50%% 50%% at 50%% 90%%, %s 0%%, transparent 65%%), %s', $c1, $c3, $c2, $c2),
        'solid' => $c1,
        default => sprintf('radial-gradient(60%% 55%% at %d%% %d%%, %s 0%%, %s 58%%, %s 100%%)', $x, $y, $c1, $c2, $c3),
    };
    $layers = [];
    $layers[] = match ($s['theme_bg_pattern']) {
        'dots' => 'radial-gradient(rgba(255,255,255,.18) 1px, transparent 1.5px) 0 0 / 16px 16px',
        'grid' => 'linear-gradient(rgba(255,255,255,.12) 1px, transparent 1px) 0 0 / 28px 28px, linear-gradient(90deg, rgba(255,255,255,.12) 1px, transparent 1px) 0 0 / 28px 28px',
        'noise' => "url(\"data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='160' height='160'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.8' numOctaves='2' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 1  0 0 0 0 1  0 0 0 0 1  0 0 0 .14 0'/></filter><rect width='100%' height='100%' filter='url(%23n)'/></svg>\") 0 0 / 160px 160px",
        default => null,
    };
    if ($s['theme_bg_image'] !== '') {
        $layers[] = 'url("' . $prefix . $s['theme_bg_image'] . '") center / cover no-repeat';
    }
    $layers[] = $base;
    return implode(', ', array_filter($layers));
}

/** The CSS that applies the settings. It is written after the stylesheet, so it overrides the defaults. */
function theme_css(array $s): string {
    $c = theme_colors($s);
    $gradient = sprintf('linear-gradient(135deg, %s 0%%, %s 100%%)', $c['light'], $c['accent']);
    $radius = THEME_RADII[$s['theme_radius']];
    $pad = THEME_DENSITY[$s['theme_density']];
    $hero = theme_hero_background($s);
    return ":root { --accent: {$c['light']}; --accent-2: {$c['accent']}; --gradient: {$gradient}; --radius: {$radius}; --block-pad: {$pad}; --inv-accent: {$c['dark']}; --hero-bg: {$hero}; }\n"
        . "@media (prefers-color-scheme: dark) { :root:not([data-theme=\"light\"]) { --accent: {$c['dark']}; --accent-2: {$c['accent']}; --inv-accent: {$c['dark']}; } }\n"
        . ":root[data-theme=\"dark\"] { --accent: {$c['dark']}; --accent-2: {$c['accent']}; --inv-accent: {$c['dark']}; }\n";
}

/** Published settings, with defaults for anything not saved yet. */
function theme_published(PDO $pdo): array {
    $rows = $pdo->query("SELECT k, v FROM profile WHERE k LIKE 'theme_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    return theme_clean(array_merge(theme_defaults(), $rows ?: []));
}

const THEME_DRAFT_FILE = DATA_DIR . '/theme_draft.json';

/** The current draft, or null. A draft expires after two hours. */
function theme_draft(): ?array {
    if (!is_file(THEME_DRAFT_FILE)) {
        return null;
    }
    $data = json_decode((string) file_get_contents(THEME_DRAFT_FILE), true);
    if (!is_array($data) || ($data['created'] ?? 0) < time() - 7200) {
        return null;
    }
    return $data;
}

/** The draft's settings when the token in the preview link matches it. */
function theme_draft_for(string $token): ?array {
    $draft = theme_draft();
    if ($draft === null || !preg_match('/^[a-f0-9]{32}$/', $token) || !hash_equals((string) ($draft['token'] ?? ''), $token)) {
        return null;
    }
    return theme_clean($draft['settings'] ?? []);
}

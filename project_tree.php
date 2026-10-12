<?php
declare(strict_types=1);

// Default Flutter project layout shown for every project. Empty directories are part of the layout.
const PROJECT_DIRS = ['android', 'ios', 'assets', 'assets/images', 'assets/fonts', 'lib', 'lib/models', 'lib/screens', 'lib/widgets', 'lib/services', 'lib/utils', 'test'];

const ICON_FOLDER = '<svg class="ico" viewBox="0 0 16 16" aria-hidden="true"><path d="M1.5 4.2a1 1 0 0 1 1-1h3.1l1.3 1.5h6.6a1 1 0 0 1 1 1v6.6a1 1 0 0 1-1 1h-11a1 1 0 0 1-1-1z" fill="currentColor"/></svg>';

// Dart keywords used for highlighting. Plain text, so no regex escaping is needed here.
const DART_KEYWORDS = 'import|export|library|part|class|extends|implements|with|mixin|enum|extension|typedef|void|var|final|const|late|static|return|if|else|for|while|do|switch|case|break|continue|default|try|catch|finally|throw|new|this|super|null|true|false|async|await|yield|abstract|required|override|is|as|in';

/** Project folder name: lowercase snake_case. Falls back to project_<id> when the title has no latin letters. */
function project_slug(array $project): string {
    $base = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', (string) $project['title']), '_'));
    return $base !== '' ? $base : 'project_' . (int) $project['id'];
}

function lang_of(string $path): string {
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'dart' => 'dart',
        'yaml', 'yml' => 'yaml',
        'md' => 'markdown',
        default => 'text',
    };
}

/** Generated overview files, built only from the project's stored fields. Not the real source code. */
function project_generated_files(array $p, string $lang, string $slug): array {
    $title = loc($p, 'title', $lang);
    $summary = loc($p, 'summary', $lang);
    $tags = array_values(array_filter(array_map('trim', explode(',', (string) $p['tags']))));
    $stack = $tags ? "\n## Tech stack\n" . implode("\n", array_map(fn($t) => "- $t", $tags)) . "\n" : '';
    $links = !empty($p['url']) ? "\n## Links\n- {$p['url']}\n" : '';
    $dartTitle = str_replace("'", "\\'", $title);
    $techComments = $tags ? implode("\n", array_map(fn($t) => "  # tech: $t", $tags)) . "\n" : '';

    return [
        'README.md' => "# $title\n\n$summary\n$stack$links",
        'pubspec.yaml' => "# Generated overview from this project's stored data.\nname: $slug\ndescription: \"" . str_replace('"', "'", $title) . "\"\npublish_to: none\n\ndependencies:\n  flutter:\n    sdk: flutter\n$techComments",
        'lib/main.dart' => "// " . t('private_code', $lang) . "\n// " . t('private_code_more', $lang) . "\n",
        'analysis_options.yaml' => "# Generated overview: standard Flutter lint setup.\ninclude: package:flutter_lints/flutter.yaml\n",
        '.gitignore' => "# Generated overview\n.dart_tool/\nbuild/\n.flutter-plugins\n.flutter-plugins-dependencies\n",
        'links.md' => "# Links\n\n" . (!empty($p['url']) ? "- {$p['url']}\n" : "No links yet.\n"),
    ];
}

/** All entries of one project: text files (generated, overridden by admin content) and screenshots. */
function project_entries(PDO $pdo, array $p, string $lang, string $slug): array {
    $files = [];
    foreach (project_generated_files($p, $lang, $slug) as $path => $content) {
        $files[$path] = ['kind' => 'text', 'lang' => lang_of($path), 'content' => $content, 'notice' => $path === 'lib/main.dart'];
    }
    $stmt = $pdo->prepare('SELECT path, content FROM project_files WHERE project_id = ? ORDER BY path');
    $stmt->execute([(int) $p['id']]);
    foreach ($stmt as $row) {
        $files[$row['path']] = ['kind' => 'text', 'lang' => lang_of($row['path']), 'content' => (string) $row['content']];
    }
    $stmt = $pdo->prepare('SELECT path FROM project_media WHERE project_id = ? ORDER BY sort, id');
    $stmt->execute([(int) $p['id']]);
    foreach ($stmt as $row) {
        $files['assets/images/' . basename($row['path'])] = ['kind' => 'image', 'lang' => 'image', 'src' => $row['path']];
    }
    return $files;
}

/** Highlights one line of code. Rules are simple and per language; this is display only. */
function highlight_line(string $line, string $lang): string {
    $pattern = match ($lang) {
        'dart' => '~(//[^\n]*|/\*.*?\*/|"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\b\d+(?:\.\d+)?\b|\b(?:' . DART_KEYWORDS . ')\b)~u',
        'yaml' => '~(#.*|"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|^\s*[A-Za-z_][\w.-]*(?=\s*:)|\b(?:true|false|null)\b|\b\d+(?:\.\d+)?\b)~u',
        'markdown' => '~(^\#{1,6}.*|`[^`]*`|\*\*[^*]+\*\*)~u',
        default => null,
    };
    if ($pattern === null) {
        return e($line);
    }
    $parts = preg_split($pattern, $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$line];
    $html = '';
    foreach ($parts as $i => $part) {
        if ($i % 2 === 0) {
            $html .= e($part);
            continue;
        }
        if ($part === '') {
            continue;
        }
        $class = match (true) {
            $lang === 'markdown' && $part[0] === '#' => 'hd',
            $part[0] === '#' || str_starts_with($part, '//') || str_starts_with($part, '/*') => 'com',
            $part[0] === '"' || $part[0] === "'" => 'str',
            $part[0] === '`' || str_starts_with($part, '**') => 'hd',
            is_numeric($part[0]) => 'num',
            in_array(trim($part), ['true', 'false', 'null'], true) && $lang === 'yaml' => 'kw',
            $lang === 'yaml' => 'key',
            default => 'kw',
        };
        $html .= '<span class="' . $class . '">' . e($part) . '</span>';
    }
    return $html;
}

/** Renders the source of a text file as numbered lines. */
function render_code(string $content, string $lang): string {
    $lines = explode("\n", rtrim($content, "\n"));
    $out = '';
    foreach ($lines as $line) {
        $out .= '<span class="l">' . ($line === '' ? '&nbsp;' : highlight_line($line, $lang)) . '</span>' . "\n";
    }
    return '<pre class="code">' . $out . '</pre>';
}

/** Renders the Project tree for one project. Writes each file's viewer source into $sources. */
function render_project_tree(array $project, PDO $pdo, string $lang, array &$sources): string {
    $slug = project_slug($project);
    $files = project_entries($pdo, $project, $lang, $slug);
    $dirs = array_fill_keys(PROJECT_DIRS, true);
    foreach (array_keys($files) as $path) {
        $dir = dirname($path);
        while ($dir !== '.' && $dir !== '') {
            $dirs[$dir] = true;
            $dir = dirname($dir);
        }
    }
    foreach ($files as $path => $entry) {
        $id = 's' . substr(md5($project['id'] . ':' . $path), 0, 10);
        if ($entry['kind'] === 'image') {
            $sources[] = '<div class="pt-src" id="' . $id . '" hidden><figure class="pt-image"><img src="' . e($entry['src']) . '" alt="' . e(basename($path)) . '"></figure></div>';
        } else {
            $notice = !empty($entry['notice']) ? '<p class="pt-notice"><a class="btn btn-ghost" href="#contact">' . e(t('contact_me', $lang)) . '</a></p>' : '';
            $sources[] = '<div class="pt-src" id="' . $id . '" data-lang="' . e($entry['lang']) . '" hidden>' . render_code($entry['content'], $entry['lang']) . $notice . '</div>';
        }
        $files[$path]['id'] = $id;
    }
    $tree = '<li class="it-project" data-kind="' . e($project['kind']) . '"><details open><summary class="it dir"><span class="chev" aria-hidden="true"></span>'
        . ICON_FOLDER . '<span class="nm">' . e($slug) . '</span></summary><ul class="kids">'
        . render_dir_level(array_keys($dirs), $files, '.', $slug)
        . '</ul></details></li>';
    return $tree;
}

/** One directory level: folders first, then files, both alphabetical (as the IDE shows them). */
function render_dir_level(array $dirs, array $files, string $parent, string $slug): string {
    $html = '';
    $childDirs = array_values(array_filter($dirs, fn($d) => dirname($d) === $parent));
    usort($childDirs, 'strcasecmp');
    foreach ($childDirs as $dir) {
        $html .= '<li><details open><summary class="it dir"><span class="chev" aria-hidden="true"></span>' . ICON_FOLDER
            . '<span class="nm">' . e(basename($dir)) . '</span></summary><ul class="kids">'
            . render_dir_level($dirs, $files, $dir, $slug) . '</ul></details></li>';
    }
    $childFiles = array_keys(array_filter($files, fn($path) => dirname($path) === $parent, ARRAY_FILTER_USE_KEY));
    usort($childFiles, 'strcasecmp');
    foreach ($childFiles as $path) {
        $entry = $files[$path];
        $badge = match ($entry['lang']) {
            'dart' => ['D', 'ic-dart'],
            'yaml' => ['Y', 'ic-yaml'],
            'markdown' => ['M', 'ic-md'],
            'image' => ['IMG', 'ic-img'],
            default => ['T', 'ic-txt'],
        };
        $html .= '<li><a class="it file" href="#' . $entry['id'] . '" data-src="' . $entry['id'] . '"'
            . ' data-path="' . e($slug . '/' . $path) . '" data-lang="' . e($entry['lang']) . '">'
            . '<span class="chev-space" aria-hidden="true"></span><span class="ic ' . $badge[1] . '" aria-hidden="true">' . $badge[0] . '</span>'
            . '<span class="nm">' . e(basename($path)) . '</span></a></li>';
    }
    return $html;
}

/** Admin path rule: relative, no traversal, only safe characters. */
function valid_project_path(string $path): bool {
    return (bool) preg_match('~^[A-Za-z0-9_][A-Za-z0-9_./-]{0,200}$~', $path)
        && !str_contains($path, '..') && !str_contains($path, '//') && !str_ends_with($path, '/')
        && !str_starts_with($path, 'assets/images/');
}

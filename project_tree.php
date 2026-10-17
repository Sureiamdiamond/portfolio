<?php
declare(strict_types=1);

// One Flutter-style app holds every project: its folders are the layout, and projects live in lib/projects/.
// Empty directories are part of the layout.
const ROOT_NAME = 'portfolio';
const ROOT_DIRS = ['android', 'ios', 'assets', 'assets/images', 'assets/fonts', 'lib', 'lib/projects', 'test'];
const PROJECTS_DIR = 'lib/projects';

const ICON_FOLDER = '<svg class="ico" viewBox="0 0 16 16" aria-hidden="true"><path d="M1.5 4.2a1 1 0 0 1 1-1h3.1l1.3 1.5h6.6a1 1 0 0 1 1 1v6.6a1 1 0 0 1-1 1h-11a1 1 0 0 1-1-1z" fill="currentColor"/></svg>';

// Dart keywords used for highlighting. Plain text, so no regex escaping is needed here.
const DART_KEYWORDS = 'import|export|library|part|class|extends|implements|with|mixin|enum|extension|typedef|void|var|final|const|late|static|return|if|else|for|while|do|switch|case|break|continue|default|try|catch|finally|throw|new|this|super|null|true|false|async|await|yield|abstract|required|override|is|as|in';

/** Project folder name: lowercase snake_case. Falls back to project_<id> when the title has no latin letters. */
function project_slug(array $project): string {
    $base = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', (string) $project['title']), '_'));
    return $base !== '' ? $base : 'project_' . (int) $project['id'];
}

function lang_of(string $path): string {
    if (basename($path) === '.gitignore') {
        return 'gitignore';
    }
    if (basename($path) === 'Podfile') {
        return 'ruby';
    }
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'dart' => 'dart',
        'yaml', 'yml' => 'yaml',
        'md' => 'markdown',
        'json' => 'json',
        'gradle' => 'gradle',
        'xml' => 'xml',
        'plist' => 'plist',
        'properties' => 'properties',
        'svg', 'png', 'jpg', 'jpeg', 'webp' => 'image',
        'woff2', 'woff', 'ttf', 'otf' => 'font',
        default => 'text',
    };
}

/**
 * Generated files of one project, built only from its stored fields.
 * main.dart is a labeled stub, not the real source code; the real file replaces it when pasted in /admin.
 */
function project_generated_files(array $p, string $lang, string $slug): array {
    // Headings and labels follow the page language; the description is the project's text in that language
    $fa = $lang === 'fa';
    $h = $fa
        ? ['kind' => 'نوع', 'stack' => 'فناوری‌ها', 'links' => 'لینک‌ها', 'nolinks' => 'هنوز لینکی ثبت نشده است.', 'nodesc' => 'توضیحی ثبت نشده است.',
            'work' => 'کاری', 'academic' => 'دانشگاهی']
        : ['kind' => 'Kind', 'stack' => 'Tech stack', 'links' => 'Links', 'nolinks' => 'No links yet.', 'nodesc' => 'No description yet.',
            'work' => 'Work', 'academic' => 'Academic'];
    $title = loc($p, 'title', $lang);
    $summary = trim(loc($p, 'summary', $lang));
    $tags = array_values(array_filter(array_map('trim', explode(',', (string) $p['tags']))));
    $links = project_links($p);
    $kind = $h[$p['kind']] ?? $p['kind'];

    $readme = "# $title\n\n";
    $readme .= "{$h['kind']}: $kind\n\n";
    $readme .= ($summary !== '' ? "$summary\n" : "{$h['nodesc']}\n");
    if ($tags) {
        $readme .= "\n## {$h['stack']}\n\n" . implode("\n", array_map(fn($t) => "- $t", $tags)) . "\n";
    }
    if ($links) {
        $readme .= "\n## {$h['links']}\n\n" . implode("\n", array_map(fn($l) => "- {$l[0]}: {$l[1]}", $links)) . "\n";
    }

    $linksFile = "# {$h['links']}\n\n" . ($links
        ? implode("\n", array_map(fn($l) => "- {$l[0]}: {$l[1]}", $links)) . "\n"
        : "{$h['nolinks']}\n");

    $dartTitle = str_replace(['\\', "'"], ['\\\\', "\\'"], $title);
    $main = "// Generated stub from this project's stored data. Not the real source code.\n"
        . "// " . t('private_code', $lang) . "\n// " . t('private_code_more', $lang) . "\n"
        . "import 'package:flutter/material.dart';\n\n"
        . "void main() {\n"
        . "  runApp(const MaterialApp(\n"
        . "    title: '$dartTitle',\n"
        . "    home: Scaffold(\n"
        . "      body: Center(child: Text('$dartTitle')),\n"
        . "    ),\n"
        . "  ));\n"
        . "}\n";

    return [
        'README.md' => $readme,
        'links.md' => $linksFile,
        'main.dart' => $main,
    ];
}

/** Project links as [label, url] pairs, only for the ones that are set. */
function project_links(array $p): array {
    $pairs = [
        ['GitHub', $p['github_url'] ?? ''],
        ['App Store', $p['appstore_url'] ?? ''],
        ['Google Play', $p['playstore_url'] ?? ''],
        ['Bazaar', $p['bazaar_url'] ?? ''],
        ['Website', clean_link((string) ($p['url'] ?? ''))],
    ];
    foreach (json_decode((string) ($p['extra_links'] ?? ''), true) ?: [] as $link) {
        if (is_array($link)) {
            $pairs[] = [trim((string) ($link['label'] ?? 'Link')), clean_link((string) ($link['url'] ?? ''))];
        }
    }
    return array_values(array_filter($pairs, fn($l) => trim((string) $l[1]) !== ''));
}

/** All entries of one project: text files (generated, overridden by admin content) and screenshots. */
function project_entries(PDO $pdo, array $p, string $lang, string $slug): array {
    $files = [];
    foreach (project_generated_files($p, $lang, $slug) as $path => $content) {
        $files[$path] = ['kind' => 'text', 'lang' => lang_of($path), 'content' => $content, 'notice' => $path === 'main.dart'];
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

/** Makes http(s) URLs in already-escaped, highlighted HTML clickable. Trailing punctuation stays outside the link. */
function link_urls(string $html): string {
    return preg_replace_callback('~https?://[^\s<"\'&]+(?:&amp;[^\s<"\'&]+)*~', function (array $m): string {
        $url = $m[0];
        $tail = '';
        if (preg_match('~[.,;:!?)\]]+$~', $url, $t)) {
            $tail = $t[0];
            $url = substr($url, 0, -strlen($tail));
        }
        return '<a href="' . $url . '" target="_blank" rel="noopener">' . $url . '</a>' . $tail;
    }, $html);
}

/** Renders the source of a text file as numbered lines. */
function render_code(string $content, string $lang): string {
    $lines = explode("\n", rtrim($content, "\n"));
    $out = '';
    foreach ($lines as $line) {
        $out .= '<span class="l">' . ($line === '' ? '&nbsp;' : link_urls(highlight_line($line, $lang))) . '</span>' . "\n";
    }
    return '<pre class="code">' . $out . '</pre>';
}

// Platform files shown in the IDE's android/ and ios/ folders. Labeled as display-only; they are not the real build configuration.
const PLATFORM_NOTE = "Generated for display on this portfolio. Not the real build configuration.";
const PLATFORM_FILES = [
    'android/app/build.gradle' => "// " . PLATFORM_NOTE . "\nplugins {\n    id \"com.android.application\"\n    id \"kotlin-android\"\n    id \"dev.flutter.flutter-gradle-plugin\"\n}\n\nandroid {\n    namespace \"com.parsabanitaba.portfolio\"\n    compileSdk 35\n\n    defaultConfig {\n        applicationId \"com.parsabanitaba.portfolio\"\n        minSdk 21\n        targetSdk 35\n        versionCode 1\n        versionName \"1.0.0\"\n    }\n}\n",
    'android/app/src/main/AndroidManifest.xml' => "<!-- " . PLATFORM_NOTE . " -->\n<manifest xmlns:android=\"http://schemas.android.com/apk/res/android\">\n    <uses-permission android:name=\"android.permission.INTERNET\" />\n    <application\n        android:label=\"Parsa Banitaba\"\n        android:icon=\"@mipmap/ic_launcher\" />\n</manifest>\n",
    'android/gradle.properties' => "# " . PLATFORM_NOTE . "\norg.gradle.jvmargs=-Xmx4G\nandroid.useAndroidX=true\nandroid.enableJetifier=true\n",
    'ios/Runner/Info.plist' => "<!-- " . PLATFORM_NOTE . " -->\n<plist version=\"1.0\">\n<dict>\n    <key>CFBundleDisplayName</key>\n    <string>Parsa Banitaba</string>\n    <key>CFBundleIdentifier</key>\n    <string>com.parsabanitaba.portfolio</string>\n    <key>CFBundleShortVersionString</key>\n    <string>1.0.0</string>\n    <key>CFBundleVersion</key>\n    <string>1</string>\n</dict>\n</plist>\n",
    'ios/Podfile' => "# " . PLATFORM_NOTE . "\nplatform :ios, '13.0'\ntarget 'Runner' do\n  use_frameworks!\n  use_modular_headers!\n  flutter_install_all_ios_pods File.dirname(File.realpath(__FILE__))\nend\n",
];

/** The root lib/main.dart: a small animated welcome screen, not the source of any project. */
const ROOT_MAIN_DART = <<<'DART'
import 'package:flutter/material.dart';

void main() {
  runApp(const PortfolioApp());
}

class PortfolioApp extends StatelessWidget {
  const PortfolioApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      theme: ThemeData(colorSchemeSeed: const Color(0xFF0052FF), useMaterial3: true),
      home: const HelloPage(),
    );
  }
}

class HelloPage extends StatefulWidget {
  const HelloPage({super.key});

  @override
  State<HelloPage> createState() => _HelloPageState();
}

class _HelloPageState extends State<HelloPage> with SingleTickerProviderStateMixin {
  late final AnimationController _pulse = AnimationController(
    vsync: this,
    duration: const Duration(seconds: 2),
  )..repeat(reverse: true);

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Scaffold(
      body: DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [scheme.primary, scheme.tertiary],
          ),
        ),
        child: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              ScaleTransition(
                scale: Tween<double>(begin: 0.95, end: 1.05).animate(
                  CurvedAnimation(parent: _pulse, curve: Curves.easeInOut),
                ),
                child: const Icon(Icons.auto_awesome, size: 72, color: Colors.white),
              ),
              const SizedBox(height: 24),
              const Text(
                'Hi, I am Parsa',
                style: TextStyle(color: Colors.white, fontSize: 32, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 8),
              const Text(
                'Flutter developer · AI · always learning',
                style: TextStyle(color: Colors.white70, fontSize: 16),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
DART;

/** Root app files: a generated overview of the layout. Not real source code. */
/** The fonts shown in the IDE's assets folder, as they appear in the pubspec overview. */
const ROOT_FONTS_YAML = "    - family: Calistoga\n      fonts:\n        - asset: assets/fonts/calistoga-latin-400-normal.woff2\n"
    . "    - family: Inter\n      fonts:\n        - asset: assets/fonts/inter-latin-600-normal.woff2\n          weight: 600\n"
    . "    - family: JetBrains Mono\n      fonts:\n        - asset: assets/fonts/jetbrains-mono-latin-500-normal.woff2\n          weight: 500\n"
    . "    - family: Vazirmatn\n      fonts:\n        - asset: assets/fonts/vazirmatn-arabic-400-normal.woff2\n";

function root_entries(string $lang): array {
    return [
        'README.md' => ['kind' => 'text', 'content' => "# " . ROOT_NAME . "\n\n" . ($lang === 'fa' ? "پروژه‌ها در lib/projects/ قرار دارند، هر کدام در پوشه‌ی جدا.\n" : "Projects live in lib/projects/, one folder each.\n"), 'notice' => false],
        'pubspec.yaml' => ['kind' => 'text', 'content' => "# Generated overview of the layout.\nname: " . ROOT_NAME . "\npublish_to: none\n\nflutter:\n  assets:\n    - assets/images/\n  fonts:\n" . ROOT_FONTS_YAML, 'notice' => false],
        'assets/images/flutter_logo.svg' => ['kind' => 'image', 'lang' => 'image', 'src' => 'assets/img/ide/flutter_logo.png'],
        'assets/images/dash.svg' => ['kind' => 'image', 'lang' => 'image', 'src' => 'assets/img/ide/dash.svg'],
        'assets/fonts/calistoga-latin-400-normal.woff2' => ['kind' => 'binary', 'lang' => 'font', 'src' => 'assets/fonts/calistoga-latin-400-normal.woff2'],
        'assets/fonts/inter-latin-600-normal.woff2' => ['kind' => 'binary', 'lang' => 'font', 'src' => 'assets/fonts/inter-latin-600-normal.woff2'],
        'assets/fonts/jetbrains-mono-latin-500-normal.woff2' => ['kind' => 'binary', 'lang' => 'font', 'src' => 'assets/fonts/jetbrains-mono-latin-500-normal.woff2'],
        'assets/fonts/vazirmatn-arabic-400-normal.woff2' => ['kind' => 'binary', 'lang' => 'font', 'src' => 'assets/fonts/vazirmatn-arabic-400-normal.woff2'],
        'lib/main.dart' => ['kind' => 'text', 'content' => ROOT_MAIN_DART, 'notice' => true],
        'android/app/build.gradle' => ['kind' => 'text', 'content' => PLATFORM_FILES['android/app/build.gradle'], 'notice' => false],
        'android/app/src/main/AndroidManifest.xml' => ['kind' => 'text', 'content' => PLATFORM_FILES['android/app/src/main/AndroidManifest.xml'], 'notice' => false],
        'android/gradle.properties' => ['kind' => 'text', 'content' => PLATFORM_FILES['android/gradle.properties'], 'notice' => false],
        'ios/Runner/Info.plist' => ['kind' => 'text', 'content' => PLATFORM_FILES['ios/Runner/Info.plist'], 'notice' => false],
        'ios/Podfile' => ['kind' => 'text', 'content' => PLATFORM_FILES['ios/Podfile'], 'notice' => false],
    ];
}

/**
 * Renders the whole Projects window tree: one root app, each project as a folder under lib/projects/.
 * Writes each file's viewer source into $sources.
 */
function render_project_window(array $projects, PDO $pdo, string $lang, array &$sources): string {
    $files = [];
    foreach (root_entries($lang) as $path => $entry) {
        $files[$path] = $entry + ['lang' => lang_of($path)];
    }
    $projectKinds = [];
    $projectTiles = [];
    $used = [];
    foreach ($projects as $project) {
        $slug = project_slug($project);
        if (isset($used[$slug])) {
            $slug .= '_' . (int) $project['id'];
        }
        $used[$slug] = true;
        $prefix = PROJECTS_DIR . '/' . $slug;
        $projectKinds[$prefix] = (string) $project['kind'];
        $projectTiles[$prefix] = project_tile($project, 'sm');
        foreach (project_entries($pdo, $project, $lang, $slug) as $path => $entry) {
            $files[$prefix . '/' . $path] = $entry;
        }
    }
    $dirs = array_fill_keys(ROOT_DIRS, true) + array_fill_keys(array_keys($projectKinds), true);
    foreach (array_keys($files) as $path) {
        $dir = dirname($path);
        while ($dir !== '.' && $dir !== '') {
            $dirs[$dir] = true;
            $dir = dirname($dir);
        }
    }
    foreach ($files as $path => $entry) {
        $id = 's' . substr(md5($path), 0, 10);
        if ($entry['kind'] === 'image') {
            $sources[] = '<div class="pt-src" id="' . $id . '" hidden><figure class="pt-image"><img src="' . e($entry['src']) . '" alt="' . e(basename($path)) . '"></figure></div>';
        } elseif ($entry['kind'] === 'binary') {
            $sources[] = '<div class="pt-src" id="' . $id . '" hidden><p class="pt-hint">' . e($lang === 'fa' ? 'این فایل باینری است و در این نما نمایش داده نمی‌شود.' : 'Binary file. The preview is not shown here.') . '</p><p><a href="' . e($entry['src']) . '" download>' . e(basename($path)) . '</a></p></div>';
        } else {
            $notice = !empty($entry['notice']) ? '<p class="pt-notice"><a class="btn btn-ghost" href="#contact">' . e(t('contact_me', $lang)) . '</a></p>' : '';
            $sources[] = '<div class="pt-src" id="' . $id . '" data-lang="' . e($entry['lang']) . '" hidden>' . render_code($entry['content'], $entry['lang']) . $notice . '</div>';
        }
        $files[$path]['id'] = $id;
    }
    return '<li><details open><summary class="it dir"><span class="chev" aria-hidden="true"></span>'
        . ICON_FOLDER . '<span class="nm">' . e(ROOT_NAME) . '</span></summary><ul class="kids">'
        . render_dir_level(array_keys($dirs), $files, '.', $projectKinds, $projectTiles)
        . '</ul></details></li>';
}

/** One directory level: folders first, then files, both alphabetical (as the IDE shows them). */
/**
 * The project's icon: the uploaded icon when one is set, otherwise a generated tile with its initials.
 * Each project gets its own hue, so the tiles differ from one another without any brand logo.
 */
function project_tile(array $p, string $size = ''): string {
    $cls = 'ptile' . ($size !== '' ? ' ' . $size : '');
    $icon = trim((string) ($p['icon'] ?? ''));
    if ($icon !== '') {
        return '<img class="' . $cls . ' ptile-img" src="' . e($icon) . '" alt="" width="44" height="44" loading="lazy">';
    }
    $title = trim((string) ($p['title'] ?? ''));
    $words = array_values(array_filter(preg_split('/\s+/u', $title) ?: [], 'strlen'));
    $initials = mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2))));
    return '<span class="' . $cls . '" style="--hue:' . (crc32($title) % 360) . '" aria-hidden="true">' . e($initials !== '' ? $initials : '•') . '</span>';
}

function render_dir_level(array $dirs, array $files, string $parent, array $projectKinds, array $projectTiles = []): string {
    $html = '';
    $childDirs = array_values(array_filter($dirs, fn($d) => dirname($d) === $parent));
    usort($childDirs, 'strcasecmp');
    foreach ($childDirs as $dir) {
        $isProject = isset($projectKinds[$dir]);
        // lib and lib/projects are open on first load; the project folders start collapsed.
        $html .= '<li' . ($isProject ? ' class="it-project" data-kind="' . e($projectKinds[$dir]) . '"' : '') . '><details' . (in_array($dir, ['lib', PROJECTS_DIR], true) ? ' open' : '') . '><summary class="it dir"><span class="chev" aria-hidden="true"></span>' . ($isProject && isset($projectTiles[$dir]) ? $projectTiles[$dir] : ICON_FOLDER)
            . '<span class="nm">' . e(basename($dir)) . '</span></summary><ul class="kids">'
            . render_dir_level($dirs, $files, $dir, $projectKinds, $projectTiles) . '</ul></details></li>';
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
            'gitignore' => ['⊘', 'ic-git'],
            'gradle' => ['G', 'ic-gradle'],
            'xml' => ['X', 'ic-xml'],
            'plist' => ['P', 'ic-plist'],
            'properties' => ['≡', 'ic-txt'],
            'ruby' => ['R', 'ic-ruby'],
            'json' => ['{}', 'ic-json'],
            'font' => ['Aa', 'ic-txt'],
            default => ['≡', 'ic-txt'],
        };
        $html .= '<li><a class="it file" href="#' . $entry['id'] . '" data-src="' . $entry['id'] . '"'
            . ' data-path="' . e(ROOT_NAME . '/' . $path) . '" data-lang="' . e($entry['lang']) . '">'
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

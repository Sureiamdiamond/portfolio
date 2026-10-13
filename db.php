<?php
declare(strict_types=1);

const DATA_DIR = __DIR__ . '/data';
require_once __DIR__ . '/migrations.php';
const UPLOAD_DIR = __DIR__ . '/uploads';
const MAX_UPLOAD_BYTES = 100 * 1024 * 1024;

function db(): PDO {
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }
    $pdo = new PDO('sqlite:' . DATA_DIR . '/site.db', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS profile (k TEXT PRIMARY KEY, v TEXT);
        CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, kind TEXT NOT NULL DEFAULT 'work',
            summary TEXT, tags TEXT, url TEXT, sort INTEGER NOT NULL DEFAULT 0);
        CREATE TABLE IF NOT EXISTS papers (
            id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, venue TEXT, year INTEGER,
            doi TEXT, pdf TEXT, sort INTEGER NOT NULL DEFAULT 0);
        CREATE TABLE IF NOT EXISTS presentations (
            id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, event TEXT, year INTEGER,
            pptx TEXT, pdf TEXT, sort INTEGER NOT NULL DEFAULT 0);
        CREATE TABLE IF NOT EXISTS timeline (
            id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, title TEXT NOT NULL, org TEXT,
            period TEXT, details TEXT, sort INTEGER NOT NULL DEFAULT 0);
        CREATE TABLE IF NOT EXISTS folders (
            id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER, name TEXT NOT NULL, sort INTEGER NOT NULL DEFAULT 0);
        CREATE TABLE IF NOT EXISTS files (
            id INTEGER PRIMARY KEY AUTOINCREMENT, folder_id INTEGER, name TEXT NOT NULL, path TEXT NOT NULL,
            ext TEXT NOT NULL, size INTEGER NOT NULL DEFAULT 0, preview TEXT, sort INTEGER NOT NULL DEFAULT 0);
        CREATE TABLE IF NOT EXISTS project_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, path TEXT NOT NULL, content TEXT);
        CREATE TABLE IF NOT EXISTS project_media (
            id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, path TEXT NOT NULL, sort INTEGER NOT NULL DEFAULT 0);
        SQL);
    // Backward-compatible migration: add Persian columns to existing databases.
    $persianColumns = [
        'projects' => ['title_fa', 'summary_fa', 'github_url', 'appstore_url', 'playstore_url', 'bazaar_url', 'icon'],
        'papers' => ['title_fa'],
        'presentations' => ['title_fa'],
        'timeline' => ['title_fa', 'org_fa', 'period_fa', 'details_fa', 'city', 'city_fa', 'website', 'logo', 'bullets', 'bullets_fa'],
    ];
    foreach ($persianColumns as $table => $columns) {
        $existing = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        foreach ($columns as $column) {
            if (!in_array($column, $existing, true)) {
                $pdo->exec("ALTER TABLE $table ADD COLUMN $column TEXT");
            }
        }
    }
    seed_files($pdo);
    seed_resume_projects($pdo);
    run_migrations($pdo);
    run_content_migrations($pdo);
    run_classyar_migration($pdo);
    run_contact_migration($pdo);
    run_junior_migration($pdo);
    run_name_fa_migration($pdo);
    run_cv_migration($pdo);
    run_experience_bullets_migration($pdo);
    run_skills_migration($pdo);
    run_location_languages_migration($pdo);
    run_skills_v2_migration($pdo);
    run_skills_v3_migration($pdo);
    run_skills_v4_migration($pdo);
    run_project_icons_migration($pdo);
    return $pdo;
}

/** One-time seed for the Files tree: Papers/ and Presentations/ folders, plus the existing CV. */
function seed_files(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = 'files_seeded'")->fetchColumn()) {
        return;
    }
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO folders (parent_id, name, sort) VALUES (NULL, 'Papers', 1), (NULL, 'Presentations', 2)");
    $cv = $pdo->query("SELECT v FROM profile WHERE k = 'cv'")->fetchColumn();
    if ($cv && is_file(__DIR__ . '/' . $cv)) {
        $stmt = $pdo->prepare("INSERT INTO files (folder_id, name, path, ext, size, sort) VALUES (NULL, 'CV.pdf', ?, 'pdf', ?, 0)");
        $stmt->execute([$cv, filesize(__DIR__ . '/' . $cv)]);
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('files_seeded', '1')");
    $pdo->commit();
}

/** Projects listed in the CV. Seeded once; a project with the same English title is never overwritten or duplicated. */
const RESUME_PROJECTS = [
    ['title' => 'Tobank', 'title_fa' => 'اپلیکیشن توبانک (شعبه مجازی بانک گردشگری)', 'kind' => 'work',
        'summary' => "Flutter mobile app for Tobank, the virtual branch of Tourism Bank (Bank Gardeshgari). Banking domain. Period: Farvardin 1403.",
        'summary_fa' => "اپلیکیشن موبایل توبانک، شعبه مجازی بانک گردشگری، با فلاتر. حوزه بانکی. بازه: فروردین ۱۴۰۳.",
        'tags' => 'flutter, dart', 'url' => 'https://tobank.ir', 'github_url' => '', 'appstore_url' => '', 'playstore_url' => '', 'bazaar_url' => '', 'sort' => 1],
    ['title' => 'Weather App', 'title_fa' => 'اپلیکیشن آب و هوا', 'kind' => 'academic',
        'summary' => "Weather app built with Bloc and Clean Architecture. University project. Period: Farvardin 1404.",
        'summary_fa' => "اپلیکیشن آب و هوا با Bloc و Clean Architecture. پروژه دانشگاه. بازه: فروردین ۱۴۰۴.",
        'tags' => 'flutter, dart, bloc, clean architecture', 'url' => '', 'github_url' => 'https://github.com/Sureiamdiamond/weather_app', 'appstore_url' => '', 'playstore_url' => '', 'bazaar_url' => '', 'sort' => 2],
    ['title' => 'TechBlog', 'title_fa' => 'تک بلاگ', 'kind' => 'academic',
        'summary' => "Group project for Prof. Safari, built with Flutter. Period: Tir 1401.",
        'summary_fa' => "پروژه گروهی استاد صفری با فلاتر. بازه: تیر ۱۴۰۱.",
        'tags' => 'flutter, dart', 'url' => '', 'github_url' => 'https://github.com/Sureiamdiamond/TechBlog', 'appstore_url' => '', 'playstore_url' => '', 'bazaar_url' => '', 'sort' => 3],
    ['title' => 'Classyar App', 'title_fa' => 'اپلیکیشن کلاسیار', 'kind' => 'work',
        'summary' => "Flutter app for Classyar, built for Azad Islamic University (Tehran West). Dynamic UI, state management with GetX and Bloc, data from an API and Firebase, published on Cafebazaar. Period: Mehr 1404.",
        'summary_fa' => "اپلیکیشن کلاسیار با فلاتر برای دانشگاه آزاد اسلامی تهران غرب: صفحات پویا، مدیریت وضعیت با GetX و Bloc، داده از API و Firebase، منتشر شده در کافه‌بازار. بازه: مهر ۱۴۰۴.",
        'tags' => 'flutter, dart, getx, bloc, firebase', 'url' => '', 'github_url' => '', 'appstore_url' => '', 'playstore_url' => '', 'bazaar_url' => 'https://cafebazaar.ir/app/com.classyar.app', 'sort' => 4],
    ['title' => 'Tobank SDUI', 'title_fa' => 'توبانک SDUI', 'kind' => 'work',
        'summary' => "Server-driven UI for the Tobank app: pages are rendered from JSON received from the server, using the Stac framework. Period: Farvardin 1405.",
        'summary_fa' => "رندر صفحات اپلیکیشن توبانک از روی JSON دریافتی از سرور با معماری Server-Driven UI و فریم‌ورک Stac. بازه: فروردین ۱۴۰۵.",
        'tags' => 'flutter, dart, stac, server-driven ui, json', 'url' => '', 'github_url' => '', 'appstore_url' => '', 'playstore_url' => '', 'bazaar_url' => '', 'sort' => 5],
];

/** One-time seed of the CV projects. Existing rows are left alone. */
function seed_resume_projects(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = 'resume_projects_seeded'")->fetchColumn()) {
        return;
    }
    $pdo->beginTransaction();
    $find = $pdo->prepare('SELECT 1 FROM projects WHERE title = ?');
    $insert = $pdo->prepare('INSERT INTO projects (title, title_fa, kind, summary, summary_fa, tags, url, github_url, appstore_url, playstore_url, bazaar_url, sort)
        VALUES (:title, :title_fa, :kind, :summary, :summary_fa, :tags, :url, :github_url, :appstore_url, :playstore_url, :bazaar_url, :sort)');
    foreach (RESUME_PROJECTS as $project) {
        $find->execute([$project['title']]);
        if (!$find->fetchColumn()) {
            $insert->execute($project);
        }
    }
    $pdo->exec("INSERT INTO profile (k, v) VALUES ('resume_projects_seeded', '1')");
    $pdo->commit();
}

/** Extension => expected file signature (magic bytes). */
const FILE_TYPES = [
    'pdf' => '%PDF-', 'pptx' => 'PK', 'docx' => 'PK', 'zip' => 'PK',
    'png' => "\x89PNG", 'jpg' => "\xFF\xD8\xFF", 'jpeg' => "\xFF\xD8\xFF", 'webp' => 'RIFF',
];

/** Display name: no folders, control characters or extreme length. The name on disk is always random. */
function clean_name(string $name): string {
    $name = basename(str_replace('\\', '/', $name));
    $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));
    return mb_substr($name !== '' ? $name : 'file', 0, 120);
}

/** Saves an uploaded file of an allowed type. Returns its stored path, extension, size and clean name, or null. */
function save_file_upload(string $field, ?array $onlyExts = null): ?array {
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(t('err.upload_failed', ['code' => $f['error']]));
    }
    if ($f['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException(t('err.too_big'));
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!isset(FILE_TYPES[$ext]) || ($onlyExts !== null && !in_array($ext, $onlyExts, true))) {
        throw new RuntimeException(t('err.type_not_allowed'));
    }
    $head = (string) file_get_contents($f['tmp_name'], false, null, 0, 12);
    $signatureOk = str_starts_with($head, FILE_TYPES[$ext])
        && ($ext !== 'webp' || substr($head, 8, 4) === 'WEBP');
    if (!$signatureOk) {
        throw new RuntimeException(t('err.signature'));
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    $stored = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $stored)) {
        throw new RuntimeException(t('err.save_upload'));
    }
    return ['path' => 'uploads/' . $stored, 'ext' => $ext, 'size' => (int) $f['size'], 'name' => clean_name($f['name'])];
}

/** Whether any row still points at this upload, so it is not deleted while in use. */
function upload_in_use(PDO $pdo, string $path): bool {
    $sql = "SELECT 1 FROM files WHERE path = :p OR preview = :p
        UNION ALL SELECT 1 FROM presentations WHERE pdf = :p OR pptx = :p
        UNION ALL SELECT 1 FROM papers WHERE pdf = :p
        UNION ALL SELECT 1 FROM project_media WHERE path = :p
        UNION ALL SELECT 1 FROM profile WHERE v = :p";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':p' => $path]);
    return (bool) $stmt->fetchColumn();
}

/** Returns the Persian value when the page is in Persian and one exists, otherwise the English value. */
function loc(array $row, string $field, string $lang): string {
    $fa = trim((string) ($row[$field . '_fa'] ?? ''));
    return ($lang === 'fa' && $fa !== '') ? $fa : (string) ($row[$field] ?? '');
}

function e(?string $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Invalid form token. Go back and try again.');
    }
}

/** Saves an uploaded file if one was sent. Returns the path relative to the site root, or null. */
function save_upload(string $field, string $ext): ?string {
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(t('err.upload_failed', ['code' => $f['error']]));
    }
    if ($f['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException(t('err.too_big'));
    }
    if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== $ext) {
        throw new RuntimeException(t('err.expected_ext', ['ext' => $ext]));
    }
    $magic = file_get_contents($f['tmp_name'], false, null, 0, 5);
    if ($ext === 'pdf' && !str_starts_with((string) $magic, '%PDF-')) {
        throw new RuntimeException(t('err.not_pdf'));
    }
    if ($ext === 'pptx' && !str_starts_with((string) $magic, 'PK')) {
        throw new RuntimeException(t('err.not_pptx'));
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name)) {
        throw new RuntimeException(t('err.save_upload'));
    }
    return 'uploads/' . $name;
}

function remove_upload(?string $path): void {
    if ($path && str_starts_with($path, 'uploads/')) {
        $full = __DIR__ . '/' . $path;
        if (is_file($full)) {
            unlink($full);
        }
    }
}

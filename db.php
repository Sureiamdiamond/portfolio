<?php
declare(strict_types=1);

const DATA_DIR = __DIR__ . '/data';
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
        SQL);
    // Backward-compatible migration: add Persian columns to existing databases.
    $persianColumns = [
        'projects' => ['title_fa', 'summary_fa'],
        'papers' => ['title_fa'],
        'presentations' => ['title_fa'],
        'timeline' => ['title_fa', 'org_fa', 'period_fa', 'details_fa'],
    ];
    foreach ($persianColumns as $table => $columns) {
        $existing = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        foreach ($columns as $column) {
            if (!in_array($column, $existing, true)) {
                $pdo->exec("ALTER TABLE $table ADD COLUMN $column TEXT");
            }
        }
    }
    return $pdo;
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
        throw new RuntimeException('Upload failed (code ' . $f['error'] . ').');
    }
    if ($f['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('File is larger than 100 MB.');
    }
    if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== $ext) {
        throw new RuntimeException("Expected a .$ext file.");
    }
    $magic = file_get_contents($f['tmp_name'], false, null, 0, 5);
    if ($ext === 'pdf' && !str_starts_with((string) $magic, '%PDF-')) {
        throw new RuntimeException('That file is not a valid PDF.');
    }
    if ($ext === 'pptx' && !str_starts_with((string) $magic, 'PK')) {
        throw new RuntimeException('That file is not a valid PowerPoint file.');
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name)) {
        throw new RuntimeException('Could not save the uploaded file.');
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

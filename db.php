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
    seed_files($pdo);
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
        throw new RuntimeException('Upload failed (code ' . $f['error'] . ').');
    }
    if ($f['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('File is larger than 100 MB.');
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!isset(FILE_TYPES[$ext]) || ($onlyExts !== null && !in_array($ext, $onlyExts, true))) {
        throw new RuntimeException('This file type is not allowed.');
    }
    $head = (string) file_get_contents($f['tmp_name'], false, null, 0, 12);
    $signatureOk = str_starts_with($head, FILE_TYPES[$ext])
        && ($ext !== 'webp' || substr($head, 8, 4) === 'WEBP');
    if (!$signatureOk) {
        throw new RuntimeException('The file content does not match its extension.');
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    $stored = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $stored)) {
        throw new RuntimeException('Could not save the uploaded file.');
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

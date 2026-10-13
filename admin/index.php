<?php
declare(strict_types=1);
// Admin session: not readable from scripts, not sent cross-site, and sent only over HTTPS when the site uses it
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Strict']);
session_start();
require __DIR__ . '/../db.php';
require __DIR__ . '/../theme.php';
require __DIR__ . '/lang.php';

// Language switch: remembered in this browser for a year, then back to the same page without the parameter
if (isset($_GET['lang']) && in_array($_GET['lang'], ADMIN_LANGS, true)) {
    setcookie(ADMIN_LANG_COOKIE, (string) $_GET['lang'], ['expires' => time() + 31536000, 'path' => '/admin/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => false, 'samesite' => 'Lax']);
    $query = $_GET;
    unset($query['lang']);
    header('Location: index.php' . ($query ? '?' . http_build_query($query) : ''));
    exit;
}

if (!is_file(__DIR__ . '/../config.php')) {
    exit(t('login.no_config'));
}
$config = require __DIR__ . '/../config.php';
$pdo = db();

/** Default admin username, used until one is set from the Password tab. */
const ADMIN_USERNAME = 'sureiamdiamond';

/** Admin account: username and password hash. Kept in data/ once changed from the panel, otherwise config.php (old password still works). */
function admin_account(array $config): array {
    $file = DATA_DIR . '/admin_hash.php';
    if (is_file($file)) {
        $saved = require $file;
        if (is_array($saved) && !empty($saved['admin_hash'])) {
            return [
                'username' => (string) ($saved['username'] ?? ADMIN_USERNAME),
                'admin_hash' => (string) $saved['admin_hash'],
            ];
        }
    }
    return ['username' => ADMIN_USERNAME, 'admin_hash' => (string) $config['admin_hash']];
}

/** Stores the account in data/, which deploys never overwrite and the web cannot read. */
function save_admin_account(string $username, string $hash): void {
    $file = DATA_DIR . '/admin_hash.php';
    $tmp = $file . '.tmp';
    $body = "<?php\n// Written by the admin panel. Do not share this file.\nreturn ['username' => " . var_export($username, true)
        . ", 'admin_hash' => " . var_export($hash, true) . "];\n";
    if (file_put_contents($tmp, $body, LOCK_EX) === false || !rename($tmp, $file)) {
        throw new RuntimeException(t('err.save_failed'));
    }
    @chmod($file, 0600);
}

/** Failed logins per IP: five wrong passwords block that IP for 15 minutes. */
const LOGIN_LIMIT_FILE = DATA_DIR . '/login_limit.json';

function login_limits(): array {
    return is_file(LOGIN_LIMIT_FILE) ? (json_decode((string) file_get_contents(LOGIN_LIMIT_FILE), true) ?: []) : [];
}

function login_blocked(string $ip): bool {
    $entry = login_limits()[$ip] ?? null;
    return $entry !== null && ($entry['until'] ?? 0) > time();
}

function login_failed(string $ip): void {
    $all = login_limits();
    $fails = (int) ($all[$ip]['fails'] ?? 0) + 1;
    $all[$ip] = $fails >= 5 ? ['fails' => 0, 'until' => time() + 900] : ['fails' => $fails, 'until' => 0];
    @file_put_contents(LOGIN_LIMIT_FILE, json_encode($all), LOCK_EX);
}

function login_succeeded(string $ip): void {
    $all = login_limits();
    unset($all[$ip]);
    @file_put_contents(LOGIN_LIMIT_FILE, json_encode($all), LOCK_EX);
}

const TYPES = [
    'projects' => ['label' => 'Projects', 'fields' => ['title', 'title_fa', 'kind', 'summary', 'summary_fa', 'tags', 'url', 'github_url', 'appstore_url', 'playstore_url', 'bazaar_url', 'sort'], 'long' => ['summary', 'summary_fa'], 'files' => []],
    'papers' => ['label' => 'Research', 'fields' => ['title', 'title_fa', 'venue', 'year', 'doi', 'sort'], 'long' => [], 'files' => ['pdf']],
    'presentations' => ['label' => 'Slides', 'fields' => ['title', 'title_fa', 'event', 'year', 'sort'], 'long' => [], 'files' => ['pptx', 'pdf']],
    'timeline' => ['label' => 'Education & experience', 'fields' => ['kind', 'title', 'title_fa', 'org', 'org_fa', 'city', 'city_fa', 'period', 'period_fa', 'website', 'bullets', 'bullets_fa', 'details', 'details_fa', 'sort'], 'long' => ['bullets', 'bullets_fa', 'details', 'details_fa'], 'files' => []],
];
const PROFILE_FIELDS = ['name', 'name_fa', 'headline', 'headline_fa', 'about', 'about_fa', 'email', 'phone', 'location', 'location_fa', 'linkedin', 'github', 'skills', 'languages', 'languages_fa', 'meta_description', 'footer_text'];
const PROFILE_LONG = ['about', 'about_fa', 'skills', 'languages', 'languages_fa', 'meta_description'];
// Sections of the public page that can be hidden; stored as '1' (shown) or '0' (hidden)
const SECTION_SWITCHES = [
    'show_journey' => 'Education & experience',
    'show_projects' => 'Projects',
    'show_research' => 'Research',
    'show_slides' => 'Slides',
];

/** Human label for a field; Persian fields are marked so the admin knows where to type them. */
function label(string $field): string {
    $key = 'field.' . $field;
    return isset(ADMIN_STRINGS['en'][$key]) ? t($key) : ucfirst(str_replace('_', ' ', $field));
}

/** Fields that hold addresses, codes or English-only values are typed left to right. */
function ltr_attr(string $field): string {
    return preg_match('/(url|doi|email|phone|linkedin|github|path)$/', $field) ? ' dir="ltr"' : '';
}

/** The "(current)" marker follows the published value; "(default)" marks the factory setting. */
function marker(string $value, string $published, ?string $default): string {
    return ($default !== null && $value === $default ? ' ' . t('marker.default') : '') . ($value === $published ? ' ' . t('marker.current') : '');
}

/** Links that switch the admin language, keeping the current page. */
function admin_lang_links(): string {
    $links = [];
    foreach (ADMIN_LANGS as $code) {
        $on = $code === admin_lang();
        $href = 'index.php?' . http_build_query(array_merge($_GET, ['lang' => $code]));
        $links[] = '<a href="' . e($href) . '" lang="' . $code . '"' . ($on ? ' class="on" aria-current="true"' : '') . '>' . e(t('lang.' . $code)) . '</a>';
    }
    return implode(' ', $links);
}

$error = null;
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'login') {
            csrf_check();
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            if (login_blocked($ip)) {
                throw new RuntimeException(t('err.locked'));
            }
            usleep(500000); // slows down password guessing
            $account = admin_account($config);
            $typed = strtolower(trim((string) ($_POST['username'] ?? '')));
            if (hash_equals(strtolower($account['username']), $typed) && password_verify((string) ($_POST['password'] ?? ''), $account['admin_hash'])) {
                login_succeeded($ip);
                session_regenerate_id(true);
                $_SESSION['auth'] = true;
                header('Location: index.php');
                exit;
            }
            login_failed($ip);
            $error = t('login.wrong');
        } elseif (empty($_SESSION['auth'])) {
            http_response_code(403);
            exit(t('login.not_logged_in'));
        } else {
            csrf_check();
            $action = $_POST['action'] ?? '';

            if ($action === 'logout') {
                session_destroy();
                header('Location: index.php');
                exit;
            }

            if ($action === 'password') {
                $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
                if (login_blocked($ip)) {
                    throw new RuntimeException(t('err.locked'));
                }
                $new = (string) ($_POST['new'] ?? '');
                $account = admin_account($config);
                if (!password_verify((string) ($_POST['current'] ?? ''), $account['admin_hash'])) {
                    login_failed($ip);
                    throw new RuntimeException(t('err.wrong_current'));
                }
                if (strlen($new) < 12) {
                    throw new RuntimeException(t('err.pw_short'));
                }
                if (!hash_equals($new, (string) ($_POST['repeat'] ?? ''))) {
                    throw new RuntimeException(t('err.pw_mismatch'));
                }
                save_admin_account($account['username'], password_hash($new, PASSWORD_DEFAULT));
                login_succeeded($ip);
                session_regenerate_id(true);
                header('Location: index.php?t=password&changed=1');
                exit;
            }

            if ($action === 'theme_draft' || $action === 'theme_publish') {
                $settings = theme_clean($_POST);
                $published = theme_published($pdo);
                // A new background image is saved as soon as it is chosen; the old draft image is removed once replaced
                if ($upload = save_file_upload('file_bg', ['png', 'webp', 'jpg', 'jpeg'])) {
                    $settings['theme_bg_image'] = $upload['path'];
                } elseif (!empty($_POST['theme_bg_clear'])) {
                    $settings['theme_bg_image'] = '';
                }
                $draftImage = ((theme_draft() ?? [])['settings'] ?? [])['theme_bg_image'] ?? '';
                if ($draftImage !== '' && $draftImage !== $settings['theme_bg_image'] && $draftImage !== $published['theme_bg_image']) {
                    remove_upload($draftImage);
                }
                if ($action === 'theme_draft') {
                    // The preview reads the draft through a random token; nothing changes on the public site yet
                    $token = bin2hex(random_bytes(16));
                    $body = json_encode(['token' => $token, 'created' => time(), 'settings' => $settings], JSON_UNESCAPED_UNICODE);
                    if (file_put_contents(THEME_DRAFT_FILE . '.tmp', $body, LOCK_EX) === false || !rename(THEME_DRAFT_FILE . '.tmp', THEME_DRAFT_FILE)) {
                        throw new RuntimeException(t('err.draft_failed'));
                    }
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'token' => $token,
                        'warnings' => theme_warnings($settings),
                        'bg_image' => $settings['theme_bg_image'],
                        'hero' => theme_hero_background($settings, '../'),
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                if ($published['theme_bg_image'] !== '' && $published['theme_bg_image'] !== $settings['theme_bg_image']) {
                    remove_upload($published['theme_bg_image']);
                }
                foreach ($settings as $key => $value) {
                    $pdo->prepare('INSERT OR REPLACE INTO profile (k, v) VALUES (?, ?)')->execute([$key, $value]);
                }
                @unlink(THEME_DRAFT_FILE);
                header('Location: index.php?t=theme&published=1');
                exit;
            }

            if ($action === 'theme_reset') {
                remove_upload(theme_published($pdo)['theme_bg_image']);
                remove_upload(((theme_draft() ?? [])['settings'] ?? [])['theme_bg_image'] ?? null);
                $pdo->exec("DELETE FROM profile WHERE k LIKE 'theme_%'");
                @unlink(THEME_DRAFT_FILE);
                header('Location: index.php?t=theme&reset=1');
                exit;
            }

            if ($action === 'username') {
                $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
                if (login_blocked($ip)) {
                    throw new RuntimeException(t('err.locked'));
                }
                $account = admin_account($config);
                if (!password_verify((string) ($_POST['current'] ?? ''), $account['admin_hash'])) {
                    login_failed($ip);
                    throw new RuntimeException(t('err.wrong_current'));
                }
                $username = strtolower(trim((string) ($_POST['username'] ?? '')));
                if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) {
                    throw new RuntimeException(t('err.username_format'));
                }
                save_admin_account($username, $account['admin_hash']);
                header('Location: index.php?t=password&renamed=1');
                exit;
            }

            if ($action === 'profile') {
                foreach (PROFILE_FIELDS as $field) {
                    $stmt = $pdo->prepare('INSERT OR REPLACE INTO profile (k, v) VALUES (?, ?)');
                    $stmt->execute([$field, trim($_POST[$field] ?? '')]);
                }
                foreach (array_keys(SECTION_SWITCHES) as $switch) {
                    $pdo->prepare('INSERT OR REPLACE INTO profile (k, v) VALUES (?, ?)')->execute([$switch, isset($_POST[$switch]) ? '1' : '0']);
                }
                if ($cv = save_upload('file_cv', 'pdf')) {
                    $old = $pdo->query("SELECT v FROM profile WHERE k = 'cv'")->fetchColumn();
                    remove_upload($old ?: null);
                    $pdo->prepare("INSERT OR REPLACE INTO profile (k, v) VALUES ('cv', ?)")->execute([$cv]);
                }
                if ($photo = save_file_upload('file_photo', ['png', 'webp'])) {
                    $old = $pdo->query("SELECT v FROM profile WHERE k = 'hero_photo'")->fetchColumn();
                    remove_upload($old ?: null);
                    $pdo->prepare("INSERT OR REPLACE INTO profile (k, v) VALUES ('hero_photo', ?)")->execute([$photo['path']]);
                }
                $notice = t('profile.saved');
            }

            if ($action === 'save') {
                $type = $_POST['type'] ?? '';
                if (!isset(TYPES[$type])) {
                    throw new RuntimeException(t('err.unknown_section'));
                }
                $spec = TYPES[$type];
                $id = (int) ($_POST['id'] ?? 0);
                $data = [];
                foreach ($spec['fields'] as $field) {
                    $data[$field] = trim((string) ($_POST[$field] ?? ''));
                }
                foreach ($spec['files'] as $ext) {
                    if ($path = save_upload('file_' . $ext, $ext)) {
                        $data[$ext] = $path;
                    }
                }
                // Education and experience entries can have a logo; without one the site shows initials
                if ($type === 'timeline' && ($logo = save_file_upload('file_logo', ['png', 'webp', 'jpg', 'jpeg']))) {
                    if ($id > 0) {
                        $old = $pdo->prepare('SELECT logo FROM timeline WHERE id = ?');
                        $old->execute([$id]);
                        remove_upload($old->fetchColumn() ?: null);
                    }
                    $data['logo'] = $logo['path'];
                }
                // Projects can have their own icon; without one the site shows a generated tile
                if ($type === 'projects' && ($icon = save_file_upload('file_icon', ['png', 'webp', 'jpg', 'jpeg']))) {
                    if ($id > 0) {
                        $old = $pdo->prepare('SELECT icon FROM projects WHERE id = ?');
                        $old->execute([$id]);
                        remove_upload($old->fetchColumn() ?: null);
                    }
                    $data['icon'] = $icon['path'];
                }
                if ($id > 0) {
                    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
                    $stmt = $pdo->prepare("UPDATE $type SET $sets WHERE id = ?");
                    $stmt->execute([...array_values($data), $id]);
                } else {
                    $cols = implode(', ', array_keys($data));
                    $marks = implode(', ', array_fill(0, count($data), '?'));
                    $pdo->prepare("INSERT INTO $type ($cols) VALUES ($marks)")->execute(array_values($data));
                }
                $notice = t('common.saved');
                header('Location: index.php?t=' . $type . '&saved=1');
                exit;
            }

            if ($action === 'folder_create') {
                $name = clean_name((string) ($_POST['name'] ?? ''));
                $parent = ($_POST['parent_id'] ?? '') === '' ? null : (int) $_POST['parent_id'];
                if ($parent !== null && !$pdo->query("SELECT 1 FROM folders WHERE id = $parent")->fetchColumn()) {
                    throw new RuntimeException(t('err.parent_missing'));
                }
                $pdo->prepare('INSERT INTO folders (parent_id, name, sort) VALUES (?, ?, 0)')->execute([$parent, $name]);
                header('Location: index.php?t=files&saved=1');
                exit;
            }

            if ($action === 'file_upload') {
                $folder = ($_POST['folder_id'] ?? '') === '' ? null : (int) $_POST['folder_id'];
                if ($folder !== null && !$pdo->query("SELECT 1 FROM folders WHERE id = $folder")->fetchColumn()) {
                    throw new RuntimeException(t('err.folder_missing'));
                }
                $saved = save_file_upload('file_upload');
                if ($saved === null) {
                    throw new RuntimeException(t('err.choose_file'));
                }
                $preview = save_file_upload('file_preview', ['pdf']);
                $pdo->prepare('INSERT INTO files (folder_id, name, path, ext, size, preview, sort) VALUES (?, ?, ?, ?, ?, ?, 0)')
                    ->execute([$folder, $saved['name'], $saved['path'], $saved['ext'], $saved['size'], $preview['path'] ?? null]);
                header('Location: index.php?t=files&saved=1');
                exit;
            }

            if ($action === 'rename') {
                $name = clean_name((string) ($_POST['name'] ?? ''));
                $id = (int) ($_POST['id'] ?? 0);
                $table = ($_POST['kind'] ?? '') === 'folder' ? 'folders' : (($_POST['kind'] ?? '') === 'file' ? 'files' : null);
                if ($table === null) {
                    throw new RuntimeException(t('err.unknown_item'));
                }
                $pdo->prepare("UPDATE $table SET name = ? WHERE id = ?")->execute([$name, $id]);
                header('Location: index.php?t=files&saved=1');
                exit;
            }

            if ($action === 'delete_file') {
                $id = (int) ($_POST['id'] ?? 0);
                $stmt = $pdo->prepare('SELECT path, preview FROM files WHERE id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if ($row) {
                    $pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$id]);
                    foreach (['path', 'preview'] as $col) {
                        if (!empty($row[$col]) && !upload_in_use($pdo, $row[$col])) {
                            remove_upload($row[$col]);
                        }
                    }
                }
                header('Location: index.php?t=files');
                exit;
            }

            if ($action === 'pf_save') {
                $project = (int) ($_POST['project_id'] ?? 0);
                $path = trim((string) ($_POST['path'] ?? ''));
                $content = str_replace("\r\n", "\n", (string) ($_POST['content'] ?? ''));
                if (!$pdo->query("SELECT 1 FROM projects WHERE id = $project")->fetchColumn()) {
                    throw new RuntimeException(t('err.project_missing'));
                }
                if (!valid_project_path($path)) {
                    throw new RuntimeException(t('err.bad_path'));
                }
                if (strlen($content) > 512 * 1024) {
                    throw new RuntimeException(t('err.too_large'));
                }
                $pdo->prepare('DELETE FROM project_files WHERE project_id = ? AND path = ?')->execute([$project, $path]);
                $pdo->prepare('INSERT INTO project_files (project_id, path, content) VALUES (?, ?, ?)')->execute([$project, $path, $content]);
                header('Location: index.php?t=pfiles&project=' . $project . '&saved=1');
                exit;
            }

            if ($action === 'pf_delete') {
                $id = (int) ($_POST['id'] ?? 0);
                $project = (int) $pdo->query("SELECT project_id FROM project_files WHERE id = $id")->fetchColumn();
                $pdo->prepare('DELETE FROM project_files WHERE id = ?')->execute([$id]);
                header('Location: index.php?t=pfiles&project=' . $project);
                exit;
            }

            if ($action === 'media_upload') {
                $project = (int) ($_POST['project_id'] ?? 0);
                if (!$pdo->query("SELECT 1 FROM projects WHERE id = $project")->fetchColumn()) {
                    throw new RuntimeException(t('err.project_missing'));
                }
                $saved = save_file_upload('file_image', ['png', 'jpg', 'jpeg', 'webp']);
                if ($saved === null) {
                    throw new RuntimeException(t('err.choose_image'));
                }
                $pdo->prepare('INSERT INTO project_media (project_id, path, sort) VALUES (?, ?, 0)')->execute([$project, $saved['path']]);
                header('Location: index.php?t=pfiles&project=' . $project . '&saved=1');
                exit;
            }

            if ($action === 'media_delete') {
                $id = (int) ($_POST['id'] ?? 0);
                $stmt = $pdo->prepare('SELECT project_id, path FROM project_media WHERE id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if ($row) {
                    $pdo->prepare('DELETE FROM project_media WHERE id = ?')->execute([$id]);
                    if (!upload_in_use($pdo, $row['path'])) {
                        remove_upload($row['path']);
                    }
                    header('Location: index.php?t=pfiles&project=' . (int) $row['project_id']);
                    exit;
                }
                header('Location: index.php?t=pfiles');
                exit;
            }

            if ($action === 'delete_folder') {
                $id = (int) ($_POST['id'] ?? 0);
                $hasChildren = $pdo->query("SELECT (SELECT COUNT(*) FROM folders WHERE parent_id = $id) + (SELECT COUNT(*) FROM files WHERE folder_id = $id)")->fetchColumn();
                if ((int) $hasChildren > 0) {
                    throw new RuntimeException(t('err.folder_not_empty'));
                }
                $pdo->prepare('DELETE FROM folders WHERE id = ?')->execute([$id]);
                header('Location: index.php?t=files');
                exit;
            }

            if ($action === 'delete') {
                $type = $_POST['type'] ?? '';
                if (!isset(TYPES[$type])) {
                    throw new RuntimeException(t('err.unknown_section'));
                }
                $id = (int) ($_POST['id'] ?? 0);
                foreach (TYPES[$type]['files'] as $ext) {
                    $stmt = $pdo->prepare("SELECT $ext FROM $type WHERE id = ?");
                    $stmt->execute([$id]);
                    remove_upload($stmt->fetchColumn() ?: null);
                }
                if ($type === 'projects') {
                    $stmt = $pdo->prepare('SELECT icon FROM projects WHERE id = ?');
                    $stmt->execute([$id]);
                    remove_upload($stmt->fetchColumn() ?: null);
                }
                $pdo->prepare("DELETE FROM $type WHERE id = ?")->execute([$id]);
                header('Location: index.php?t=' . $type);
                exit;
            }
        }
    } catch (Throwable $ex) {
        // The live preview reads errors as JSON; the other forms show them on the page
        if (($_POST['action'] ?? '') === 'theme_draft') {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE));
        }
        $error = $ex->getMessage();
    }
}

$loggedIn = !empty($_SESSION['auth']);
$tab = $_GET['t'] ?? 'profile';
$edit = null;
if (isset(TYPES[$tab], $_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM $tab WHERE id = ?");
    $stmt->execute([(int) $_GET['id']]);
    $edit = $stmt->fetch() ?: null;
}
$profile = [];
if ($loggedIn) {
    foreach ($pdo->query('SELECT k, v FROM profile') as $row) {
        $profile[$row['k']] = $row['v'];
    }
}
?>
<!doctype html>
<html lang="<?= admin_lang() ?>" dir="<?= admin_lang() === 'fa' ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e(t('login.title')) ?></title>
<style>
@font-face { font-family: "Vazirmatn"; font-style: normal; font-weight: 400; font-display: swap; src: url("../assets/fonts/vazirmatn-arabic-400-normal.woff2") format("woff2"); unicode-range: U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0898-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC; }
@font-face { font-family: "Vazirmatn"; font-style: normal; font-weight: 700; font-display: swap; src: url("../assets/fonts/vazirmatn-arabic-700-normal.woff2") format("woff2"); unicode-range: U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0898-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC; }
@font-face { font-family: "Vazirmatn"; font-style: normal; font-weight: 400; font-display: swap; src: url("../assets/fonts/vazirmatn-latin-400-normal.woff2") format("woff2"); unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+2000-206F, U+2074, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }
@font-face { font-family: "Vazirmatn"; font-style: normal; font-weight: 700; font-display: swap; src: url("../assets/fonts/vazirmatn-latin-700-normal.woff2") format("woff2"); unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+2000-206F, U+2074, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }
  html[dir="rtl"] body { font-family: Vazirmatn, system-ui, sans-serif; }
  body { font-family: system-ui, sans-serif; margin: 0; background: #eef4f8; color: #0f2a3d; }
  main { max-width: 860px; margin: 0 auto; padding: 16px; }
  main.wide { max-width: 1320px; }
  nav { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 16px; }
  nav a, button { padding: 8px 12px; border-radius: 8px; border: 1px solid #9bb8c9; background: #fff; color: #0f2a3d; text-decoration: none; cursor: pointer; font: inherit; }
  nav a.on { background: #1a7fa8; color: #fff; border-color: #1a7fa8; }
  .danger { color: #a12b2b; }
  form, .card { background: #fff; border-radius: 12px; padding: 16px; margin-bottom: 16px; box-shadow: 0 2px 10px rgba(15,42,61,.08); }
  label { display: block; font-size: 14px; margin: 10px 0 4px; }
  input, textarea, select { width: 100%; box-sizing: border-box; padding: 8px; border: 1px solid #9bb8c9; border-radius: 8px; font: inherit; }
  textarea { min-height: 90px; }
  .row { display: flex; justify-content: space-between; gap: 8px; padding: 8px 0; border-bottom: 1px solid #e3edf3; align-items: center; }
  .msg { padding: 10px; border-radius: 8px; margin-bottom: 12px; background: #e3f4ea; }
  .err { background: #fbe4e4; }
  .muted { color: #5b7385; font-size: 13px; }
  .swatches { display: flex; gap: 6px; margin: 8px 0; }
  .swatches span { width: 28px; height: 28px; border-radius: 8px; }
  .frame-wrap { max-width: 100%; display: flex; justify-content: center; }
  .pv-toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
  .pv-toolbar button, .pv-toolbar a.button { white-space: nowrap; }
  .pv-toolbar [aria-pressed="true"] { background: #1a7fa8; color: #fff; border-color: #1a7fa8; }
  #t-frame { width: 100%; height: 640px; max-width: 100%; border: 1px solid #d6e4ec; border-radius: 10px; background: #fff; transition: width .2s ease; }
  #t-frame.phone { width: 390px; }
  .split { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.3fr); gap: 16px; align-items: start; }
  .preview { position: sticky; top: 8px; background: #fff; border-radius: 12px; padding: 16px; box-shadow: 0 2px 10px rgba(15,42,61,.08); }
  .pv-card { border: 1px solid #d6e4ec; border-radius: 12px; padding: 14px; margin-bottom: 12px; background: #0f1a2e; color: #e8f1f7; }
  .pv-pill { display: inline-block; margin: 0 0 8px; padding: 4px 10px; border-radius: 999px; background: #1f2d4a; font-size: 13px; }
  .pv-card h4 { margin: 0 0 8px; font-size: 22px; }
  .pv-about { margin: 0; font-size: 14px; color: #c2d0dc; white-space: pre-line; }
  #pv-photo { max-width: 160px; border-radius: 12px; margin-top: 8px; }
  fieldset { border: 1px solid #d6e4ec; border-radius: 8px; margin: 12px 0; padding: 8px 12px; }
  legend { font-size: 14px; padding: 0 4px; }
  label.check { display: flex; align-items: center; gap: 8px; margin: 6px 0; }
  label.check input { width: auto; }
  a.button { padding: 8px 12px; border-radius: 8px; border: 1px solid #9bb8c9; text-decoration: none; color: #0f2a3d; background: #fff; }
  .hero-mini { width: 180px; aspect-ratio: 4 / 5; border-radius: 16px; background: var(--hero-bg, #0052FF); box-shadow: 0 2px 10px rgba(15,42,61,.12); }
  @media (max-width: 760px) { .split { grid-template-columns: 1fr; } .preview { position: static; } }
</style>
<script>const ADMIN_T = <?= json_encode(['photo' => t('profile.photo_hint'), 'cv' => t('profile.cv_label'), 'kb' => 'KB', 'previewFailed' => t('theme.preview_failed')], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;</script>
</head>
<body>
<main class="<?= in_array($tab, ['theme', 'profile'], true) ? 'wide' : '' ?>">
<?php if (!$loggedIn): ?>
  <nav class="lang-nav" aria-label="<?= e(t('lang.label')) ?>"><?= admin_lang_links() ?></nav>
  <h1><?= e(t('login.title')) ?></h1>
  <?php if ($error): ?><div class="msg err"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="login">
    <label for="user"><?= e(t('login.username')) ?></label>
    <input id="user" name="username" autocomplete="username" dir="ltr" required autofocus>
    <label for="pw"><?= e(t('login.password')) ?></label>
    <input id="pw" type="password" name="password" autocomplete="current-password" dir="ltr" required>
    <p><button type="submit"><?= e(t('login.submit')) ?></button></p>
  </form>
<?php else: ?>
  <nav>
    <a href="index.php?t=profile" class="<?= $tab === 'profile' ? 'on' : '' ?>"><?= e(t('nav.profile')) ?></a>
    <a href="index.php?t=files" class="<?= $tab === 'files' ? 'on' : '' ?>"><?= e(t('nav.files')) ?></a>
    <a href="index.php?t=pfiles" class="<?= $tab === 'pfiles' ? 'on' : '' ?>"><?= e(t('nav.pfiles')) ?></a>
    <?php foreach (TYPES as $key => $spec): ?>
      <a href="index.php?t=<?= $key ?>" class="<?= $tab === $key ? 'on' : '' ?>"><?= e(t('type.' . $key)) ?></a>
    <?php endforeach; ?>
    <a href="index.php?t=theme" class="<?= $tab === 'theme' ? 'on' : '' ?>"><?= e(t('nav.theme')) ?></a>
    <a href="index.php?t=password" class="<?= $tab === 'password' ? 'on' : '' ?>"><?= e(t('nav.password')) ?></a>
    <a href="../index.php" target="_blank"><?= e(t('nav.view_site')) ?></a>
    <form method="post" style="margin:0;padding:0;background:none;box-shadow:none;margin-inline-start:auto">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button name="action" value="logout" type="submit"><?= e(t('nav.logout')) ?></button>
      <span class="lang-nav" aria-label="<?= e(t('lang.label')) ?>"><?= admin_lang_links() ?></span>
    </form>
  </nav>

  <?php if ($error): ?><div class="msg err"><?= e($error) ?></div><?php endif; ?>
  <?php if ($notice || isset($_GET['saved'])): ?><div class="msg"><?= e(t('common.saved')) ?></div><?php endif; ?>

  <?php if ($tab === 'theme'): ?>
    <?php $current = theme_published($pdo); ?>
    <?php if (isset($_GET['published'])): ?><div class="msg"><?= e(t('theme.published')) ?></div><?php endif; ?>
    <?php if (isset($_GET['reset'])): ?><div class="msg"><?= e(t('theme.reset_done')) ?></div><?php endif; ?>
    <div class="split">
    <form id="theme-form" method="post" autocomplete="off" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="theme_publish">
      <h2><?= e(t('theme.heading')) ?></h2>
      <label for="t-preset"><?= e(t('theme.preset')) ?></label>
      <select id="t-preset" name="theme_preset">
        <?php foreach (THEME_PRESETS as $key => $preset): ?>
          <option value="<?= e($key) ?>" <?= $current['theme_preset'] === $key ? 'selected' : '' ?>><?= e(t('preset.' . $key) . marker($key, $current['theme_preset'], 'electric')) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="swatches" aria-hidden="true">
        <?php foreach (THEME_PRESETS as $key => $preset): if ($key === 'custom') continue; ?>
          <span title="<?= e(t('preset.' . $key)) ?>" style="background:linear-gradient(135deg,<?= e($preset['light']) ?>,<?= e($preset['accent']) ?>)"></span>
        <?php endforeach; ?>
      </div>
      <label for="t-primary"><?= e(t('theme.primary')) ?></label>
      <input id="t-primary" type="color" name="theme_primary" value="<?= e(strtolower($current['theme_primary'])) ?>">
      <label for="t-accent"><?= e(t('theme.accent')) ?></label>
      <input id="t-accent" type="color" name="theme_accent" value="<?= e(strtolower($current['theme_accent'])) ?>">
      <label for="t-radius"><?= e(t('theme.radius')) ?></label>
      <select id="t-radius" name="theme_radius">
        <?php foreach (['sharp' => t('theme.radius_sharp'), 'soft' => t('theme.radius_soft'), 'round' => t('theme.radius_round')] as $key => $label): ?>
          <option value="<?= $key ?>" <?= $current['theme_radius'] === $key ? 'selected' : '' ?>><?= e($label . marker($key, $current['theme_radius'], 'soft')) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="t-density"><?= e(t('theme.density')) ?></label>
      <select id="t-density" name="theme_density">
        <?php foreach (['compact' => t('theme.density_compact'), 'comfortable' => t('theme.density_comfortable'), 'airy' => t('theme.density_airy')] as $key => $label): ?>
          <option value="<?= $key ?>" <?= $current['theme_density'] === $key ? 'selected' : '' ?>><?= e($label . marker($key, $current['theme_density'], 'comfortable')) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="t-mode"><?= e(t('theme.mode')) ?></label>
      <select id="t-mode" name="theme_mode">
        <?php foreach (['system' => t('theme.mode_system'), 'light' => t('theme.mode_light'), 'dark' => t('theme.mode_dark')] as $key => $label): ?>
          <option value="<?= $key ?>" <?= $current['theme_mode'] === $key ? 'selected' : '' ?>><?= e($label . marker($key, $current['theme_mode'], 'system')) ?></option>
        <?php endforeach; ?>
      </select>
      <label class="check"><input type="checkbox" name="theme_motion" value="on" <?= $current['theme_motion'] === 'on' ? 'checked' : '' ?>> <?= e(t('theme.motion')) ?></label>
      <label class="check"><input type="checkbox" name="theme_glass" value="on" <?= $current['theme_glass'] === 'on' ? 'checked' : '' ?>> <?= e(t('theme.glass')) ?></label>
      <label for="t-glass-intensity"><?= e(t('theme.glass_intensity')) ?></label>
      <select id="t-glass-intensity" name="theme_glass_intensity">
        <?php foreach (['soft' => t('theme.glass_soft'), 'medium' => t('theme.glass_medium'), 'strong' => t('theme.glass_strong')] as $key => $label): ?>
          <option value="<?= $key ?>" <?= $current['theme_glass_intensity'] === $key ? 'selected' : '' ?>><?= e($label . marker($key, $current['theme_glass_intensity'], 'medium')) ?></option>
        <?php endforeach; ?>
      </select>
      <h2><?= e(t('theme.bg_heading')) ?></h2>
      <label for="t-bg-preset"><?= e(t('theme.bg_preset')) ?></label>
      <select id="t-bg-preset" name="theme_bg_preset">
        <?php foreach (HERO_PRESETS as $key => $preset): ?>
          <option value="<?= e($key) ?>" <?= $current['theme_bg_preset'] === $key ? 'selected' : '' ?>><?= e(t('hero.' . $key) . marker($key, $current['theme_bg_preset'], 'blue')) ?></option>
        <?php endforeach; ?>
        <option value="custom" <?= $current['theme_bg_preset'] === 'custom' ? 'selected' : '' ?>><?= e(t('theme.bg_custom') . marker('custom', $current['theme_bg_preset'], null)) ?></option>
      </select>
      <label for="t-bg-type"><?= e(t('theme.bg_type')) ?></label>
      <select id="t-bg-type" name="theme_bg_type">
        <?php foreach (['radial' => t('theme.bg_radial'), 'linear' => t('theme.bg_linear'), 'mesh' => t('theme.bg_mesh'), 'solid' => t('theme.bg_solid')] as $key => $label): ?>
          <option value="<?= $key ?>" <?= $current['theme_bg_type'] === $key ? 'selected' : '' ?>><?= e($label . marker($key, $current['theme_bg_type'], 'radial')) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="t-bg-c1"><?= e(t('theme.bg_c1')) ?></label>
      <input id="t-bg-c1" type="color" name="theme_bg_c1" value="<?= e(strtolower($current['theme_bg_c1'])) ?>">
      <label for="t-bg-c2"><?= e(t('theme.bg_c2')) ?></label>
      <input id="t-bg-c2" type="color" name="theme_bg_c2" value="<?= e(strtolower($current['theme_bg_c2'])) ?>">
      <label for="t-bg-c3"><?= e(t('theme.bg_c3')) ?></label>
      <input id="t-bg-c3" type="color" name="theme_bg_c3" value="<?= e(strtolower($current['theme_bg_c3'])) ?>">
      <label for="t-bg-angle"><?= e(t('theme.bg_angle')) ?> <span id="t-bg-angle-out"><?= (int) $current['theme_bg_angle'] ?></span>°</label>
      <input id="t-bg-angle" type="range" name="theme_bg_angle" min="0" max="360" value="<?= (int) $current['theme_bg_angle'] ?>">
      <label for="t-bg-x"><?= e(t('theme.bg_x')) ?> <span id="t-bg-x-out"><?= (int) $current['theme_bg_x'] ?></span>%</label>
      <input id="t-bg-x" type="range" name="theme_bg_x" min="0" max="100" value="<?= (int) $current['theme_bg_x'] ?>">
      <label for="t-bg-y"><?= e(t('theme.bg_y')) ?> <span id="t-bg-y-out"><?= (int) $current['theme_bg_y'] ?></span>%</label>
      <input id="t-bg-y" type="range" name="theme_bg_y" min="0" max="100" value="<?= (int) $current['theme_bg_y'] ?>">
      <label for="t-bg-pattern"><?= e(t('theme.bg_pattern')) ?></label>
      <select id="t-bg-pattern" name="theme_bg_pattern">
        <?php foreach (['none' => t('theme.pattern_none'), 'dots' => t('theme.pattern_dots'), 'grid' => t('theme.pattern_grid'), 'noise' => t('theme.pattern_noise')] as $key => $label): ?>
          <option value="<?= $key ?>" <?= $current['theme_bg_pattern'] === $key ? 'selected' : '' ?>><?= e($label . marker($key, $current['theme_bg_pattern'], 'none')) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="t-bg-file"><?= e(t('theme.bg_image')) ?></label>
      <input id="t-bg-file" type="file" name="file_bg" accept=".png,.webp,.jpg,.jpeg">
      <label class="check"><input type="checkbox" name="theme_bg_clear" value="1"> <?= e(t('theme.bg_clear')) ?></label>
      <input type="hidden" id="t-bg-image" name="theme_bg_image" value="<?= e($current['theme_bg_image']) ?>">
      <div id="t-warnings" class="msg err" hidden></div>
      <p><button type="submit"><?= e(t('theme.publish')) ?></button></p>
    </form>
    <aside class="preview">
      <h3><?= e(t('theme.live')) ?></h3>
      <div class="pv-toolbar" role="group" aria-label="<?= e(t('theme.live')) ?>">
        <button type="button" data-width="100%" aria-pressed="true"><?= e(t('theme.desktop')) ?></button>
        <button type="button" data-width="390px" aria-pressed="false"><?= e(t('theme.phone')) ?></button>
        <a class="button" id="t-open" href="../index.php" target="_blank"><?= e(t('theme.open_tab')) ?></a>
      </div>
      <div class="frame-wrap"><iframe id="t-frame" title="<?= e(t('theme.frame_title')) ?>" src="../index.php?preview=none"></iframe></div>
      <p class="muted"><?= e(t('theme.help')) ?></p>
      <h3><?= e(t('theme.photo_card')) ?></h3>
      <div id="t-hero-mini" class="hero-mini"></div>
      <form method="post" onsubmit="return confirm('<?= e(t('theme.reset_confirm')) ?>')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="theme_reset">
        <button class="danger" type="submit"><?= e(t('theme.reset_button')) ?></button>
      </form>
    </aside>
    </div>
    <script>
      // Each change saves a draft and reloads the preview with it (the public page only shows drafts through a random token)
      (function () {
        const form = document.getElementById('theme-form');
        const frame = document.getElementById('t-frame');
        const open = document.getElementById('t-open');
        const warnings = document.getElementById('t-warnings');
        const heroMini = document.getElementById('t-hero-mini');
        const bgPreset = document.getElementById('t-bg-preset');
        const bgFile = document.getElementById('t-bg-file');
        const bgImage = document.getElementById('t-bg-image');
        const bgClear = form.theme_bg_clear;
        const heroPresets = <?= json_encode(HERO_PRESETS, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
        const bgFields = ['theme_bg_type', 'theme_bg_c1', 'theme_bg_c2', 'theme_bg_c3', 'theme_bg_angle', 'theme_bg_x', 'theme_bg_y'];
        let timer = null;
        // Picking a preset fills the fields; editing a field by hand switches the preset to "Custom"
        bgPreset.addEventListener('change', () => {
          const p = heroPresets[bgPreset.value];
          if (!p) return;
          form.theme_bg_type.value = p.type;
          form.theme_bg_c1.value = p.c1;
          form.theme_bg_c2.value = p.c2;
          form.theme_bg_c3.value = p.c3;
          form.theme_bg_angle.value = p.angle;
          form.theme_bg_x.value = p.x;
          form.theme_bg_y.value = p.y;
        });
        bgFields.forEach((name) => form[name].addEventListener('input', () => { bgPreset.value = 'custom'; }));
        form.theme_bg_angle.addEventListener('input', () => { document.getElementById('t-bg-angle-out').textContent = form.theme_bg_angle.value; });
        form.theme_bg_x.addEventListener('input', () => { document.getElementById('t-bg-x-out').textContent = form.theme_bg_x.value; });
        form.theme_bg_y.addEventListener('input', () => { document.getElementById('t-bg-y-out').textContent = form.theme_bg_y.value; });
        bgFile.addEventListener('change', () => { bgClear.checked = false; });
        bgClear.addEventListener('change', () => { if (bgClear.checked) bgFile.value = ''; });
        const update = () => {
          const body = new FormData(form);
          body.set('action', 'theme_draft');
          body.set('theme_motion', form.theme_motion.checked ? 'on' : 'off');
          body.set('theme_glass', form.theme_glass.checked ? 'on' : 'off');
          body.set('theme_bg_clear', bgClear.checked ? '1' : '');
          fetch('index.php?t=theme', { method: 'POST', body, headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
            .then((r) => r.json().then((data) => ({ ok: r.ok, data })).catch(() => ({ ok: false, data: {} })))
            .then(({ ok, data }) => {
              if (!ok) { warnings.hidden = false; warnings.textContent = data.error || ADMIN_T.previewFailed; return; }
              frame.src = '../index.php?preview=' + data.token;
              open.href = '../index.php?preview=' + data.token;
              warnings.hidden = data.warnings.length === 0;
              warnings.textContent = data.warnings.join(' ');
              // The uploaded image is now part of the draft; clear the picker so the next change does not upload it again
              bgImage.value = data.bg_image;
              bgFile.value = '';
              bgClear.checked = false;
              heroMini.style.setProperty('--hero-bg', data.hero);
            })
            .catch(() => { warnings.hidden = false; warnings.textContent = ADMIN_T.previewFailed; });
        };
        form.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(update, 350); });
        form.addEventListener('change', () => { clearTimeout(timer); timer = setTimeout(update, 0); });
        // Desktop fills the preview column; phone is a centred 390px frame. The pressed button shows which one is active.
        const sizeButtons = document.querySelectorAll('[data-width]');
        sizeButtons.forEach((btn) => btn.addEventListener('click', () => {
          frame.classList.toggle('phone', btn.dataset.width !== '100%');
          sizeButtons.forEach((other) => other.setAttribute('aria-pressed', String(other === btn)));
        }));
        update();
      })();
    </script>
  <?php elseif ($tab === 'password'): ?>
    <?php if (isset($_GET['changed'])): ?><div class="msg"><?= e(t('password.changed')) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="password">
      <h2><?= e(t('password.heading')) ?></h2>
      <label for="pw-current"><?= e(t('password.current')) ?></label>
      <input id="pw-current" type="password" name="current" autocomplete="current-password" required>
      <label for="pw-new"><?= e(t('password.new')) ?></label>
      <input id="pw-new" type="password" name="new" autocomplete="new-password" minlength="12" required>
      <label for="pw-repeat"><?= e(t('password.repeat')) ?></label>
      <input id="pw-repeat" type="password" name="repeat" autocomplete="new-password" minlength="12" required>
      <p><button type="submit"><?= e(t('password.submit')) ?></button></p>
    </form>
    <?php if (isset($_GET['renamed'])): ?><div class="msg"><?= e(t('password.username_changed')) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="username">
      <h2><?= e(t('password.username_heading')) ?></h2>
      <p class="muted"><?= e(t('password.current_username')) ?> <strong><?= e(admin_account($config)['username']) ?></strong></p>
      <label for="us-new"><?= e(t('password.new_username')) ?></label>
      <input id="us-new" name="username" pattern="[A-Za-z0-9._-]{3,40}" dir="ltr" required>
      <label for="us-current"><?= e(t('password.current')) ?></label>
      <input id="us-current" type="password" name="current" autocomplete="current-password" required>
      <p><button type="submit"><?= e(t('password.username_submit')) ?></button></p>
    </form>
  <?php elseif ($tab === 'profile'): ?>
    <div class="split">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="profile">
      <?php foreach (PROFILE_FIELDS as $field): ?>
        <label for="p-<?= $field ?>"><?= e(label($field)) ?></label>
        <?php if (in_array($field, PROFILE_LONG, true)): ?>
          <textarea id="p-<?= $field ?>" name="<?= $field ?>"><?= e($profile[$field] ?? '') ?></textarea>
        <?php else: ?>
          <input id="p-<?= $field ?>" name="<?= $field ?>"<?= ltr_attr($field) ?> value="<?= e($profile[$field] ?? '') ?>">
        <?php endif; ?>
      <?php endforeach; ?>
      <fieldset>
        <legend><?= e(t('profile.sections')) ?></legend>
        <?php foreach (SECTION_SWITCHES as $switch => $title): ?>
          <label class="check"><input type="checkbox" name="<?= $switch ?>" <?= ($profile[$switch] ?? '1') !== '0' ? 'checked' : '' ?>> <?= e(t('section.' . $switch)) ?></label>
        <?php endforeach; ?>
      </fieldset>
      <label for="cv"><?= e(t('profile.cv')) ?></label>
      <input id="cv" type="file" name="file_cv" accept="application/pdf,.pdf">
      <p class="muted"><?= e(t('profile.current_cv')) ?> <?= !empty($profile['cv']) ? '<a href="../' . e($profile['cv']) . '" target="_blank">' . e(t('common.open')) . '</a>' : e(t('common.none')) ?></p>
      <label for="photo"><?= e(t('profile.photo')) ?></label>
      <input id="photo" type="file" name="file_photo" accept="image/png,image/webp,.png,.webp">
      <p class="muted"><?= e(t('profile.current_photo')) ?> <?= !empty($profile['hero_photo']) ? '<a href="../' . e($profile['hero_photo']) . '" target="_blank">' . e(t('common.open')) . '</a>' : e(t('common.built_in')) ?></p>
      <p><button type="submit"><?= e(t('profile.save')) ?></button> <a class="button" href="../index.php" target="_blank"><?= e(t('profile.preview_saved')) ?></a></p>
    </form>
    <aside class="preview" aria-label="Live preview">
      <h3><?= e(t('profile.live')) ?></h3>
      <p class="muted"><?= e(t('profile.live_help')) ?></p>
      <div class="pv-card">
        <p class="pv-pill" id="pv-headline"><?= e($profile['headline'] ?? '') ?></p>
        <h4 id="pv-name"><?= e($profile['name'] ?? '') ?></h4>
        <p class="pv-about" id="pv-about"><?= nl2br(e($profile['about'] ?? '')) ?></p>
        <p class="pv-photo"><img id="pv-photo" alt="" hidden></p>
      </div>
      <div class="pv-card" dir="rtl">
        <p class="pv-pill" id="pv-headline-fa"><?= e($profile['headline_fa'] ?? '') ?></p>
        <h4 id="pv-name-fa"><?= e($profile['name_fa'] ?? '') ?></h4>
        <p class="pv-about" id="pv-about-fa"><?= nl2br(e($profile['about_fa'] ?? '')) ?></p>
      </div>
      <p class="muted" id="pv-file"></p>
    </aside>
    </div>
    <script>
      // Live preview of the profile text and the chosen photo, before anything is uploaded
      (function () {
        const pairs = { 'p-name': 'pv-name', 'p-name_fa': 'pv-name-fa', 'p-headline': 'pv-headline', 'p-headline_fa': 'pv-headline-fa' };
        Object.keys(pairs).forEach((id) => {
          const input = document.getElementById(id), target = document.getElementById(pairs[id]);
          if (input && target) input.addEventListener('input', () => { target.textContent = input.value; });
        });
        const about = document.getElementById('p-about'), aboutOut = document.getElementById('pv-about');
        if (about && aboutOut) about.addEventListener('input', () => { aboutOut.textContent = about.value; });
        const aboutFa = document.getElementById('p-about_fa'), aboutFaOut = document.getElementById('pv-about-fa');
        if (aboutFa && aboutFaOut) aboutFa.addEventListener('input', () => { aboutFaOut.textContent = aboutFa.value; });
        const photo = document.getElementById('photo'), img = document.getElementById('pv-photo'), file = document.getElementById('pv-file');
        if (photo) photo.addEventListener('change', () => {
          const f = photo.files[0];
          if (!f) { img.hidden = true; file.textContent = ''; return; }
          img.src = URL.createObjectURL(f); img.hidden = false;
          file.textContent = f.name + ' · ' + Math.round(f.size / 1024) + ' ' + ADMIN_T.kb + ' (' + ADMIN_T.photo + ')';
        });
        const cv = document.getElementById('cv');
        if (cv) cv.addEventListener('change', () => {
          const f = cv.files[0];
          file.textContent = f ? ADMIN_T.cv + ' ' + f.name + ' · ' + Math.round(f.size / 1024) + ' ' + ADMIN_T.kb : '';
        });
      })();
    </script>
  <?php elseif ($tab === 'files'): ?>
    <?php
      $folders = $pdo->query('SELECT * FROM folders ORDER BY sort, id')->fetchAll();
      $files = $pdo->query('SELECT * FROM files ORDER BY folder_id, sort, id')->fetchAll();
      // Full path of each folder, e.g. "Papers / 2025"
      $paths = [];
      $pathOf = function (?int $id) use (&$pathOf, &$paths, $folders): string {
          if ($id === null) return '';
          if (isset($paths[$id])) return $paths[$id];
          foreach ($folders as $f) {
              if ((int) $f['id'] === $id) {
                  $parent = $f['parent_id'] === null ? null : (int) $f['parent_id'];
                  $prefix = $parent === null ? '' : $pathOf($parent) . ' / ';
                  return $paths[$id] = $prefix . $f['name'];
              }
          }
          return '';
      };
      foreach ($folders as $f) { $pathOf((int) $f['id']); }
    ?>
    <h2><?= e(t('files.heading')) ?></h2>
    <p class="muted"><?= e(t('files.help')) ?></p>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="folder_create">
      <h3><?= e(t('files.new_folder')) ?></h3>
      <label for="fc-name"><?= e(t('files.name')) ?></label>
      <input id="fc-name" name="name" required maxlength="120">
      <label for="fc-parent"><?= e(t('files.inside')) ?></label>
      <select id="fc-parent" name="parent_id">
        <option value=""><?= e(t('files.root')) ?></option>
        <?php foreach ($folders as $f): ?><option value="<?= (int) $f['id'] ?>"><?= e($paths[(int) $f['id']]) ?></option><?php endforeach; ?>
      </select>
      <p><button type="submit"><?= e(t('files.create_folder')) ?></button></p>
    </form>

    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="file_upload">
      <h3><?= e(t('files.upload_heading')) ?></h3>
      <label for="fu-folder"><?= e(t('files.folder')) ?></label>
      <select id="fu-folder" name="folder_id">
        <option value=""><?= e(t('files.root')) ?></option>
        <?php foreach ($folders as $f): ?><option value="<?= (int) $f['id'] ?>"><?= e($paths[(int) $f['id']]) ?></option><?php endforeach; ?>
      </select>
      <label for="fu-file"><?= e(t('files.file')) ?></label>
      <input id="fu-file" type="file" name="file_upload" required>
      <label for="fu-preview"><?= e(t('files.pdf_preview')) ?></label>
      <input id="fu-preview" type="file" name="file_preview" accept=".pdf">
      <p><button type="submit"><?= e(t('common.upload')) ?></button></p>
    </form>

    <div class="card">
      <h3><?= e(t('files.folders')) ?></h3>
      <?php foreach ($folders as $f): ?>
        <div class="row">
          <span><?= e($paths[(int) $f['id']]) ?>/</span>
          <span>
            <form method="post" style="display:inline;background:none;box-shadow:none;padding:0;margin:0">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="rename"><input type="hidden" name="kind" value="folder"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
              <input name="name" value="<?= e($f['name']) ?>" maxlength="120" aria-label="<?= e(t('files.folder_name')) ?>" style="width:160px;display:inline-block">
              <button type="submit"><?= e(t('common.rename')) ?></button>
            </form>
            <form method="post" style="display:inline;background:none;box-shadow:none;padding:0;margin:0" onsubmit="return confirm('<?= e(t('files.delete_folder')) ?>')">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete_folder"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
              <button class="danger" type="submit"><?= e(t('common.delete')) ?></button>
            </form>
          </span>
        </div>
      <?php endforeach; ?>
      <?php if (!$folders): ?><p class="muted"><?= e(t('files.no_folders')) ?></p><?php endif; ?>
    </div>

    <div class="card">
      <h3><?= e(t('files.files')) ?></h3>
      <?php foreach ($files as $fl): ?>
        <div class="row">
          <span>
            <?= e($fl['folder_id'] ? ($paths[(int) $fl['folder_id']] . ' / ') : '') ?><?= e($fl['name']) ?>
            <span class="muted"><?= e(strtoupper($fl['ext'])) ?><?= $fl['preview'] ? ' ' . t('common.with_preview') : '' ?></span>
          </span>
          <span>
            <form method="post" style="display:inline;background:none;box-shadow:none;padding:0;margin:0">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="rename"><input type="hidden" name="kind" value="file"><input type="hidden" name="id" value="<?= (int) $fl['id'] ?>">
              <input name="name" value="<?= e($fl['name']) ?>" maxlength="120" aria-label="<?= e(t('files.file_name')) ?>" style="width:160px;display:inline-block">
              <button type="submit"><?= e(t('common.rename')) ?></button>
            </form>
            <a href="../<?= e($fl['path']) ?>" target="_blank"><?= e(t('common.open')) ?></a>
            <form method="post" style="display:inline;background:none;box-shadow:none;padding:0;margin:0" onsubmit="return confirm('<?= e(t('files.delete_file')) ?>')">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete_file"><input type="hidden" name="id" value="<?= (int) $fl['id'] ?>">
              <button class="danger" type="submit"><?= e(t('common.delete')) ?></button>
            </form>
          </span>
        </div>
      <?php endforeach; ?>
      <?php if (!$files): ?><p class="muted"><?= e(t('files.no_files')) ?></p><?php endif; ?>
    </div>

  <?php elseif ($tab === 'pfiles'): ?>
    <?php
      $projectList = $pdo->query('SELECT id, title FROM projects ORDER BY sort, id')->fetchAll();
      $pid = (int) ($_GET['project'] ?? ($projectList[0]['id'] ?? 0));
      $overrides = [];
      $media = [];
      if ($pid > 0) {
          $stmt = $pdo->prepare('SELECT id, path, length(content) AS size FROM project_files WHERE project_id = ? ORDER BY path');
          $stmt->execute([$pid]);
          $overrides = $stmt->fetchAll();
          $stmt = $pdo->prepare('SELECT id, path FROM project_media WHERE project_id = ? ORDER BY sort, id');
          $stmt->execute([$pid]);
          $media = $stmt->fetchAll();
      }
      $templatePaths = ['README.md', 'pubspec.yaml', 'main.dart', 'analysis_options.yaml', '.gitignore', 'links.md'];
    ?>
    <h2><?= e(t('pfiles.heading')) ?></h2>
    <p class="muted"><?= e(t('pfiles.help')) ?></p>

    <form method="get">
      <input type="hidden" name="t" value="pfiles">
      <label for="pf-project"><?= e(t('pfiles.project')) ?></label>
      <select id="pf-project" name="project">
        <?php foreach ($projectList as $pr): ?>
          <option value="<?= (int) $pr['id'] ?>" <?= (int) $pr['id'] === $pid ? 'selected' : '' ?>><?= e($pr['title']) ?></option>
        <?php endforeach; ?>
      </select>
      <p><button type="submit"><?= e(t('common.show')) ?></button></p>
    </form>

    <?php if ($pid > 0): ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="pf_save">
      <input type="hidden" name="project_id" value="<?= $pid ?>">
      <h3><?= e(t('pfiles.set_heading')) ?></h3>
      <label for="pf-path"><?= t('pfiles.path') ?></label>
      <input id="pf-path" dir="ltr" name="path" list="pf-paths" required maxlength="200" placeholder="lib/screens/home.dart">
      <datalist id="pf-paths"><?php foreach ($templatePaths as $tp): ?><option value="<?= e($tp) ?>"><?php endforeach; ?></datalist>
      <label for="pf-content"><?= e(t('pfiles.contents')) ?></label>
      <textarea id="pf-content" name="content" rows="14" style="font-family:ui-monospace,Menlo,monospace"></textarea>
      <p><button type="submit"><?= e(t('pfiles.save_file')) ?></button></p>
    </form>

    <div class="card">
      <h3><?= e(t('pfiles.saved_heading')) ?></h3>
      <?php foreach ($overrides as $ov): ?>
        <div class="row">
          <span><?= e($ov['path']) ?> <span class="muted"><?= (int) $ov['size'] ?> <?= e(t('common.bytes')) ?></span></span>
          <form method="post" style="display:inline;background:none;box-shadow:none;padding:0;margin:0" onsubmit="return confirm('<?= e(t('pfiles.remove_confirm')) ?>')">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="pf_delete"><input type="hidden" name="id" value="<?= (int) $ov['id'] ?>">
            <button class="danger" type="submit"><?= e(t('common.remove')) ?></button>
          </form>
        </div>
      <?php endforeach; ?>
      <?php if (!$overrides): ?><p class="muted"><?= e(t('pfiles.none_saved')) ?></p><?php endif; ?>
    </div>

    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="media_upload">
      <input type="hidden" name="project_id" value="<?= $pid ?>">
      <h3><?= e(t('pfiles.screenshot_heading')) ?></h3>
      <label for="pm-file"><?= e(t('pfiles.image')) ?></label>
      <input id="pm-file" type="file" name="file_image" accept=".png,.jpg,.jpeg,.webp" required>
      <p><button type="submit"><?= e(t('pfiles.upload_screenshot')) ?></button></p>
    </form>

    <div class="card">
      <h3><?= e(t('pfiles.screenshots')) ?></h3>
      <?php foreach ($media as $m): ?>
        <div class="row">
          <span>assets/images/<?= e(basename($m['path'])) ?></span>
          <form method="post" style="display:inline;background:none;box-shadow:none;padding:0;margin:0" onsubmit="return confirm('<?= e(t('pfiles.delete_screenshot')) ?>')">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="media_delete"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
            <button class="danger" type="submit"><?= e(t('common.delete')) ?></button>
          </form>
        </div>
      <?php endforeach; ?>
      <?php if (!$media): ?><p class="muted"><?= e(t('pfiles.no_screenshots')) ?></p><?php endif; ?>
    </div>
    <?php endif; ?>

  <?php else: ?>
    <?php $spec = TYPES[$tab]; ?>
    <?php
      $rows = $pdo->query("SELECT * FROM $tab ORDER BY id DESC")->fetchAll();
    ?>
    <h2><?= e(t('type.' . $tab)) ?></h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="type" value="<?= e($tab) ?>">
      <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <h3><?= $edit ? e(t('item.edit')) : e(t('item.add')) ?></h3>
      <?php foreach ($spec['fields'] as $field): ?>
        <label for="f-<?= $field ?>"><?= e(label($field)) ?></label>
        <?php if (in_array($field, $spec['long'], true)): ?>
          <textarea id="f-<?= $field ?>" name="<?= $field ?>"><?= e($edit[$field] ?? '') ?></textarea>
        <?php else: ?>
          <input id="f-<?= $field ?>" name="<?= $field ?>"<?= ltr_attr($field) ?> value="<?= e((string) ($edit[$field] ?? '')) ?>">
        <?php endif; ?>
      <?php endforeach; ?>
      <?php foreach ($spec['files'] as $ext): ?>
        <label for="f-<?= $ext ?>"><?= e(t('common.file_of', ['ext' => strtoupper($ext)])) ?></label>
        <input id="f-<?= $ext ?>" type="file" name="file_<?= $ext ?>" accept=".<?= $ext ?>">
        <p class="muted"><?= e(t('common.current')) ?>: <?= !empty($edit[$ext]) ? e($edit[$ext]) : e(t('common.none')) ?></p>
      <?php endforeach; ?>
      <?php if ($tab === 'timeline'): ?>
        <label for="f-logo"><?= e(t('item.logo')) ?></label>
        <input id="f-logo" type="file" name="file_logo" accept=".png,.webp,.jpg,.jpeg">
        <p class="muted"><?= e(t('common.current')) ?>: <?= !empty($edit['logo']) ? e($edit['logo']) : e(t('common.none')) ?></p>
      <?php endif; ?>
      <?php if ($tab === 'projects'): ?>
        <label for="f-icon"><?= e(t('item.icon')) ?></label>
        <input id="f-icon" type="file" name="file_icon" accept=".png,.webp,.jpg,.jpeg">
        <p class="muted"><?= e(t('common.current')) ?>: <?= !empty($edit['icon']) ? e($edit['icon']) : e(t('common.generated_tile')) ?></p>
      <?php endif; ?>
      <p><button type="submit"><?= e(t('common.save')) ?></button>
        <?php if ($edit): ?><a href="index.php?t=<?= $tab ?>"><?= e(t('common.cancel')) ?></a><?php endif; ?></p>
    </form>

    <div class="card">
      <?php foreach ($rows as $row): ?>
        <div class="row">
          <span><?= e($row['title']) ?> <span class="muted"><?= e((string) ($row['year'] ?? $row['kind'] ?? '')) ?></span></span>
          <span>
            <a href="index.php?t=<?= $tab ?>&id=<?= (int) $row['id'] ?>"><?= e(t('common.edit')) ?></a>
            <form method="post" style="display:inline;background:none;box-shadow:none;padding:0;margin:0" onsubmit="return confirm('<?= e(t('common.confirm_delete')) ?>')">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="type" value="<?= e($tab) ?>">
              <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
              <button class="danger" type="submit"><?= e(t('common.delete')) ?></button>
            </form>
          </span>
        </div>
      <?php endforeach; ?>
      <?php if (!$rows): ?><p class="muted"><?= e(t('common.nothing_yet')) ?></p><?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>

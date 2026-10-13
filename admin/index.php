<?php
declare(strict_types=1);
// Admin session: not readable from scripts, not sent cross-site, and sent only over HTTPS when the site uses it
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Strict']);
session_start();
require __DIR__ . '/../db.php';
require __DIR__ . '/../theme.php';

if (!is_file(__DIR__ . '/../config.php')) {
    exit('Create config.php from config.sample.php on the server first.');
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
        throw new RuntimeException('The change could not be saved. Check that the data folder is writable.');
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
    'timeline' => ['label' => 'Education & experience', 'fields' => ['kind', 'title', 'title_fa', 'org', 'org_fa', 'period', 'period_fa', 'details', 'details_fa', 'sort'], 'long' => ['details', 'details_fa'], 'files' => []],
];
const PROFILE_FIELDS = ['name', 'name_fa', 'headline', 'headline_fa', 'about', 'about_fa', 'email', 'phone', 'linkedin', 'github', 'meta_description', 'footer_text'];
const PROFILE_LONG = ['about', 'about_fa', 'meta_description'];
// Sections of the public page that can be hidden; stored as '1' (shown) or '0' (hidden)
const SECTION_SWITCHES = [
    'show_journey' => 'Education & experience',
    'show_projects' => 'Projects',
    'show_research' => 'Research',
    'show_slides' => 'Slides',
];

/** Human label for a field; Persian fields are marked so the admin knows where to type them. */
function label(string $field): string {
    if (str_ends_with($field, '_fa')) {
        return ucfirst(str_replace('_', ' ', substr($field, 0, -3))) . ' (Persian)';
    }
    return ucfirst(str_replace('_', ' ', $field));
}

$error = null;
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'login') {
            csrf_check();
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            if (login_blocked($ip)) {
                throw new RuntimeException('Too many wrong passwords. Try again in 15 minutes.');
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
            $error = 'Wrong username or password.';
        } elseif (empty($_SESSION['auth'])) {
            http_response_code(403);
            exit('Not logged in.');
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
                    throw new RuntimeException('Too many wrong passwords. Try again in 15 minutes.');
                }
                $new = (string) ($_POST['new'] ?? '');
                $account = admin_account($config);
                if (!password_verify((string) ($_POST['current'] ?? ''), $account['admin_hash'])) {
                    login_failed($ip);
                    throw new RuntimeException('The current password is wrong.');
                }
                if (strlen($new) < 12) {
                    throw new RuntimeException('The new password must be at least 12 characters.');
                }
                if (!hash_equals($new, (string) ($_POST['repeat'] ?? ''))) {
                    throw new RuntimeException('The two new passwords do not match.');
                }
                save_admin_account($account['username'], password_hash($new, PASSWORD_DEFAULT));
                login_succeeded($ip);
                session_regenerate_id(true);
                header('Location: index.php?t=password&changed=1');
                exit;
            }

            if ($action === 'theme_draft' || $action === 'theme_publish') {
                $settings = theme_clean($_POST);
                if ($action === 'theme_draft') {
                    // The preview reads the draft through a random token; nothing changes on the public site yet
                    $token = bin2hex(random_bytes(16));
                    $body = json_encode(['token' => $token, 'created' => time(), 'settings' => $settings], JSON_UNESCAPED_UNICODE);
                    if (file_put_contents(THEME_DRAFT_FILE . '.tmp', $body, LOCK_EX) === false || !rename(THEME_DRAFT_FILE . '.tmp', THEME_DRAFT_FILE)) {
                        throw new RuntimeException('The draft could not be saved. Check that the data folder is writable.');
                    }
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['token' => $token, 'warnings' => theme_warnings($settings)], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                foreach ($settings as $key => $value) {
                    $pdo->prepare('INSERT OR REPLACE INTO profile (k, v) VALUES (?, ?)')->execute([$key, $value]);
                }
                @unlink(THEME_DRAFT_FILE);
                header('Location: index.php?t=theme&published=1');
                exit;
            }

            if ($action === 'theme_reset') {
                $pdo->exec("DELETE FROM profile WHERE k LIKE 'theme_%'");
                @unlink(THEME_DRAFT_FILE);
                header('Location: index.php?t=theme&reset=1');
                exit;
            }

            if ($action === 'username') {
                $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
                if (login_blocked($ip)) {
                    throw new RuntimeException('Too many wrong passwords. Try again in 15 minutes.');
                }
                $account = admin_account($config);
                if (!password_verify((string) ($_POST['current'] ?? ''), $account['admin_hash'])) {
                    login_failed($ip);
                    throw new RuntimeException('The current password is wrong.');
                }
                $username = strtolower(trim((string) ($_POST['username'] ?? '')));
                if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) {
                    throw new RuntimeException('The username must be 3 to 40 characters: letters, digits, dot, dash or underscore.');
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
                $notice = 'Profile saved.';
            }

            if ($action === 'save') {
                $type = $_POST['type'] ?? '';
                if (!isset(TYPES[$type])) {
                    throw new RuntimeException('Unknown section.');
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
                $notice = 'Saved.';
                header('Location: index.php?t=' . $type . '&saved=1');
                exit;
            }

            if ($action === 'folder_create') {
                $name = clean_name((string) ($_POST['name'] ?? ''));
                $parent = ($_POST['parent_id'] ?? '') === '' ? null : (int) $_POST['parent_id'];
                if ($parent !== null && !$pdo->query("SELECT 1 FROM folders WHERE id = $parent")->fetchColumn()) {
                    throw new RuntimeException('Parent folder not found.');
                }
                $pdo->prepare('INSERT INTO folders (parent_id, name, sort) VALUES (?, ?, 0)')->execute([$parent, $name]);
                header('Location: index.php?t=files&saved=1');
                exit;
            }

            if ($action === 'file_upload') {
                $folder = ($_POST['folder_id'] ?? '') === '' ? null : (int) $_POST['folder_id'];
                if ($folder !== null && !$pdo->query("SELECT 1 FROM folders WHERE id = $folder")->fetchColumn()) {
                    throw new RuntimeException('Folder not found.');
                }
                $saved = save_file_upload('file_upload');
                if ($saved === null) {
                    throw new RuntimeException('Choose a file to upload.');
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
                    throw new RuntimeException('Unknown item.');
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
                    throw new RuntimeException('Project not found.');
                }
                if (!valid_project_path($path)) {
                    throw new RuntimeException('Invalid path. Use letters, numbers, dots, dashes, underscores and slashes.');
                }
                if (strlen($content) > 512 * 1024) {
                    throw new RuntimeException('Content is larger than 512 KB.');
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
                    throw new RuntimeException('Project not found.');
                }
                $saved = save_file_upload('file_image', ['png', 'jpg', 'jpeg', 'webp']);
                if ($saved === null) {
                    throw new RuntimeException('Choose an image to upload.');
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
                    throw new RuntimeException('Folder is not empty. Delete or move its contents first.');
                }
                $pdo->prepare('DELETE FROM folders WHERE id = ?')->execute([$id]);
                header('Location: index.php?t=files');
                exit;
            }

            if ($action === 'delete') {
                $type = $_POST['type'] ?? '';
                if (!isset(TYPES[$type])) {
                    throw new RuntimeException('Unknown section.');
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
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Admin</title>
<style>
  body { font-family: system-ui, sans-serif; margin: 0; background: #eef4f8; color: #0f2a3d; }
  main { max-width: 860px; margin: 0 auto; padding: 16px; }
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
  .frame-wrap { max-width: 100%; overflow: auto; }
  .split { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; align-items: start; }
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
  @media (max-width: 760px) { .split { grid-template-columns: 1fr; } .preview { position: static; } }
</style>
</head>
<body>
<main>
<?php if (!$loggedIn): ?>
  <h1>Admin login</h1>
  <?php if ($error): ?><div class="msg err"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="login">
    <label for="user">Username</label>
    <input id="user" name="username" autocomplete="username" required autofocus>
    <label for="pw">Password</label>
    <input id="pw" type="password" name="password" autocomplete="current-password" required>
    <p><button type="submit">Log in</button></p>
  </form>
<?php else: ?>
  <nav>
    <a href="index.php?t=profile" class="<?= $tab === 'profile' ? 'on' : '' ?>">Profile & CV</a>
    <a href="index.php?t=files" class="<?= $tab === 'files' ? 'on' : '' ?>">Files</a>
    <a href="index.php?t=pfiles" class="<?= $tab === 'pfiles' ? 'on' : '' ?>">Project files</a>
    <?php foreach (TYPES as $key => $spec): ?>
      <a href="index.php?t=<?= $key ?>" class="<?= $tab === $key ? 'on' : '' ?>"><?= e($spec['label']) ?></a>
    <?php endforeach; ?>
    <a href="index.php?t=theme" class="<?= $tab === 'theme' ? 'on' : '' ?>">Theme</a>
    <a href="index.php?t=password" class="<?= $tab === 'password' ? 'on' : '' ?>">Password</a>
    <a href="../index.php" target="_blank">View site</a>
    <form method="post" style="margin:0;padding:0;background:none;box-shadow:none;margin-left:auto">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button name="action" value="logout" type="submit">Log out</button>
    </form>
  </nav>

  <?php if ($error): ?><div class="msg err"><?= e($error) ?></div><?php endif; ?>
  <?php if ($notice || isset($_GET['saved'])): ?><div class="msg">Saved.</div><?php endif; ?>

  <?php if ($tab === 'theme'): ?>
    <?php $current = theme_published($pdo); ?>
    <?php if (isset($_GET['published'])): ?><div class="msg">Theme published.</div><?php endif; ?>
    <?php if (isset($_GET['reset'])): ?><div class="msg">Theme reset to the default.</div><?php endif; ?>
    <div class="split">
    <form id="theme-form" method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="theme_publish">
      <h2>Theme</h2>
      <label for="t-preset">Colour preset</label>
      <select id="t-preset" name="theme_preset">
        <?php foreach (THEME_PRESETS as $key => $preset): ?>
          <option value="<?= e($key) ?>" <?= $current['theme_preset'] === $key ? 'selected' : '' ?>><?= e($preset['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="swatches" aria-hidden="true">
        <?php foreach (THEME_PRESETS as $key => $preset): if ($key === 'custom') continue; ?>
          <span title="<?= e($preset['label']) ?>" style="background:linear-gradient(135deg,<?= e($preset['light']) ?>,<?= e($preset['accent']) ?>)"></span>
        <?php endforeach; ?>
      </div>
      <label for="t-primary">Custom primary colour (used with "Custom colours")</label>
      <input id="t-primary" type="color" name="theme_primary" value="<?= e(strtolower($current['theme_primary'])) ?>">
      <label for="t-accent">Custom accent colour</label>
      <input id="t-accent" type="color" name="theme_accent" value="<?= e(strtolower($current['theme_accent'])) ?>">
      <label for="t-radius">Corner radius</label>
      <select id="t-radius" name="theme_radius">
        <?php foreach (['sharp' => 'Sharp', 'soft' => 'Soft (current)', 'round' => 'Round'] as $key => $label): ?>
          <option value="<?= $key ?>" <?= $current['theme_radius'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="t-density">Spacing between sections</label>
      <select id="t-density" name="theme_density">
        <?php foreach (['compact' => 'Compact', 'comfortable' => 'Comfortable (current)', 'airy' => 'Airy'] as $key => $label): ?>
          <option value="<?= $key ?>" <?= $current['theme_density'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="t-mode">Default mode for new visitors</label>
      <select id="t-mode" name="theme_mode">
        <?php foreach (['system' => 'Follow the visitor\'s system', 'light' => 'Light', 'dark' => 'Dark'] as $key => $label): ?>
          <option value="<?= $key ?>" <?= $current['theme_mode'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <label class="check"><input type="checkbox" name="theme_motion" value="on" <?= $current['theme_motion'] === 'on' ? 'checked' : '' ?>> Animations on</label>
      <div id="t-warnings" class="msg err" hidden></div>
      <p><button type="submit">Publish theme</button></p>
    </form>
    <aside class="preview">
      <h3>Live preview</h3>
      <p>
        <button type="button" data-width="100%">Desktop</button>
        <button type="button" data-width="390px">390px phone</button>
        <a class="button" id="t-open" href="../index.php" target="_blank">Open preview in a new tab</a>
      </p>
      <div class="frame-wrap"><iframe id="t-frame" title="Site preview" src="../index.php?preview=none" style="width:100%;height:560px;border:1px solid #d6e4ec;border-radius:10px;background:#fff"></iframe></div>
      <p class="muted">The preview shows your changes before they are published. Publish only when you are happy with it.</p>
      <form method="post" onsubmit="return confirm('Reset the theme to the default? Your draft is discarded.')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="theme_reset">
        <button class="danger" type="submit">Reset to default</button>
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
        let timer = null;
        const update = () => {
          const body = new FormData(form);
          body.set('action', 'theme_draft');
          body.set('theme_motion', form.theme_motion.checked ? 'on' : 'off');
          fetch('index.php?t=theme', { method: 'POST', body, headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
            .then((r) => r.json())
            .then((data) => {
              frame.src = '../index.php?preview=' + data.token;
              open.href = '../index.php?preview=' + data.token;
              warnings.hidden = data.warnings.length === 0;
              warnings.textContent = data.warnings.join(' ');
            })
            .catch(() => { warnings.hidden = false; warnings.textContent = 'The preview could not be updated. Try again.'; });
        };
        form.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(update, 350); });
        form.addEventListener('change', () => { clearTimeout(timer); timer = setTimeout(update, 0); });
        document.querySelectorAll('[data-width]').forEach((btn) => btn.addEventListener('click', () => { frame.style.width = btn.dataset.width; }));
        update();
      })();
    </script>
  <?php elseif ($tab === 'password'): ?>
    <?php if (isset($_GET['changed'])): ?><div class="msg">Password changed. Use the new password next time you log in.</div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="password">
      <h2>Change password</h2>
      <label for="pw-current">Current password</label>
      <input id="pw-current" type="password" name="current" autocomplete="current-password" required>
      <label for="pw-new">New password (at least 12 characters)</label>
      <input id="pw-new" type="password" name="new" autocomplete="new-password" minlength="12" required>
      <label for="pw-repeat">Repeat the new password</label>
      <input id="pw-repeat" type="password" name="repeat" autocomplete="new-password" minlength="12" required>
      <p><button type="submit">Change password</button></p>
    </form>
    <?php if (isset($_GET['renamed'])): ?><div class="msg">Username changed. Use it next time you log in.</div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="username">
      <h2>Change username</h2>
      <p class="muted">Current username: <strong><?= e(admin_account($config)['username']) ?></strong></p>
      <label for="us-new">New username (3 to 40 characters: letters, digits, dot, dash, underscore)</label>
      <input id="us-new" name="username" pattern="[A-Za-z0-9._-]{3,40}" required>
      <label for="us-current">Current password</label>
      <input id="us-current" type="password" name="current" autocomplete="current-password" required>
      <p><button type="submit">Change username</button></p>
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
          <input id="p-<?= $field ?>" name="<?= $field ?>" value="<?= e($profile[$field] ?? '') ?>">
        <?php endif; ?>
      <?php endforeach; ?>
      <fieldset>
        <legend>Visible sections on the site</legend>
        <?php foreach (SECTION_SWITCHES as $switch => $title): ?>
          <label class="check"><input type="checkbox" name="<?= $switch ?>" <?= ($profile[$switch] ?? '1') !== '0' ? 'checked' : '' ?>> <?= e($title) ?></label>
        <?php endforeach; ?>
      </fieldset>
      <label for="cv">CV (PDF)</label>
      <input id="cv" type="file" name="file_cv" accept="application/pdf,.pdf">
      <p class="muted">Current CV: <?= !empty($profile['cv']) ? '<a href="../' . e($profile['cv']) . '" target="_blank">open</a>' : 'none' ?></p>
      <label for="photo">Hero photo (PNG or WebP with a transparent background)</label>
      <input id="photo" type="file" name="file_photo" accept="image/png,image/webp,.png,.webp">
      <p class="muted">Current hero photo: <?= !empty($profile['hero_photo']) ? '<a href="../' . e($profile['hero_photo']) . '" target="_blank">open</a>' : 'built-in cutout' ?></p>
      <p><button type="submit">Save profile</button> <a class="button" href="../index.php" target="_blank">Preview site (saved version)</a></p>
    </form>
    <aside class="preview" aria-label="Live preview">
      <h3>Live preview</h3>
      <p class="muted">Updates while you type. Nothing is saved until you press Save.</p>
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
          file.textContent = f.name + ' · ' + Math.round(f.size / 1024) + ' KB (PNG or WebP, transparent background)';
        });
        const cv = document.getElementById('cv');
        if (cv) cv.addEventListener('change', () => {
          const f = cv.files[0];
          file.textContent = f ? 'CV: ' + f.name + ' · ' + Math.round(f.size / 1024) + ' KB' : '';
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
    <h2>Files</h2>
    <p class="muted">These appear as the Project file tree on the public site. Allowed: pdf, pptx, docx, zip, png, jpg, webp (100 MB max). A PDF preview can be attached to a PPTX or DOCX so visitors can read it in the browser.</p>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="folder_create">
      <h3>New folder</h3>
      <label for="fc-name">Name</label>
      <input id="fc-name" name="name" required maxlength="120">
      <label for="fc-parent">Inside</label>
      <select id="fc-parent" name="parent_id">
        <option value="">Project root</option>
        <?php foreach ($folders as $f): ?><option value="<?= (int) $f['id'] ?>"><?= e($paths[(int) $f['id']]) ?></option><?php endforeach; ?>
      </select>
      <p><button type="submit">Create folder</button></p>
    </form>

    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="file_upload">
      <h3>Upload file</h3>
      <label for="fu-folder">Folder</label>
      <select id="fu-folder" name="folder_id">
        <option value="">Project root</option>
        <?php foreach ($folders as $f): ?><option value="<?= (int) $f['id'] ?>"><?= e($paths[(int) $f['id']]) ?></option><?php endforeach; ?>
      </select>
      <label for="fu-file">File</label>
      <input id="fu-file" type="file" name="file_upload" required>
      <label for="fu-preview">Optional PDF preview (for PPTX or DOCX)</label>
      <input id="fu-preview" type="file" name="file_preview" accept=".pdf">
      <p><button type="submit">Upload</button></p>
    </form>

    <div class="card">
      <h3>Folders</h3>
      <?php foreach ($folders as $f): ?>
        <div class="row">
          <span><?= e($paths[(int) $f['id']]) ?>/</span>
          <span>
            <form method="post" style="display:inline;background:none;box-shadow:none;padding:0;margin:0">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="rename"><input type="hidden" name="kind" value="folder"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
              <input name="name" value="<?= e($f['name']) ?>" maxlength="120" aria-label="Folder name" style="width:160px;display:inline-block">
              <button type="submit">Rename</button>
            </form>
            <form method="post" style="display:inline;background:none;box-shadow:none;padding:0;margin:0" onsubmit="return confirm('Delete this folder?')">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete_folder"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
              <button class="danger" type="submit">Delete</button>
            </form>
          </span>
        </div>
      <?php endforeach; ?>
      <?php if (!$folders): ?><p class="muted">No folders yet.</p><?php endif; ?>
    </div>

    <div class="card">
      <h3>Files</h3>
      <?php foreach ($files as $fl): ?>
        <div class="row">
          <span>
            <?= e($fl['folder_id'] ? ($paths[(int) $fl['folder_id']] . ' / ') : '') ?><?= e($fl['name']) ?>
            <span class="muted"><?= e(strtoupper($fl['ext'])) ?><?= $fl['preview'] ? ' + preview' : '' ?></span>
          </span>
          <span>
            <form method="post" style="display:inline;background:none;box-shadow:none;padding:0;margin:0">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="rename"><input type="hidden" name="kind" value="file"><input type="hidden" name="id" value="<?= (int) $fl['id'] ?>">
              <input name="name" value="<?= e($fl['name']) ?>" maxlength="120" aria-label="File name" style="width:160px;display:inline-block">
              <button type="submit">Rename</button>
            </form>
            <a href="../<?= e($fl['path']) ?>" target="_blank">open</a>
            <form method="post" style="display:inline;background:none;box-shadow:none;padding:0;margin:0" onsubmit="return confirm('Delete this file?')">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete_file"><input type="hidden" name="id" value="<?= (int) $fl['id'] ?>">
              <button class="danger" type="submit">Delete</button>
            </form>
          </span>
        </div>
      <?php endforeach; ?>
      <?php if (!$files): ?><p class="muted">No files yet.</p><?php endif; ?>
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
    <h2>Project files</h2>
    <p class="muted">Each project shows a Flutter project tree. Its README, pubspec, links and placeholder code are generated from the project fields (title, summary, tags as tech stack, url). Paste real file contents here to replace a generated file or add one. Screenshots go into assets/images/.</p>

    <form method="get">
      <input type="hidden" name="t" value="pfiles">
      <label for="pf-project">Project</label>
      <select id="pf-project" name="project">
        <?php foreach ($projectList as $pr): ?>
          <option value="<?= (int) $pr['id'] ?>" <?= (int) $pr['id'] === $pid ? 'selected' : '' ?>><?= e($pr['title']) ?></option>
        <?php endforeach; ?>
      </select>
      <p><button type="submit">Show</button></p>
    </form>

    <?php if ($pid > 0): ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="pf_save">
      <input type="hidden" name="project_id" value="<?= $pid ?>">
      <h3>Set file contents</h3>
      <label for="pf-path">Path inside the project (shown under lib/projects/&lt;name&gt;/)</label>
      <input id="pf-path" name="path" list="pf-paths" required maxlength="200" placeholder="lib/screens/home.dart">
      <datalist id="pf-paths"><?php foreach ($templatePaths as $tp): ?><option value="<?= e($tp) ?>"><?php endforeach; ?></datalist>
      <label for="pf-content">Contents (leave empty for an empty file)</label>
      <textarea id="pf-content" name="content" rows="14" style="font-family:ui-monospace,Menlo,monospace"></textarea>
      <p><button type="submit">Save file</button></p>
    </form>

    <div class="card">
      <h3>Saved file contents</h3>
      <?php foreach ($overrides as $ov): ?>
        <div class="row">
          <span><?= e($ov['path']) ?> <span class="muted"><?= (int) $ov['size'] ?> bytes</span></span>
          <form method="post" style="display:inline;background:none;box-shadow:none;padding:0;margin:0" onsubmit="return confirm('Remove this saved file? The generated version will show again.')">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="pf_delete"><input type="hidden" name="id" value="<?= (int) $ov['id'] ?>">
            <button class="danger" type="submit">Remove</button>
          </form>
        </div>
      <?php endforeach; ?>
      <?php if (!$overrides): ?><p class="muted">No saved contents. The generated overview is shown.</p><?php endif; ?>
    </div>

    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="media_upload">
      <input type="hidden" name="project_id" value="<?= $pid ?>">
      <h3>Add a screenshot</h3>
      <label for="pm-file">Image (png, jpg, webp)</label>
      <input id="pm-file" type="file" name="file_image" accept=".png,.jpg,.jpeg,.webp" required>
      <p><button type="submit">Upload screenshot</button></p>
    </form>

    <div class="card">
      <h3>Screenshots</h3>
      <?php foreach ($media as $m): ?>
        <div class="row">
          <span>assets/images/<?= e(basename($m['path'])) ?></span>
          <form method="post" style="display:inline;background:none;box-shadow:none;padding:0;margin:0" onsubmit="return confirm('Delete this screenshot?')">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="media_delete"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
            <button class="danger" type="submit">Delete</button>
          </form>
        </div>
      <?php endforeach; ?>
      <?php if (!$media): ?><p class="muted">No screenshots yet.</p><?php endif; ?>
    </div>
    <?php endif; ?>

  <?php else: ?>
    <?php $spec = TYPES[$tab]; ?>
    <?php
      $rows = $pdo->query("SELECT * FROM $tab ORDER BY id DESC")->fetchAll();
    ?>
    <h2><?= e($spec['label']) ?></h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="type" value="<?= e($tab) ?>">
      <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <h3><?= $edit ? 'Edit item' : 'Add item' ?></h3>
      <?php foreach ($spec['fields'] as $field): ?>
        <label for="f-<?= $field ?>"><?= e(label($field)) ?></label>
        <?php if (in_array($field, $spec['long'], true)): ?>
          <textarea id="f-<?= $field ?>" name="<?= $field ?>"><?= e($edit[$field] ?? '') ?></textarea>
        <?php else: ?>
          <input id="f-<?= $field ?>" name="<?= $field ?>" value="<?= e((string) ($edit[$field] ?? '')) ?>">
        <?php endif; ?>
      <?php endforeach; ?>
      <?php foreach ($spec['files'] as $ext): ?>
        <label for="f-<?= $ext ?>"><?= strtoupper($ext) ?> file</label>
        <input id="f-<?= $ext ?>" type="file" name="file_<?= $ext ?>" accept=".<?= $ext ?>">
        <p class="muted">Current: <?= !empty($edit[$ext]) ? e($edit[$ext]) : 'none' ?></p>
      <?php endforeach; ?>
      <?php if ($tab === 'projects'): ?>
        <label for="f-icon">Icon (PNG, WebP or JPG, optional)</label>
        <input id="f-icon" type="file" name="file_icon" accept=".png,.webp,.jpg,.jpeg">
        <p class="muted">Current: <?= !empty($edit['icon']) ? e($edit['icon']) : 'generated tile' ?></p>
      <?php endif; ?>
      <p><button type="submit">Save</button>
        <?php if ($edit): ?><a href="index.php?t=<?= $tab ?>">Cancel</a><?php endif; ?></p>
    </form>

    <div class="card">
      <?php foreach ($rows as $row): ?>
        <div class="row">
          <span><?= e($row['title']) ?> <span class="muted"><?= e((string) ($row['year'] ?? $row['kind'] ?? '')) ?></span></span>
          <span>
            <a href="index.php?t=<?= $tab ?>&id=<?= (int) $row['id'] ?>">Edit</a>
            <form method="post" style="display:inline;background:none;box-shadow:none;padding:0;margin:0" onsubmit="return confirm('Delete this item?')">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="type" value="<?= e($tab) ?>">
              <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
              <button class="danger" type="submit">Delete</button>
            </form>
          </span>
        </div>
      <?php endforeach; ?>
      <?php if (!$rows): ?><p class="muted">Nothing here yet.</p><?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>

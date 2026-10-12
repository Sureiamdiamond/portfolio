<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../db.php';

if (!is_file(__DIR__ . '/../config.php')) {
    exit('Create config.php from config.sample.php on the server first.');
}
$config = require __DIR__ . '/../config.php';
$pdo = db();

const TYPES = [
    'projects' => ['label' => 'Projects', 'fields' => ['title', 'title_fa', 'kind', 'summary', 'summary_fa', 'tags', 'url', 'sort'], 'long' => ['summary', 'summary_fa'], 'files' => []],
    'papers' => ['label' => 'Papers', 'fields' => ['title', 'title_fa', 'venue', 'year', 'doi', 'sort'], 'long' => [], 'files' => ['pdf']],
    'presentations' => ['label' => 'Presentations', 'fields' => ['title', 'title_fa', 'event', 'year', 'sort'], 'long' => [], 'files' => ['pptx', 'pdf']],
    'timeline' => ['label' => 'Education & experience', 'fields' => ['kind', 'title', 'title_fa', 'org', 'org_fa', 'period', 'period_fa', 'details', 'details_fa', 'sort'], 'long' => ['details', 'details_fa'], 'files' => []],
];
const PROFILE_FIELDS = ['name', 'name_fa', 'headline', 'headline_fa', 'about', 'about_fa', 'email', 'phone', 'linkedin', 'github'];
const PROFILE_LONG = ['about', 'about_fa'];

/** Human label for a field; Persian fields are marked so the admin knows where to type them. */
function label(string $field): string {
    if (str_ends_with($field, '_fa')) {
        return ucfirst(substr($field, 0, -3)) . ' (Persian)';
    }
    return ucfirst($field);
}

$error = null;
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'login') {
            usleep(500000); // slows down password guessing
            if (password_verify($_POST['password'] ?? '', $config['admin_hash'])) {
                session_regenerate_id(true);
                $_SESSION['auth'] = true;
                header('Location: index.php');
                exit;
            }
            $error = 'Wrong password.';
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

            if ($action === 'profile') {
                foreach (PROFILE_FIELDS as $field) {
                    $stmt = $pdo->prepare('INSERT OR REPLACE INTO profile (k, v) VALUES (?, ?)');
                    $stmt->execute([$field, trim($_POST[$field] ?? '')]);
                }
                if ($cv = save_upload('file_cv', 'pdf')) {
                    $old = $pdo->query("SELECT v FROM profile WHERE k = 'cv'")->fetchColumn();
                    remove_upload($old ?: null);
                    $pdo->prepare("INSERT OR REPLACE INTO profile (k, v) VALUES ('cv', ?)")->execute([$cv]);
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
</style>
</head>
<body>
<main>
<?php if (!$loggedIn): ?>
  <h1>Admin login</h1>
  <?php if ($error): ?><div class="msg err"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="action" value="login">
    <label for="pw">Password</label>
    <input id="pw" type="password" name="password" required autofocus>
    <p><button type="submit">Log in</button></p>
  </form>
<?php else: ?>
  <nav>
    <a href="index.php?t=profile" class="<?= $tab === 'profile' ? 'on' : '' ?>">Profile & CV</a>
    <?php foreach (TYPES as $key => $spec): ?>
      <a href="index.php?t=<?= $key ?>" class="<?= $tab === $key ? 'on' : '' ?>"><?= e($spec['label']) ?></a>
    <?php endforeach; ?>
    <a href="../index.php" target="_blank">View site</a>
    <form method="post" style="margin:0;padding:0;background:none;box-shadow:none;margin-left:auto">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button name="action" value="logout" type="submit">Log out</button>
    </form>
  </nav>

  <?php if ($error): ?><div class="msg err"><?= e($error) ?></div><?php endif; ?>
  <?php if ($notice || isset($_GET['saved'])): ?><div class="msg">Saved.</div><?php endif; ?>

  <?php if ($tab === 'profile'): ?>
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
      <label for="cv">CV (PDF)</label>
      <input id="cv" type="file" name="file_cv" accept="application/pdf,.pdf">
      <p class="muted">Current CV: <?= !empty($profile['cv']) ? '<a href="../' . e($profile['cv']) . '" target="_blank">open</a>' : 'none' ?></p>
      <p><button type="submit">Save profile</button></p>
    </form>
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

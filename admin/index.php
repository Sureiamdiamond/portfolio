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
    'projects' => ['label' => 'Projects', 'fields' => ['title', 'title_fa', 'kind', 'summary', 'summary_fa', 'tags', 'url', 'github_url', 'appstore_url', 'playstore_url', 'sort'], 'long' => ['summary', 'summary_fa'], 'files' => []],
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
    <a href="index.php?t=files" class="<?= $tab === 'files' ? 'on' : '' ?>">Files</a>
    <a href="index.php?t=pfiles" class="<?= $tab === 'pfiles' ? 'on' : '' ?>">Project files</a>
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

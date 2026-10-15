<?php
declare(strict_types=1);
// Admin-only diagnostics for project links: the stored values, what they look like with invisible characters shown, and what the card menu renders
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Strict']);
session_start();
require __DIR__ . '/../db.php';

if (empty($_SESSION['auth'])) {
    header('Location: index.php');
    exit;
}

/** Shows every invisible or control character as [U+XXXX], so a pasted value can be read exactly. */
function show_chars(string $value): string {
    return (string) preg_replace_callback('/[\p{Cf}\p{Zs}\p{Cc}]/u', static function (array $m): string {
        $code = mb_ord($m[0], 'UTF-8');
        return '[U+' . strtoupper(sprintf('%04X', $code)) . ']';
    }, $value);
}

/** Code points that are invisible or not plain ASCII, with their code, for the hex column. */
function hex_of(string $value): string {
    $out = [];
    foreach (mb_str_split($value, 1, 'UTF-8') as $ch) {
        $code = mb_ord($ch, 'UTF-8');
        if ($code < 0x20 || $code > 0x7E) {
            $out[] = sprintf('%04X', $code);
        }
    }
    return $out ? implode(' ', $out) : '-';
}

$pdo = db();
$projects = $pdo->query('SELECT * FROM projects ORDER BY sort, id')->fetchAll(PDO::FETCH_ASSOC);
$fields = ['url', 'github_url', 'appstore_url', 'playstore_url', 'bazaar_url'];
// The values that differ between the Tobank rows and the others are highlighted
$reference = [];
foreach ($projects as $p) {
    if (strtolower(trim((string) $p['title'])) === 'tobank sdui') {
        $reference = $p;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Project links (admin)</title>
<style>
  body { font: 14px/1.5 system-ui, sans-serif; margin: 0; padding: 16px; background: #eef4f8; color: #0f2a3d; }
  h1 { font-size: 20px; margin: 0 0 12px; }
  .card { background: #fff; border-radius: 12px; padding: 14px; margin-bottom: 14px; box-shadow: 0 2px 10px rgba(15,42,61,.08); overflow-x: auto; }
  table { border-collapse: collapse; width: 100%; font-size: 13px; }
  th, td { text-align: start; vertical-align: top; padding: 6px 8px; border-bottom: 1px solid #e3edf3; word-break: break-all; }
  th { width: 22%; color: #5b7385; font-weight: 600; }
  code, pre { font: 12px/1.4 ui-monospace, monospace; white-space: pre-wrap; }
  .diff { background: #fff4cc; }
  .bad { color: #a12b2b; font-weight: 600; }
  .ok { color: #1f7a3d; }
  a { color: #1a7fa8; }
</style>
</head>
<body>
<h1>Project links (admin only)</h1>
<p>Values are shown raw. Invisible characters appear as [U+XXXX]. Yellow marks a value that differs from the Tobank SDUI row.</p>
<?php foreach ($projects as $p): ?>
  <?php
    $title = (string) $p['title'];
    $isRef = $reference && (int) $reference['id'] === (int) $p['id'];
    $options = project_options($p, 'en');
    $extraRaw = (string) ($p['extra_links'] ?? '');
    $extra = json_decode($extraRaw, true);
  ?>
  <section class="card">
    <h2 style="font-size:16px;margin:0 0 8px"><?= e($title) ?> <span class="muted">(id <?= (int) $p['id'] ?>)</span></h2>
    <table>
      <?php foreach ($fields as $field): ?>
        <?php
          $raw = (string) ($p[$field] ?? '');
          $refRaw = (string) ($reference[$field] ?? '');
          $differs = $reference && !$isRef && $raw !== $refRaw;
          $clean = clean_link($raw);
          $valid = (bool) preg_match('#^https?://#i', $clean);
        ?>
        <tr class="<?= $differs ? 'diff' : '' ?>">
          <th><?= e($field) ?></th>
          <td>
            <div>stored (escaped): <code><?= e(json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') ?></code></div>
            <div>invisible shown: <code><?= e(show_chars($raw)) ?></code></div>
            <div>non-ASCII code points: <code><?= e(hex_of($raw)) ?></code></div>
            <div>after clean_link: <code><?= e($clean) ?></code> <span class="<?= $raw === '' ? 'muted' : ($valid ? 'ok' : 'bad') ?>"><?= $raw === '' ? 'empty' : ($valid ? 'valid' : 'NOT valid (site hides it)') ?></span></div>
          </td>
        </tr>
      <?php endforeach; ?>
      <tr class="<?= $reference && !$isRef && $extraRaw !== (string) ($reference['extra_links'] ?? '') ? 'diff' : '' ?>">
        <th>extra_links (raw)</th>
        <td>
          <div><code><?= e($extraRaw === '' ? '(empty)' : $extraRaw) ?></code></div>
          <div>invisible shown: <code><?= e(show_chars($extraRaw)) ?></code></div>
          <div>json: <?= is_array($extra) ? '<span class="ok">parsed, ' . count($extra) . ' item(s)</span>' : ($extraRaw === '' ? '-' : '<span class="bad">does not parse</span>') ?></div>
          <?php if (is_array($extra)): foreach ($extra as $i => $link): ?>
            <div>item <?= (int) $i ?>: keys <code><?= e(implode(', ', is_array($link) ? array_keys($link) : [])) ?></code>, url <code><?= e(is_array($link) ? show_chars((string) ($link['url'] ?? $link['link'] ?? $link['href'] ?? '')) : '') ?></code></div>
          <?php endforeach; endif; ?>
        </td>
      </tr>
      <tr>
        <th>card menu (as the site renders it, English)</th>
        <td>
          <?php if (!$options): ?>
            <span class="muted">no links</span>
          <?php else: foreach ($options as [$label, $url, $icon]): ?>
            <div><?= e($label) ?> → <code><?= e($url) ?></code> <span class="muted"><?= e($icon) ?></span></div>
          <?php endforeach; endif; ?>
        </td>
      </tr>
    </table>
  </section>
<?php endforeach; ?>
<p><a href="index.php?t=projects">Back to the admin</a></p>
</body>
</html>

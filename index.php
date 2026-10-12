<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/i18n.php';
require __DIR__ . '/project_tree.php';

$lang = current_lang();
$dir = $lang === 'fa' ? 'rtl' : 'ltr';
$pdo = db();
$profile = [];
foreach ($pdo->query('SELECT k, v FROM profile') as $row) {
    $profile[$row['k']] = $row['v'];
}
$projects = $pdo->query('SELECT * FROM projects ORDER BY sort, id DESC')->fetchAll();
$projectShots = [];
foreach ($pdo->query('SELECT project_id, path FROM project_media ORDER BY sort, id') as $shot) {
    $projectShots[$shot['project_id']][] = $shot['path'];
}
$papers = $pdo->query('SELECT * FROM papers ORDER BY year DESC, sort, id DESC')->fetchAll();
$slides = $pdo->query('SELECT * FROM presentations ORDER BY year DESC, sort, id DESC')->fetchAll();
$timeline = $pdo->query('SELECT * FROM timeline ORDER BY sort, id DESC')->fetchAll();
$education = array_values(array_filter($timeline, fn($t) => $t['kind'] === 'education'));
$experience = array_values(array_filter($timeline, fn($t) => $t['kind'] === 'experience'));

$name = loc($profile, 'name', $lang) ?: t('default_name', $lang);
// Hero photo uploaded in /admin (a transparent PNG or WebP); empty means the built-in cutout
$heroPhoto = (string) ($profile['hero_photo'] ?? '');
$headline = loc($profile, 'headline', $lang) ?: t('default_headline', $lang);
$about = loc($profile, 'about', $lang);
$switchLang = $lang === 'fa' ? 'en' : 'fa';

// Initials for the logo and hero card, e.g. "Parsa Banitaba" -> "PB"
$words = preg_split('/\s+/u', trim($name)) ?: [];
$initials = mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2))));
// The last word of the name gets the gradient highlight
$lastWord = count($words) > 1 ? array_pop($words) : '';
$firstWords = implode(' ', $words);

// Projects: the Project window tree and the viewer source for every file (rendered on the server)
$projectSources = [];
$projectTree = $projects ? render_project_window($projects, $pdo, $lang, $projectSources) : '';

// Content hash of the CSS and JS: changes whenever the files change, so caches can't serve an old build
$assetVersion = substr(md5((string) file_get_contents(__DIR__ . '/assets/style.css') . (string) file_get_contents(__DIR__ . '/assets/app.js')), 0, 8);


?>
<!doctype html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($name) ?> · <?= e(t('default_title', $lang)) ?></title>
<meta name="description" content="<?= e($headline) ?>">
<script>
  // Apply the saved theme before first paint to avoid a flash.
  try { var s = localStorage.getItem('theme'); if (s) document.documentElement.dataset.theme = s; } catch (e) {}
  // Projects view before first paint: #projects-ide wins, then the saved choice, then Normal.
  try {
    var pv = location.hash === '#projects-ide' ? 'ide' : localStorage.getItem('projects-view');
    document.documentElement.dataset.projectsView = pv === 'ide' ? 'ide' : 'normal';
  } catch (e) { document.documentElement.dataset.projectsView = 'normal'; }
</script>
<link rel="stylesheet" href="assets/style.css?v=<?= $assetVersion ?>">
</head>
<body>

<a class="skip" href="#main"><?= e($lang === 'fa' ? 'رفتن به محتوا' : 'Skip to content') ?></a>

<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="#top"><span class="brand-mark" aria-hidden="true"><?= e($initials) ?></span><span><?= e($name) ?></span></a>
    <nav class="nav-links" aria-label="<?= e(t('projects', $lang)) ?>">
      <a href="#journey"><?= e(t('journey', $lang)) ?></a>
      <a href="#projects"><?= e(t('projects', $lang)) ?></a>
      <a href="#papers"><?= e(t('papers', $lang)) ?></a>
      <a href="#slides"><?= e(t('presentations', $lang)) ?></a>
    </nav>
    <div class="nav-actions">
      <a class="tool" href="?lang=<?= $switchLang ?>" hreflang="<?= $switchLang ?>"><?= e(t('lang_switch', $lang)) ?></a>
      <button type="button" class="tool" id="theme-toggle" aria-label="<?= e(t('toggle_theme', $lang)) ?>">◐</button>
      <a class="btn btn-primary" href="#contact"><?= e(t('lets_chat', $lang)) ?></a>
    </div>
  </div>
</header>

<main id="main">
  <section id="top" class="hero">
    <div class="hero-text">
      <span class="pill"><span class="dot" aria-hidden="true"></span><?= e($headline) ?></span>
      <h1><?= e($firstWords) ?><?php if ($lastWord !== ''): ?> <span class="grad"><?= e($lastWord) ?></span><?php endif; ?></h1>
      <?php if ($about !== ''): ?><p class="lead"><?= nl2br(e($about)) ?></p><?php endif; ?>
      <div class="actions">
        <?php if (!empty($profile['cv'])): ?><a class="btn btn-primary" href="<?= e($profile['cv']) ?>" download><?= e(t('download_cv', $lang)) ?></a><?php endif; ?>
        <?php if (!empty($profile['email'])): ?><a class="btn btn-outline" href="mailto:<?= e($profile['email']) ?>"><?= e(t('email_me', $lang)) ?></a><?php endif; ?>
      </div>
    </div>
    <div class="hero-art" aria-hidden="true">
      <span class="ring"></span>
      <div class="portrait">
        <span class="portrait-name"><?= e($name) ?></span>
        <img class="portrait-img" src="<?= e($heroPhoto !== '' ? $heroPhoto : 'assets/img/portrait.png?v=' . $assetVersion) ?>" alt="<?= e($name) ?>" width="380" height="541" decoding="async">
      </div>
      <span class="chip chip-a"><?= count($projects) ?> <?= e(t('stat_projects', $lang)) ?></span>
      <span class="chip chip-b"><?= count($papers) ?> <?= e(t('stat_papers', $lang)) ?></span>
    </div>
  </section>

  <section id="journey" class="block reveal">
    <p class="label"><span>01</span><?= e(t('journey', $lang)) ?></p>
    <h2><?= e(t('journey', $lang)) ?></h2>
    <div class="timeline">
      <?php foreach ([['education', $education], ['experience', $experience]] as [$label, $items]): ?>
        <h3 class="sub"><?= e(t($label, $lang)) ?></h3>
        <?php foreach ($items as $item): ?>
          <div class="node">
            <span class="period"><?= e(loc($item, 'period', $lang)) ?></span>
            <strong><?= e(loc($item, 'title', $lang)) ?></strong>
            <?php if ($item['org'] || $item['org_fa']): ?><span class="muted"><?= e(loc($item, 'org', $lang)) ?></span><?php endif; ?>
            <?php if ($item['details'] || $item['details_fa']): ?><p><?= nl2br(e(loc($item, 'details', $lang))) ?></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  </section>

  <section id="projects" class="block reveal">
    <p class="label"><span>02</span><?= e(t('projects', $lang)) ?></p>
    <h2><?= e(t('projects', $lang)) ?></h2>
    <div class="view-switch" id="view-switch" role="radiogroup" aria-label="<?= e(t('view_label', $lang)) ?>">
      <button type="button" role="radio" class="on" data-view="normal" aria-checked="true" tabindex="0">
        <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2 2h5v5H2zM9 2h5v5H9zM2 9h5v5H2zM9 9h5v5H9z" fill="none" stroke="currentColor" stroke-width="1.4"/></svg>
        <span><?= e(t('view_normal', $lang)) ?></span>
      </button>
      <button type="button" role="radio" data-view="ide" aria-checked="false" tabindex="-1">
        <svg viewBox="0 0 16 16" aria-hidden="true"><rect x="1.5" y="2.5" width="13" height="11" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M5.5 2.5v11" stroke="currentColor" stroke-width="1.4"/></svg>
        <span><?= e(t('view_ide', $lang)) ?></span>
      </button>
    </div>

    <div class="filters" role="group" aria-label="<?= e(t('projects', $lang)) ?>">
      <button type="button" class="on" data-filter="all" aria-pressed="true"><?= e(t('filter_all', $lang)) ?></button>
      <button type="button" data-filter="work" aria-pressed="false"><?= e(t('filter_work', $lang)) ?></button>
      <button type="button" data-filter="academic" aria-pressed="false"><?= e(t('filter_academic', $lang)) ?></button>
    </div>

    <div class="pv-normal" id="view-normal">
      <div class="grid">
        <?php foreach ($projects as $p): $shots = $projectShots[$p['id']] ?? []; ?>
          <article class="card pcard" data-kind="<?= e($p['kind']) ?>">
            <?php if ($shots): ?>
              <figure class="pcard-shot"><img src="<?= e($shots[0]) ?>" alt="" loading="lazy"></figure>
            <?php endif; ?>
            <span class="tag"><?= e($p['kind']) ?></span>
            <h3><?= e(loc($p, 'title', $lang)) ?></h3>
            <p class="muted"><?= nl2br(e(loc($p, 'summary', $lang))) ?></p>
            <?php $tags = array_filter(array_map('trim', explode(',', (string) $p['tags']))); ?>
            <?php if ($tags): ?>
              <ul class="pcard-tags"><?php foreach ($tags as $tag): ?><li><?= e($tag) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <?php if (count($shots) > 1): ?>
              <ul class="pcard-shots"><?php foreach (array_slice($shots, 1) as $more): ?><li><img src="<?= e($more) ?>" alt="" loading="lazy"></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <?php if ($p['url']): ?><a href="<?= e($p['url']) ?>" target="_blank" rel="noopener"><?= e(t('view_project', $lang)) ?> →</a><?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
      <?php if (!$projects): ?><p class="muted"><?= e(t('empty_projects', $lang)) ?></p><?php endif; ?>
    </div>

    <div class="pt-window" data-pt dir="ltr">
      <div class="pt-titlebar" aria-hidden="true"><span class="dots"><i></i><i></i><i></i></span><span class="pt-title">ParsaBanitaba.ir — <?= e(t('projects', $lang)) ?></span></div>
      <div class="pt-main">
        <aside class="pt-tool" id="pt-tool">
          <div class="pt-tool-head">
            <span class="pt-tool-title"><?= e(t('files_project', $lang)) ?></span>
            <div class="pt-icons">
              <button type="button" class="pt-btn" data-act="expand" aria-label="<?= e(t('expand_all', $lang)) ?>" title="<?= e(t('expand_all', $lang)) ?>">⊞</button>
              <button type="button" class="pt-btn" data-act="collapse" aria-label="<?= e(t('collapse_all', $lang)) ?>" title="<?= e(t('collapse_all', $lang)) ?>">⊟</button>
              <button type="button" class="pt-btn pt-close" data-act="close-drawer" aria-label="Close">✕</button>
            </div>
          </div>
          <nav class="pt-tree-wrap" aria-label="<?= e(t('projects', $lang)) ?>">
            <?php if ($projectTree !== ''): ?>
              <ul class="tree" id="pt-tree"><?= $projectTree ?></ul>
            <?php else: ?>
              <p class="muted pt-empty-tree"><?= e(t('empty_projects', $lang)) ?></p>
            <?php endif; ?>
          </nav>
        </aside>
        <div class="pt-split" id="pt-split" role="separator" aria-orientation="vertical" aria-label="Resize panels" tabindex="0"></div>
        <div class="pt-editor">
          <div class="pt-tabs" id="pt-tabs" role="tablist"></div>
          <div class="pt-view" id="pt-view"><p class="pt-hint"><?= e(t('select_file', $lang)) ?></p></div>
          <div class="pt-status">
            <span id="pt-status-path"></span>
            <span class="pt-status-right"><span id="pt-status-lang"></span><span>Read-only</span></span>
          </div>
        </div>
      </div>
      <button type="button" class="pt-toggle" id="pt-toggle" aria-expanded="false" aria-controls="pt-tool"><?= e(t('files_project', $lang)) ?></button>
      <div hidden id="pt-sources"><?= implode('', $projectSources) ?></div>
    </div>
  </section>

  <section id="papers" class="block reveal">
    <p class="label"><span>03</span><?= e(t('papers', $lang)) ?></p>
    <h2><?= e(t('papers', $lang)) ?></h2>
    <?php foreach ($papers as $paper): ?>
      <article class="row">
        <div>
          <h3><?= e(loc($paper, 'title', $lang)) ?></h3>
          <p class="muted"><?= e(trim(($paper['venue'] ?? '') . ' ' . ($paper['year'] ?? ''))) ?></p>
        </div>
        <div class="links">
          <?php if ($paper['pdf']): ?><a href="<?= e($paper['pdf']) ?>" target="_blank" rel="noopener">PDF</a><?php endif; ?>
          <?php if ($paper['doi']): ?><a href="https://doi.org/<?= e($paper['doi']) ?>" target="_blank" rel="noopener">DOI</a><?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
    <?php if (!$papers): ?><p class="muted"><?= e(t('empty_papers', $lang)) ?></p><?php endif; ?>
  </section>

  <section id="slides" class="block reveal">
    <p class="label"><span>04</span><?= e(t('presentations', $lang)) ?></p>
    <h2><?= e(t('presentations', $lang)) ?></h2>
    <?php foreach ($slides as $s): ?>
      <details class="slide">
        <summary>
          <strong><?= e(loc($s, 'title', $lang)) ?></strong>
          <span class="muted"><?= e(trim(($s['event'] ?? '') . ' ' . ($s['year'] ?? ''))) ?></span>
        </summary>
        <?php if ($s['pdf']): ?>
          <iframe src="<?= e($s['pdf']) ?>#view=FitH" title="<?= e(loc($s, 'title', $lang)) ?>" loading="lazy"></iframe>
        <?php endif; ?>
        <div class="links">
          <?php if ($s['pdf']): ?><a href="<?= e($s['pdf']) ?>" target="_blank" rel="noopener"><?= e(t('open_pdf', $lang)) ?></a><?php endif; ?>
          <?php if ($s['pptx']): ?><a href="<?= e($s['pptx']) ?>" download><?= e(t('download_pptx', $lang)) ?></a><?php endif; ?>
        </div>
      </details>
    <?php endforeach; ?>
    <?php if (!$slides): ?><p class="muted"><?= e(t('empty_slides', $lang)) ?></p><?php endif; ?>
  </section>

  <section id="contact" class="inverted reveal">
    <dl class="stats">
      <div><dt><?= count($projects) ?></dt><dd><?= e(t('stat_projects', $lang)) ?></dd></div>
      <div><dt><?= count($papers) ?></dt><dd><?= e(t('stat_papers', $lang)) ?></dd></div>
      <div><dt><?= count($slides) ?></dt><dd><?= e(t('stat_presentations', $lang)) ?></dd></div>
    </dl>
    <div class="contact-grid">
      <div>
        <p class="label"><span>06</span><?= e(t('contact', $lang)) ?></p>
        <h2><?= e(t('contact', $lang)) ?></h2>
        <?php if (!empty($profile['email'])): ?><p><a href="mailto:<?= e($profile['email']) ?>"><?= e($profile['email']) ?></a></p><?php endif; ?>
        <?php if (!empty($profile['phone'])): ?>
          <p><span class="muted"><?= e(t('phone', $lang)) ?>:</span> <a href="tel:<?= e(preg_replace('/\s+/', '', $profile['phone'])) ?>"><?= e($profile['phone']) ?></a></p>
        <?php endif; ?>
      </div>
      <div class="links">
        <?php if (!empty($profile['linkedin'])): ?><a href="<?= e($profile['linkedin']) ?>" target="_blank" rel="noopener">LinkedIn</a><?php endif; ?>
        <?php if (!empty($profile['github'])): ?><a href="<?= e($profile['github']) ?>" target="_blank" rel="noopener">GitHub</a><?php endif; ?>
      </div>
    </div>
  </section>
</main>

<footer class="muted">
  © <?= date('Y') ?> <?= e($name) ?> · <span class="build">build <?= $assetVersion ?></span>
</footer>

<script src="assets/app.js?v=<?= $assetVersion ?>" defer></script>
</body>
</html>

<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/i18n.php';

$lang = current_lang();
$dir = $lang === 'fa' ? 'rtl' : 'ltr';
$pdo = db();
$profile = [];
foreach ($pdo->query('SELECT k, v FROM profile') as $row) {
    $profile[$row['k']] = $row['v'];
}
$projects = $pdo->query('SELECT * FROM projects ORDER BY sort, id DESC')->fetchAll();
$papers = $pdo->query('SELECT * FROM papers ORDER BY year DESC, sort, id DESC')->fetchAll();
$slides = $pdo->query('SELECT * FROM presentations ORDER BY year DESC, sort, id DESC')->fetchAll();
$timeline = $pdo->query('SELECT * FROM timeline ORDER BY sort, id DESC')->fetchAll();
$education = array_values(array_filter($timeline, fn($t) => $t['kind'] === 'education'));
$experience = array_values(array_filter($timeline, fn($t) => $t['kind'] === 'experience'));

$name = loc($profile, 'name', $lang) ?: t('default_name', $lang);
$headline = loc($profile, 'headline', $lang) ?: t('default_headline', $lang);
$about = loc($profile, 'about', $lang);
$switchLang = $lang === 'fa' ? 'en' : 'fa';

// Initials for the logo and the portrait panel, e.g. "Parsa Banitaba" -> "PB"
$words = preg_split('/\s+/u', trim($name)) ?: [];
$initials = mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2))));
$ghostWord = $words[0] ?? '';
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
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;800&display=swap">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="bg" aria-hidden="true"><span></span><span></span><span></span></div>

<nav class="topbar glass" aria-label="Main">
  <a class="logo" href="#top"><span class="logo-mark"><?= e($initials) ?></span><span class="logo-name"><?= e($name) ?></span></a>
  <div class="links">
    <a href="#journey"><?= e(t('journey', $lang)) ?></a>
    <a href="#projects"><?= e(t('projects', $lang)) ?></a>
    <a href="#papers"><?= e(t('papers', $lang)) ?></a>
    <a href="#slides"><?= e(t('presentations', $lang)) ?></a>
  </div>
  <a class="pill-btn" href="#contact"><?= e(t('lets_chat', $lang)) ?></a>
</nav>

<div class="side-tools glass" aria-label="Preferences">
  <button type="button" id="theme-toggle" aria-label="<?= e(t('toggle_theme', $lang)) ?>">◐</button>
  <a class="lang" href="?lang=<?= $switchLang ?>" hreflang="<?= $switchLang ?>"><?= e(t('lang_switch', $lang)) ?></a>
</div>

<main id="top">
  <section class="hero">
    <div class="hero-text">
      <span class="pill-tag"><?= e($headline) ?></span>
      <h1><?= e($name) ?></h1>
      <?php if ($about !== ''): ?><p class="lead"><?= nl2br(e($about)) ?></p><?php endif; ?>
      <div class="actions">
        <?php if (!empty($profile['cv'])): ?><a class="btn btn-primary" href="<?= e($profile['cv']) ?>" download><?= e(t('download_cv', $lang)) ?></a><?php endif; ?>
        <?php if (!empty($profile['email'])): ?><a class="btn btn-outline" href="mailto:<?= e($profile['email']) ?>"><?= e(t('email_me', $lang)) ?></a><?php endif; ?>
      </div>
      <dl class="stats">
        <div><dt><?= count($projects) ?></dt><dd><?= e(t('stat_projects', $lang)) ?></dd></div>
        <div><dt><?= count($papers) ?></dt><dd><?= e(t('stat_papers', $lang)) ?></dd></div>
        <div><dt><?= count($slides) ?></dt><dd><?= e(t('stat_presentations', $lang)) ?></dd></div>
      </dl>
    </div>
    <div class="hero-visual" aria-hidden="true">
      <span class="ghost-name"><?= e($ghostWord) ?></span>
      <div class="portrait glass"><span class="initials"><?= e($initials) ?></span></div>
    </div>
  </section>

  <hr class="divider">

  <section id="journey" class="block reveal">
    <h2><?= e(t('journey', $lang)) ?></h2>
    <div class="timeline">
      <?php foreach ([['education', $education], ['experience', $experience]] as [$label, $items]): ?>
        <h3><?= e(t($label, $lang)) ?></h3>
        <?php foreach ($items as $item): ?>
          <div class="node glass">
            <span class="muted"><?= e(loc($item, 'period', $lang)) ?></span>
            <strong><?= e(loc($item, 'title', $lang)) ?></strong>
            <?php if ($item['org'] || $item['org_fa']): ?><span><?= e(loc($item, 'org', $lang)) ?></span><?php endif; ?>
            <?php if ($item['details'] || $item['details_fa']): ?><p><?= nl2br(e(loc($item, 'details', $lang))) ?></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  </section>

  <hr class="divider">

  <section id="projects" class="block reveal">
    <h2><span class="file">lib/projects.dart</span> <?= e(t('projects', $lang)) ?></h2>
    <div class="filters" role="group" aria-label="<?= e(t('projects', $lang)) ?>">
      <button class="on" data-filter="all"><?= e(t('filter_all', $lang)) ?></button>
      <button data-filter="work"><?= e(t('filter_work', $lang)) ?></button>
      <button data-filter="academic"><?= e(t('filter_academic', $lang)) ?></button>
    </div>
    <div class="grid">
      <?php foreach ($projects as $p): ?>
        <article class="card glass" data-kind="<?= e($p['kind']) ?>">
          <span class="tag"><?= e($p['kind']) ?></span>
          <h3><?= e(loc($p, 'title', $lang)) ?></h3>
          <p><?= nl2br(e(loc($p, 'summary', $lang))) ?></p>
          <?php if ($p['tags']): ?><p class="muted"><?= e($p['tags']) ?></p><?php endif; ?>
          <?php if ($p['url']): ?><a href="<?= e($p['url']) ?>" target="_blank" rel="noopener"><?= e(t('view_project', $lang)) ?> →</a><?php endif; ?>
        </article>
      <?php endforeach; ?>
      <?php if (!$projects): ?><p class="muted"><?= e(t('empty_projects', $lang)) ?></p><?php endif; ?>
    </div>
  </section>

  <hr class="divider">

  <section id="papers" class="block reveal">
    <h2><?= e(t('papers', $lang)) ?></h2>
    <?php foreach ($papers as $paper): ?>
      <article class="row glass">
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

  <hr class="divider">

  <section id="slides" class="block reveal">
    <h2><?= e(t('presentations', $lang)) ?></h2>
    <?php foreach ($slides as $s): ?>
      <details class="glass slide">
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

  <hr class="divider">

  <section id="contact" class="block reveal">
    <div class="contact-card glass">
      <h2><?= e(t('contact', $lang)) ?></h2>
      <?php if (!empty($profile['email'])): ?><p><a href="mailto:<?= e($profile['email']) ?>"><?= e($profile['email']) ?></a></p><?php endif; ?>
      <?php if (!empty($profile['phone'])): ?>
        <p><span class="muted"><?= e(t('phone', $lang)) ?>:</span> <a href="tel:<?= e(preg_replace('/\s+/', '', $profile['phone'])) ?>"><?= e($profile['phone']) ?></a></p>
      <?php endif; ?>
      <div class="links">
        <?php if (!empty($profile['linkedin'])): ?><a href="<?= e($profile['linkedin']) ?>" target="_blank" rel="noopener">LinkedIn</a><?php endif; ?>
        <?php if (!empty($profile['github'])): ?><a href="<?= e($profile['github']) ?>" target="_blank" rel="noopener">GitHub</a><?php endif; ?>
      </div>
    </div>
  </section>

  <footer class="muted">© <?= date('Y') ?> <?= e($name) ?></footer>
</main>

<script src="assets/app.js" defer></script>
</body>
</html>

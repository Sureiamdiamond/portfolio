<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

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

$name = $profile['name'] ?? 'Your Name';
$headline = $profile['headline'] ?? 'Software engineer';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($name) ?> · Portfolio</title>
<meta name="description" content="<?= e($headline) ?>">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="blobs" aria-hidden="true"><span></span><span></span><span></span></div>

<header class="dock glass" aria-label="Sections">
  <a href="#top" title="Home">⌂</a>
  <a href="#projects" title="Projects">{ }</a>
  <a href="#papers" title="Papers">📄</a>
  <a href="#slides" title="Presentations">🖥</a>
  <a href="#journey" title="Education & experience">⎇</a>
  <a href="#contact" title="Contact">✉</a>
</header>

<main id="top">
  <section class="hero glass reveal">
    <p class="kicker">git checkout main</p>
    <h1><?= e($name) ?></h1>
    <p class="lead"><?= e($headline) ?></p>
    <div class="actions">
      <?php if (!empty($profile['cv'])): ?><a class="btn" href="<?= e($profile['cv']) ?>" download>Download CV</a><?php endif; ?>
      <?php if (!empty($profile['email'])): ?><a class="btn ghost" href="mailto:<?= e($profile['email']) ?>">Email me</a><?php endif; ?>
    </div>
  </section>

  <section id="projects" class="reveal">
    <h2><span class="file">lib/projects.dart</span></h2>
    <div class="filters" role="group" aria-label="Filter projects">
      <button class="on" data-filter="all">All</button>
      <button data-filter="work">Work</button>
      <button data-filter="academic">Academic</button>
    </div>
    <div class="grid">
      <?php foreach ($projects as $p): ?>
        <article class="card glass" data-kind="<?= e($p['kind']) ?>">
          <span class="tag"><?= e($p['kind']) ?></span>
          <h3><?= e($p['title']) ?></h3>
          <p><?= nl2br(e($p['summary'])) ?></p>
          <?php if ($p['tags']): ?><p class="muted"><?= e($p['tags']) ?></p><?php endif; ?>
          <?php if ($p['url']): ?><a href="<?= e($p['url']) ?>" target="_blank" rel="noopener">View project →</a><?php endif; ?>
        </article>
      <?php endforeach; ?>
      <?php if (!$projects): ?><p class="muted">Projects will appear here.</p><?php endif; ?>
    </div>
  </section>

  <section id="papers" class="reveal">
    <h2>Papers</h2>
    <?php foreach ($papers as $paper): ?>
      <article class="row glass">
        <div>
          <h3><?= e($paper['title']) ?></h3>
          <p class="muted"><?= e(trim(($paper['venue'] ?? '') . ' ' . ($paper['year'] ?? ''))) ?></p>
        </div>
        <div class="links">
          <?php if ($paper['pdf']): ?><a href="<?= e($paper['pdf']) ?>" target="_blank" rel="noopener">PDF</a><?php endif; ?>
          <?php if ($paper['doi']): ?><a href="https://doi.org/<?= e($paper['doi']) ?>" target="_blank" rel="noopener">DOI</a><?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
    <?php if (!$papers): ?><p class="muted">Papers will appear here.</p><?php endif; ?>
  </section>

  <section id="slides" class="reveal">
    <h2>Presentations</h2>
    <?php foreach ($slides as $s): ?>
      <details class="glass slide">
        <summary>
          <strong><?= e($s['title']) ?></strong>
          <span class="muted"><?= e(trim(($s['event'] ?? '') . ' ' . ($s['year'] ?? ''))) ?></span>
        </summary>
        <?php if ($s['pdf']): ?>
          <iframe src="<?= e($s['pdf']) ?>#view=FitH" title="<?= e($s['title']) ?>" loading="lazy"></iframe>
        <?php endif; ?>
        <div class="links">
          <?php if ($s['pdf']): ?><a href="<?= e($s['pdf']) ?>" target="_blank" rel="noopener">Open PDF</a><?php endif; ?>
          <?php if ($s['pptx']): ?><a href="<?= e($s['pptx']) ?>" download>Download PPTX</a><?php endif; ?>
        </div>
      </details>
    <?php endforeach; ?>
    <?php if (!$slides): ?><p class="muted">Presentations will appear here.</p><?php endif; ?>
  </section>

  <section id="journey" class="reveal">
    <h2>Education & experience</h2>
    <div class="timeline">
      <?php foreach ([['Education', $education], ['Experience', $experience]] as [$label, $items]): ?>
        <h3><?= $label ?></h3>
        <?php foreach ($items as $t): ?>
          <div class="node glass">
            <span class="muted"><?= e($t['period']) ?></span>
            <strong><?= e($t['title']) ?></strong>
            <?php if ($t['org']): ?><span><?= e($t['org']) ?></span><?php endif; ?>
            <?php if ($t['details']): ?><p><?= nl2br(e($t['details'])) ?></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  </section>

  <section id="contact" class="reveal glass">
    <h2>Contact</h2>
    <p>
      <?php if (!empty($profile['email'])): ?><a href="mailto:<?= e($profile['email']) ?>"><?= e($profile['email']) ?></a><?php endif; ?>
    </p>
    <div class="links">
      <?php if (!empty($profile['linkedin'])): ?><a href="<?= e($profile['linkedin']) ?>" target="_blank" rel="noopener">LinkedIn</a><?php endif; ?>
      <?php if (!empty($profile['github'])): ?><a href="<?= e($profile['github']) ?>" target="_blank" rel="noopener">GitHub</a><?php endif; ?>
    </div>
  </section>

  <footer class="muted">© <?= date('Y') ?> <?= e($name) ?></footer>
</main>

<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js" defer></script>
<script src="assets/app.js" defer></script>
</body>
</html>

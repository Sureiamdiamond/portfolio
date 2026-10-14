<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/i18n.php';
require_once __DIR__ . '/project_tree.php';

$lang = current_lang();
$dir = $lang === 'fa' ? 'rtl' : 'ltr';
$pdo = db();
$profile = [];
foreach ($pdo->query('SELECT k, v FROM profile') as $row) {
    $profile[$row['k']] = $row['v'];
}
// Theme: the published settings, or the draft when the admin's preview link is used
require_once __DIR__ . '/theme.php';
require_once __DIR__ . '/timeline.php';
require_once __DIR__ . '/skill_icons.php';
$themeSettings = theme_published($pdo);
$isPreview = false;
$previewToken = (string) ($_GET['preview'] ?? '');
if ($previewToken !== '' && ($draftSettings = theme_draft_for($previewToken)) !== null) {
    $themeSettings = $draftSettings;
    $isPreview = true;
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
// Name on two lines for the hero: first name, then the rest
$nameLines = array_values(array_filter(preg_split('/\s+/u', trim($name), 2) ?: [], 'strlen'));
$headline = loc($profile, 'headline', $lang) ?: t('default_headline', $lang);
$about = loc($profile, 'about', $lang);
$switchLang = $lang === 'fa' ? 'en' : 'fa';

$words = preg_split('/\s+/u', trim($name)) ?: [];
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
<html lang="<?= $lang ?>" dir="<?= $dir ?>"<?= $themeSettings['theme_mode'] !== 'system' ? ' data-theme="' . $themeSettings['theme_mode'] . '"' : '' ?> data-motion="<?= $themeSettings['theme_motion'] ?>" data-glass="<?= $themeSettings['theme_glass'] ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
// Browser chrome colour (status bar, address bar, overscroll) follows the theme: the forced theme gets one tag, system gets one per scheme.
$chromeColors = ['light' => '#FAFAFA', 'dark' => '#0B1222'];
$chromeMode = $themeSettings['theme_mode'];
if (isset($chromeColors[$chromeMode])) {
    echo '<meta name="theme-color" content="' . $chromeColors[$chromeMode] . '">' . "\n";
} else {
    echo '<meta name="theme-color" content="' . $chromeColors['light'] . '" media="(prefers-color-scheme: light)">' . "\n";
    echo '<meta name="theme-color" content="' . $chromeColors['dark'] . '" media="(prefers-color-scheme: dark)">' . "\n";
}
?>
<meta name="color-scheme" content="light dark">
<?php
// Search and sharing metadata. The site lives at the domain; each language has its own address.
$siteUrl = 'https://parsabanitaba.ir';
$pageUrl = $lang === 'fa' ? $siteUrl . '/?lang=fa' : $siteUrl . '/';
$summary = trim(preg_replace('/\s+/u', ' ', strip_tags($about)));
$description = mb_substr(trim($name . ' · ' . $headline . ($summary !== '' ? ' — ' . $summary : '')), 0, 160);
if (trim((string) ($profile['meta_description'] ?? '')) !== '') {
    $description = trim((string) $profile['meta_description']);
}
$shareImage = $siteUrl . '/' . ($heroPhoto !== '' ? $heroPhoto : 'assets/img/portrait.png');
$personData = [
    '@context' => 'https://schema.org',
    '@type' => 'Person',
    'name' => 'Parsa Banitaba',
    'alternateName' => 'پارسا بنی‌طبا',
    'jobTitle' => $headline,
    'description' => $summary !== '' ? mb_substr($summary, 0, 300) : null,
    'url' => $siteUrl . '/',
    'image' => $shareImage,
    'email' => !empty($profile['email']) ? 'mailto:' . $profile['email'] : null,
    'knowsAbout' => ['Flutter', 'Dart'],
    'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Tehran', 'addressCountry' => 'IR'],
    'sameAs' => array_values(array_filter([$profile['github'] ?? '', $profile['linkedin'] ?? ''])),
];
$personData = array_filter($personData, fn($v) => $v !== null && $v !== []);
?>
<title><?= e($name) ?> · <?= e($headline !== '' ? $headline : t('default_title', $lang)) ?></title>
<meta name="description" content="<?= e($description) ?>">
<meta name="robots" content="<?= $isPreview ? 'noindex, nofollow' : 'index, follow' ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<link rel="alternate" hreflang="en" href="<?= e($siteUrl . '/') ?>">
<link rel="alternate" hreflang="fa" href="<?= e($siteUrl . '/?lang=fa') ?>">
<link rel="alternate" hreflang="x-default" href="<?= e($siteUrl . '/') ?>">
<meta property="og:type" content="profile">
<meta property="og:site_name" content="<?= e($name) ?>">
<meta property="og:title" content="<?= e($name) ?> · <?= e($headline) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:image" content="<?= e($shareImage) ?>">
<meta property="og:locale" content="<?= $lang === 'fa' ? 'fa_IR' : 'en_US' ?>">
<meta property="og:locale:alternate" content="<?= $lang === 'fa' ? 'en_US' : 'fa_IR' ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($name) ?> · <?= e($headline) ?>">
<meta name="twitter:description" content="<?= e($description) ?>">
<meta name="twitter:image" content="<?= e($shareImage) ?>">
<link rel="icon" href="assets/img/favicon.svg?v=<?= $assetVersion ?>" type="image/svg+xml">
<script type="application/ld+json"><?= json_encode($personData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
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
<style id="theme"><?= theme_css($themeSettings) ?></style>
</head>
<body>

<a class="skip" href="#main"><?= e($lang === 'fa' ? 'رفتن به محتوا' : 'Skip to content') ?></a>

<header class="topbar">
  <div class="topbar-inner">
    <nav class="nav-links" aria-label="<?= e(t('projects', $lang)) ?>">
      <?php if (($profile['show_journey'] ?? '1') !== '0'): ?><a href="#journey"><?= e(t('journey', $lang)) ?></a><?php endif; ?>
      <?php if (($profile['show_projects'] ?? '1') !== '0'): ?><a href="#projects"><?= e(t('projects', $lang)) ?></a><?php endif; ?>
      <?php if (($profile['show_research'] ?? '1') !== '0'): ?><a href="#research"><?= e(t('papers', $lang)) ?></a><?php endif; ?>
      <?php if (($profile['show_slides'] ?? '1') !== '0'): ?><a href="#slides"><?= e(t('presentations', $lang)) ?></a><?php endif; ?>
    </nav>
    <div class="nav-actions">
      <a class="tool" href="?lang=<?= $switchLang ?>" hreflang="<?= $switchLang ?>"><?= e(t('lang_switch', $lang)) ?></a>
      <button type="button" class="tool" id="theme-toggle" aria-label="<?= e(t('toggle_theme', $lang)) ?>"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 3.5a8.5 8.5 0 0 1 0 17z" fill="currentColor"/></svg></button>
      <a class="btn btn-primary" href="#contact"><?= e(t('contact_me', $lang)) ?></a>
    </div>
  </div>
</header>

<main id="main">
  <section id="top" class="hero">
    <div class="hero-text">
      <span class="pill"><span class="dot" aria-hidden="true"></span><bdi><?= e($headline) ?></bdi></span>
      <h1><?= e($firstWords) ?><?php if ($lastWord !== ''): ?> <span class="grad"><?= e($lastWord) ?></span><?php endif; ?></h1>
      <?php if ($about !== ''): ?><p class="lead"><?= nl2br(e($about)) ?></p><?php endif; ?>
      <div class="actions">
        <?php if (!empty($profile['cv'])): ?><a class="btn btn-primary" href="<?= e($profile['cv']) ?>" download><?= e(t('download_cv', $lang)) ?></a><?php endif; ?>
        <?php if (!empty($profile['email'])): ?><a class="btn btn-outline" href="mailto:<?= e($profile['email']) ?>"><?= e(t('email_me', $lang)) ?></a><?php endif; ?>
      </div>
      <?php $heroLocation = loc($profile, 'location', $lang); if ($heroLocation !== ''): ?>
        <p class="loc"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg><span><?= e($heroLocation) ?></span></p>
      <?php endif; ?>
    </div>
    <div class="hero-art" aria-hidden="true">
      <span class="ring"></span>
      <div class="portrait">
        <img class="portrait-img" src="<?= e($heroPhoto !== '' ? $heroPhoto : 'assets/img/portrait.png?v=' . $assetVersion) ?>" alt="<?= e($name) ?>" width="380" height="541" decoding="async">
        <svg class="dash" viewBox="0 0 64 64" aria-hidden="true" focusable="false">
          <path class="dash-tail" d="M12 40 L3 33 L5 42 L1 47 L14 46 Z" fill="#029EFD"/>
          <path class="dash-body" d="M10 42 C16 28 34 22 48 27 C55 29 58 34 56 38 C53 44 36 48 22 49 C16 50 11 47 10 42 Z" fill="#54C5F8"/>
          <path class="dash-wing" d="M24 34 L38 12 L50 13 L40 32 Z" fill="#01579B"/>
          <path class="dash-beak" d="M55 31 L63 35 L55 38 Z" fill="#FFB020"/>
          <circle class="dash-eye" cx="45" cy="31" r="2.4" fill="#0B1222"/>
          <path class="dash-legs" d="M30 47 L28 55 M36 47 L37 55" stroke="#01579B" stroke-width="2.4" stroke-linecap="round" fill="none"/>
        </svg>
      </div>
      <span class="chip chip-icon chip-a" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 7l-5 5 5 5M16 7l5 5-5 5M14 4l-4 16"/></svg></span>
      <span class="chip chip-icon chip-b" aria-hidden="true"><img src="assets/img/ide/flutter_logo.png?v=<?= $assetVersion ?>" alt="" width="27" height="32" decoding="async"></span>
    </div>
  </section>

<?php if (($profile['show_journey'] ?? '1') !== '0'): ?>
  <section id="journey" class="block reveal">
    <h2><?= e(t('journey', $lang)) ?></h2>
    <div class="timeline">
      <?php foreach ([['education', $education], ['experience', $experience]] as [$label, $items]): ?>
        <h3 class="sub"><?= e(t($label, $lang)) ?></h3>
        <?php if ($label === 'education'): ?>
          <ul class="edu-row">
            <?php foreach ($items as $index => $item): echo education_item_html($item, $index, $lang); endforeach; ?>
          </ul>
        <?php else: ?>
        <ol class="tl tl-career">
          <?php foreach ($items as $index => $item):
            $current = str_contains(strtolower((string) $item['period']), 'now');
          ?>
            <li class="tl-item reveal<?= $current ? ' is-current' : '' ?>" style="--dot-o: <?= timeline_dot_opacity(count($items) - 1 - $index) ?>">
              <article class="tl-card">
                <?= org_tile($item, $lang) ?>
                <div class="tl-body">
                  <p class="tl-org"><?= e(loc($item, 'org', $lang)) ?></p>
                  <h4 class="tl-role"><?= e(loc($item, 'title', $lang)) ?></h4>
                  <?php if (trim(loc($item, 'period', $lang)) !== '' || trim(loc($item, 'city', $lang)) !== '' || trim((string) $item['website']) !== ''): ?>
                  <p class="tl-meta">
                    <span class="period"><?= e(loc($item, 'period', $lang)) ?></span>
                    <?php if (trim(loc($item, 'city', $lang)) !== ''): ?><span class="tl-city"><?= e(loc($item, 'city', $lang)) ?></span><?php endif; ?>
                    <?= timeline_site($item, $lang) ?>
                  </p>
                  <?php endif; ?>
                </div>
              </article>
            </li>
          <?php endforeach; ?>
        </ol>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php
      $skills = skill_items(loc($profile, 'skills', $lang));
      $skillGroups = skill_groups($skills);
      $languages = language_items(loc($profile, 'languages', $lang));
      $languageGroups = skill_groups($languages);
      $location = loc($profile, 'location', $lang);
    ?>
    <?php if ($skills || $languages): ?>
      <div class="skills-langs">
        <h3 class="sub"><?= e(t('skills_languages', $lang)) ?></h3>
        <?php if ($skills): ?>
          <p class="skills-label"><?= e(t('skills', $lang)) ?></p>
          <ul class="skill-grid">
            <?php foreach ($skillGroups as $group): ?><?= skill_card_html($group) ?><?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if ($languages): ?>
          <p class="skills-label"><?= e(t('languages', $lang)) ?></p>
          <ul class="skill-grid">
            <?php foreach ($languageGroups as $group): ?><?= language_card_html($group) ?><?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if (($profile['show_projects'] ?? '1') !== '0'): ?>
  <section id="projects" class="block reveal">
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

    <div class="pv-normal" id="view-normal">
      <div class="grid">
        <?php foreach ($projects as $p): $shots = $projectShots[$p['id']] ?? []; ?>
          <?php
            // The card's links: one direct link, or a small list when the project has several (see project_options).
            $options = project_options($p, $lang);
            $primary = $options[0][1] ?? '';
          ?>
          <article class="card pcard" data-kind="<?= e($p['kind']) ?>">
            <?php if ($shots): ?>
              <figure class="pcard-shot"><img src="<?= e($shots[0]) ?>" alt="" loading="lazy"></figure>
            <?php endif; ?>
            <?= project_tile($p) ?>
            <h3><?= e(loc($p, 'title', $lang)) ?></h3>
            <p class="muted pcard-summary"><?= nl2br(e(loc($p, 'summary', $lang))) ?></p>
            <?php $tags = array_filter(array_map('trim', explode(',', (string) $p['tags']))); ?>
            <?php if ($tags): ?>
              <ul class="pcard-tags"><?php foreach ($tags as $tag): ?><li><?= e($tag) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <?php if (count($shots) > 1): ?>
              <ul class="pcard-shots"><?php foreach (array_slice($shots, 1) as $more): ?><li><img src="<?= e($more) ?>" alt="" loading="lazy"></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <?php if (count($options) === 1): ?>
              <a class="pcard-ext" href="<?= e($options[0][1]) ?>" target="_blank" rel="noopener"><?= e($options[0][0]) ?> <span class="arr" aria-hidden="true">→</span></a>
            <?php elseif (count($options) > 1): ?>
              <div class="pcard-menu">
                <button type="button" class="pcard-ext pcard-menu-btn" aria-haspopup="menu" aria-expanded="false"><?= $lang === 'fa' ? 'وب‌سایت' : 'Website' ?> <span class="arr" aria-hidden="true">▾</span></button>
                <ul class="pcard-menu-list" role="menu">
                  <?php foreach ($options as [$optionLabel, $optionUrl, $optionIcon]): ?>
                    <li role="none"><a role="menuitem" href="<?= e($optionUrl) ?>" target="_blank" rel="noopener"><?php if ($optionIcon !== ''): ?><img class="pmenu-icon" src="<?= e($optionIcon) ?>" alt="" width="20" height="20" loading="lazy"><?php endif; ?><span><?= e($optionLabel) ?></span></a></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>
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
<?php endif; ?>

<?php if (($profile['show_research'] ?? '1') !== '0'): ?>
  <section id="research" class="block reveal">
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
<?php endif; ?>

<?php if (($profile['show_slides'] ?? '1') !== '0'): ?>
  <section id="slides" class="block reveal">
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
<?php endif; ?>

  <section id="contact" class="inverted reveal">
    <div class="contact-grid">
      <div>
        <h2><?= e(t('contact_me', $lang)) ?></h2>
      </div>
      <div class="contact-cards">
        <?php
        // Each card: [href, label, shown value (null: icon and label only), svg viewBox, svg inner markup, opens in a new tab]
        $cards = [];
        if (!empty($profile['github'])) $cards[] = [$profile['github'], 'GitHub', null, '0 0 16 16', '<path fill="currentColor" stroke="none" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/>', true];
        if (!empty($profile['linkedin'])) $cards[] = [$profile['linkedin'], 'LinkedIn', null, '0 0 24 24', '<rect x="2.5" y="2.5" width="19" height="19" rx="3"/><path d="M7 10v7M7 6.8v.2M11 17v-7M11 13c0-1.7 1.2-3 2.8-3s2.7 1.3 2.7 3v4"/>', true];
        if (!empty($profile['email'])) $cards[] = ['mailto:' . $profile['email'], t('email', $lang), $profile['email'], '0 0 24 24', '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/>', false];
        if (!empty($profile['phone'])) $cards[] = ['tel:' . preg_replace('/\s+/', '', $profile['phone']), t('phone', $lang), $profile['phone'], '0 0 24 24', '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>', false];
        // The location card opens Tehran on OpenStreetMap only when clicked; no map is embedded.
        if ($location !== '') $cards[] = ['https://www.openstreetmap.org/search?query=' . rawurlencode($location), t('location', $lang), $location, '0 0 24 24', '<path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>', true];
        foreach ($cards as [$href, $label, $value, $viewBox, $icon, $external]): ?>
          <a class="cc" href="<?= e($href) ?>"<?= $external ? ' target="_blank" rel="noopener"' : '' ?>>
            <svg class="cc-ico" viewBox="<?= $viewBox ?>" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><?= $icon ?></svg>
            <span class="cc-text">
              <?php if ($value === null): ?>
                <bdi class="cc-value"><?= e($label) ?></bdi>
              <?php else: ?>
                <span class="cc-label"><?= e($label) ?></span>
                <bdi class="cc-value" dir="ltr"><?= e($value) ?></bdi>
              <?php endif; ?>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</main>

<?php if (!empty($profile['cv'])): ?><a class="btn btn-primary cv-fab" href="<?= e($profile['cv']) ?>" download><?= e(t('download_cv', $lang)) ?></a><?php endif; ?>
<a class="to-top" href="#top" aria-label="<?= e(t('back_to_top', $lang)) ?>" title="<?= e(t('back_to_top', $lang)) ?>"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 14l6-6 6 6"/></svg></a>

<footer class="muted">
  <?= trim((string) ($profile['footer_text'] ?? '')) !== '' ? e($profile['footer_text']) : '© ' . date('Y') . ' ' . e($name) ?>
</footer>
<!-- build <?= $assetVersion ?> -->

<script src="assets/app.js?v=<?= $assetVersion ?>" defer></script>
</body>
</html>

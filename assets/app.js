// Progressive enhancement: the page is fully readable without this file.
(function () {
  const root = document.documentElement;
  // Adding .js is what turns on the scroll entrance; without this script everything stays visible.
  root.classList.add('js');

  const systemDark = window.matchMedia('(prefers-color-scheme: dark)');
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Fail loudly in the console if controls this script depends on are missing
  ['#theme-toggle', '.filters button', '#projects .card'].forEach((selector) => {
    if (!document.querySelector(selector)) console.error('[portfolio] element not found:', selector);
  });

  // Theme toggle: remembers the choice, otherwise follows the system preference
  const toggle = document.getElementById('theme-toggle');
  const effectiveTheme = () => root.dataset.theme || (systemDark.matches ? 'dark' : 'light');
  if (toggle) {
    toggle.addEventListener('click', () => {
      const next = effectiveTheme() === 'dark' ? 'light' : 'dark';
      root.dataset.theme = next;
      try { localStorage.setItem('theme', next); } catch (e) { /* storage blocked: applies to this page only */ }
    });
  }

  // Project filters
  const buttons = document.querySelectorAll('.filters button');
  buttons.forEach((btn) => {
    btn.addEventListener('click', () => {
      const kind = btn.dataset.filter;
      buttons.forEach((b) => {
        const active = b === btn;
        b.classList.toggle('on', active);
        b.setAttribute('aria-pressed', String(active));
      });
      document.querySelectorAll('#projects .card').forEach((card) => {
        card.hidden = kind !== 'all' && card.dataset.kind !== kind;
      });
    });
  });

  // Scroll entrance: each section fades up once when it first enters the viewport
  const sections = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window && !reduce) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('in');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12 });
    sections.forEach((el) => io.observe(el));
  } else {
    sections.forEach((el) => el.classList.add('in'));
  }
})();

// Files panel: preview on click, expand/collapse all, and IDE-style arrow-key navigation.
// Without this script the tree still works: folders are native <details>, files open in a new tab.
(function () {
  const tree = document.getElementById('tree');
  if (!tree) return;
  const preview = document.getElementById('preview');
  const pvPath = document.getElementById('pv-path');
  const pvBody = document.getElementById('pv-body');
  const pvDownload = document.getElementById('pv-download');
  const strings = document.documentElement.lang === 'fa'
    ? { select: 'یک فایل را انتخاب کنید تا اینجا نمایش داده شود.', none: 'پیش‌نمایشی برای این نوع فایل نیست. از دکمه‌ی دانلود استفاده کنید.' }
    : { select: 'Select a file to preview it here.', none: 'No preview for this file type. Use Download.' };

  // Build the preview with DOM methods (paths come from the database, never as raw HTML)
  function show(link) {
    tree.querySelectorAll('.is-selected').forEach((el) => el.classList.remove('is-selected'));
    link.classList.add('is-selected');
    pvPath.textContent = link.dataset.name;
    pvDownload.href = link.dataset.download;
    pvDownload.hidden = false;
    pvBody.replaceChildren();
    const kind = link.dataset.kind;
    const src = link.dataset.preview;
    if (kind === 'pdf' && src) {
      const frame = document.createElement('iframe');
      frame.src = src + '#view=FitH';
      frame.title = link.dataset.name;
      pvBody.appendChild(frame);
    } else if (kind === 'image' && src) {
      const img = document.createElement('img');
      img.src = src;
      img.alt = link.dataset.name;
      pvBody.appendChild(img);
    } else {
      const p = document.createElement('p');
      p.className = 'muted';
      p.textContent = strings.none;
      pvBody.appendChild(p);
    }
    if (window.matchMedia('(max-width: 760px)').matches) {
      preview.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  tree.addEventListener('click', (ev) => {
    const link = ev.target.closest('a.file');
    if (!link || ev.metaKey || ev.ctrlKey || ev.shiftKey) return;
    ev.preventDefault();
    show(link);
  });

  // Expand all / collapse all
  document.querySelectorAll('[data-action]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const open = btn.dataset.action === 'expand';
      tree.querySelectorAll('details').forEach((d) => { d.open = open; });
    });
  });

  // Keyboard: Up/Down move through visible rows, Right opens or steps in, Left closes or steps out
  const visibleRows = () => Array.from(tree.querySelectorAll('summary, a.file')).filter((el) => el.offsetParent !== null);
  tree.addEventListener('keydown', (ev) => {
    const rows = visibleRows();
    const index = rows.indexOf(document.activeElement);
    if (index === -1) return;
    const row = rows[index];
    const folderDetails = row.tagName === 'SUMMARY' ? row.parentElement : null;
    let target = null;
    switch (ev.key) {
      case 'ArrowDown': target = rows[index + 1]; break;
      case 'ArrowUp': target = rows[index - 1]; break;
      case 'Home': target = rows[0]; break;
      case 'End': target = rows[rows.length - 1]; break;
      case 'ArrowRight':
        if (folderDetails && !folderDetails.open) { folderDetails.open = true; }
        else { target = rows[index + 1]; }
        break;
      case 'ArrowLeft':
        if (folderDetails && folderDetails.open) { folderDetails.open = false; }
        else {
          const parentDetails = folderDetails ? folderDetails.parentElement.closest('details') : row.closest('details');
          target = parentDetails?.querySelector(':scope > summary');
        }
        break;
      default: return;
    }
    ev.preventDefault();
    if (target) target.focus();
  });
})();

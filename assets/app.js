// Page scripts run per language pane (see initPane); the switch between panes is at the end of this file.

// Both language panes are live: each runs the same setup against its own elements, with its own id suffix.
function initPane(scope, sfx) {
  const byId = (name) => scope.querySelector('#' + name + sfx);
// Progressive enhancement: the page is fully readable without this file.
(function () {
  const root = document.documentElement;
  // Adding .js is what turns on the scroll entrance; without this script everything stays visible.
  root.classList.add('js');

  const systemDark = window.matchMedia('(prefers-color-scheme: dark)');
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Fail loudly in the console if controls this script depends on are missing
  ['#theme-toggle', '[data-pt]'].forEach((selector) => {
    if (sfx === '' && !scope.querySelector(selector)) console.error('[portfolio] element not found:', selector);
  });

  // Theme toggle: remembers the choice, otherwise follows the system preference
  const toggle = byId('theme-toggle');
  const effectiveTheme = () => root.dataset.theme || (systemDark.matches ? 'dark' : 'light');
  // Browser chrome colour: one tag for the chosen theme, replacing the per-scheme pair from the page head
  const setChromeColor = (theme) => {
    document.querySelectorAll('meta[name="theme-color"]').forEach((m) => m.remove());
    const meta = document.createElement('meta');
    meta.name = 'theme-color';
    meta.content = theme === 'dark' ? '#0B1222' : '#FAFAFA';
    document.head.appendChild(meta);
  };
  if (toggle) {
    toggle.addEventListener('click', () => {
      const next = effectiveTheme() === 'dark' ? 'light' : 'dark';
      root.dataset.theme = next;
      setChromeColor(next);
      try { localStorage.setItem('theme', next); } catch (e) { /* storage blocked: applies to this page only */ }
    });
  }

  // Scroll entrance: each section fades up once when it first enters the viewport
  const sections = scope.querySelectorAll('.reveal');
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

// Project window: opens files in editor tabs, resizable tool window, keyboard navigation.
// Without this script the tree still works: folders are native <details>, and each file is a link.
(function () {
  const win = scope.querySelector('[data-pt]');
  if (!win) return;
  const tree = byId('pt-tree');
  const tabsEl = byId('pt-tabs');
  const view = byId('pt-view');
  const statusPath = byId('pt-status-path');
  const statusLang = byId('pt-status-lang');
  const split = byId('pt-split');
  const toggle = byId('pt-toggle');
  const tabs = new Map(); // source id -> { tab, name }
  let activeId = null;

  const isNarrow = () => window.matchMedia('(max-width: 760px)').matches;
  const langName = { dart: 'Dart', yaml: 'YAML', markdown: 'Markdown', image: 'Image', text: 'Plain text' };

  function setActive(id) {
    const src = scope.querySelector('#' + CSS.escape(id));
    if (!src) return;
    activeId = id;
    tabs.forEach((entry, key) => {
      const on = key === id;
      entry.tab.classList.toggle('is-active', on);
      entry.tab.querySelector('[role="tab"]').setAttribute('aria-selected', String(on));
    });
    view.replaceChildren(...Array.from(src.childNodes, (n) => n.cloneNode(true)));
    const link = tree.querySelector(`a[data-src="${id}"]`);
    tree.querySelectorAll('.is-selected').forEach((el) => el.classList.remove('is-selected'));
    if (link) link.classList.add('is-selected');
    statusPath.textContent = link ? link.dataset.path : '';
    statusLang.textContent = link ? (langName[link.dataset.lang] || 'Plain text') : '';
    view.scrollTop = 0;
  }

  function openFile(link) {
    const id = link.dataset.src;
    if (!tabs.has(id)) {
      const tab = document.createElement('div');
      tab.className = 'pt-tab';
      const name = link.querySelector('.nm').textContent;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.setAttribute('role', 'tab');
      btn.textContent = name;
      const icon = link.querySelector('.ic');
      if (icon) btn.prepend(icon.cloneNode(true));
      btn.addEventListener('click', () => setActive(id));
      const x = document.createElement('button');
      x.type = 'button';
      x.className = 'x';
      x.setAttribute('aria-label', 'Close ' + name);
      x.textContent = '×';
      x.addEventListener('click', (ev) => { ev.stopPropagation(); closeTab(id); });
      tab.append(btn, x);
      tabs.set(id, { tab, name });
      tabsEl.appendChild(tab);
    }
    setActive(id);
    if (isNarrow()) closeDrawer();
  }

  function closeTab(id) {
    const entry = tabs.get(id);
    if (!entry) return;
    entry.tab.remove();
    tabs.delete(id);
    if (activeId === id) {
      const last = Array.from(tabs.keys()).pop();
      if (last) setActive(last);
      else {
        activeId = null;
        view.replaceChildren();
        const hint = document.createElement('p');
        hint.className = 'pt-hint';
        hint.textContent = document.documentElement.lang === 'fa' ? 'یک فایل را انتخاب کنید.' : 'Select a file in the Project panel.';
        view.appendChild(hint);
        statusPath.textContent = '';
        statusLang.textContent = '';
        tree.querySelectorAll('.is-selected').forEach((el) => el.classList.remove('is-selected'));
      }
    }
  }

  tree.addEventListener('click', (ev) => {
    const link = ev.target.closest('a.it.file');
    if (!link || ev.metaKey || ev.ctrlKey || ev.shiftKey) return;
    ev.preventDefault();
    openFile(link);
  });

  // On load the first project opens, with its README.md shown; its assets, android and ios folders stay closed.
  const firstProject = tree.querySelector('li.it-project > details');
  if (firstProject) {
    firstProject.open = true;
    const readme = Array.from(firstProject.querySelectorAll(':scope > ul.kids a.it.file')).find((a) => a.querySelector('.nm')?.textContent === 'README.md');
    if (readme) openFile(readme);
  }

  // Expand all / collapse all, and the mobile drawer
  win.querySelectorAll('[data-act]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const act = btn.dataset.act;
      if (act === 'close-drawer') return closeDrawer();
      const open = act === 'expand';
      tree.querySelectorAll('details').forEach((d) => { d.open = open; });
    });
  });

  function closeDrawer() {
    win.classList.remove('tool-open');
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
  }
  if (toggle) {
    toggle.addEventListener('click', () => {
      const open = win.classList.toggle('tool-open');
      toggle.setAttribute('aria-expanded', String(open));
    });
  }

  // Projects view switch: Normal cards or the IDE window. Remembers the choice.
  const section = byId('projects');
  const switcher = byId('view-switch');
  if (section && switcher) {
    const views = {
      normal: byId('view-normal'),
      ide: section.querySelector('.pt-window'),
    };
    const buttons = Array.from(switcher.querySelectorAll('[role="radio"]'));
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const show = (name, animate) => {
      if (!views[name]) return;
      section.dataset.view = name;
      document.documentElement.dataset.projectsView = name;
      buttons.forEach((b) => {
        const on = b.dataset.view === name;
        b.classList.toggle('on', on);
        b.setAttribute('aria-checked', String(on));
        b.tabIndex = on ? 0 : -1;
      });
      Object.entries(views).forEach(([n, el]) => {
        if (!el) return;
        el.hidden = n !== name;
        el.classList.remove('view-in');
      });
      const el = views[name];
      if (animate && el && !reduceMotion.matches) {
        void el.offsetWidth;
        el.classList.add('view-in');
        el.addEventListener('animationend', () => el.classList.remove('view-in'), { once: true });
      }
    };
    // #projects-ide is an explicit override of the saved choice; it also scrolls to the section
    const applyHash = (scroll) => {
      if (window.location.hash !== '#projects-ide') return false;
      show('ide', false);
      try { localStorage.setItem('projects-view', 'ide'); } catch (err) { /* ignore */ }
      if (scroll) section.scrollIntoView({ behavior: reduceMotion.matches ? 'auto' : 'smooth', block: 'start' });
      return true;
    };
    let initial = 'normal';
    try { initial = localStorage.getItem('projects-view') || 'normal'; } catch (err) { /* private mode: keep the default */ }
    show(initial === 'ide' ? 'ide' : 'normal', false);
    applyHash(true);
    window.addEventListener('hashchange', () => applyHash(true));

    buttons.forEach((btn, i) => {
      btn.addEventListener('click', () => {
        show(btn.dataset.view, true);
        try { localStorage.setItem('projects-view', btn.dataset.view); } catch (err) { /* ignore */ }
      });
      btn.addEventListener('keydown', (ev) => {
        const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[ev.key];
        if (!step) return;
        ev.preventDefault();
        const next = buttons[(i + step + buttons.length) % buttons.length];
        next.focus();
        next.click();
      });
    });
  }

  // Resizable divider (desktop): drag, or use the arrow keys when focused
  const minW = 160;
  const setWidth = (px) => {
    const max = win.clientWidth * 0.7;
    win.style.setProperty('--tool-w', Math.round(Math.min(max, Math.max(minW, px))) + 'px');
  };
  split.addEventListener('pointerdown', (ev) => {
    split.setPointerCapture(ev.pointerId);
    const left = win.querySelector('.pt-tool').getBoundingClientRect().left;
    const move = (e) => setWidth(e.clientX - left);
    const up = () => {
      split.removeEventListener('pointermove', move);
      split.removeEventListener('pointerup', up);
    };
    split.addEventListener('pointermove', move);
    split.addEventListener('pointerup', up);
  });
  split.addEventListener('keydown', (ev) => {
    const current = win.querySelector('.pt-tool').getBoundingClientRect().width;
    if (ev.key === 'ArrowLeft') { setWidth(current - 16); ev.preventDefault(); }
    if (ev.key === 'ArrowRight') { setWidth(current + 16); ev.preventDefault(); }
  });

  // Keyboard in the tree: Up/Down move through visible rows, Right opens or steps in, Left closes or steps out
  const rows = () => Array.from(tree.querySelectorAll('summary.it, a.it.file')).filter((el) => el.offsetParent !== null);
  tree.addEventListener('keydown', (ev) => {
    const list = rows();
    const index = list.indexOf(document.activeElement);
    if (index === -1) return;
    const row = list[index];
    const folder = row.tagName === 'SUMMARY' ? row.parentElement : null;
    let target = null;
    switch (ev.key) {
      case 'ArrowDown': target = list[index + 1]; break;
      case 'ArrowUp': target = list[index - 1]; break;
      case 'Home': target = list[0]; break;
      case 'End': target = list[list.length - 1]; break;
      case 'ArrowRight':
        if (folder && !folder.open) folder.open = true;
        else target = list[index + 1];
        break;
      case 'ArrowLeft':
        if (folder && folder.open) folder.open = false;
        else {
          const parent = folder ? folder.parentElement.closest('details') : row.closest('details');
          target = parent && parent.querySelector(':scope > summary');
        }
        break;
      default: return;
    }
    ev.preventDefault();
    if (target) target.focus();
  });
})();

// Floating CV button: shown once the reader has scrolled past most of the hero
(function () {
  const fab = scope.querySelector('.cv-fab');
  const hero = byId('top');
  const toTop = scope.querySelector('.to-top');
  const update = () => {
    const limit = hero ? hero.offsetHeight * 0.6 : 400;
    if (fab) fab.classList.toggle('show', window.scrollY > limit);
    if (toTop) toTop.classList.toggle('show', window.scrollY > 600);
  };
  window.addEventListener('scroll', update, { passive: true });
  update();
})();

// Skill groups (Flutter, AI): each card's own panel slides out beside it. Only that card changes; the others keep their size.
(function () {
  const root = document.documentElement;
  scope.querySelectorAll('.skill-parent').forEach((card) => {
    const head = card.querySelector('.skill-head');
    const panel = card.querySelector('.skill-panel');
    if (!head || !panel) return;

    function setOpen(open) {
      if (open) {
        // Open on the other side when the panel would run past the page edge.
        card.classList.remove('is-flip');
        const r = card.getBoundingClientRect();
        const w = panel.offsetWidth;
        const fits = root.dir === 'rtl' ? r.left - w - 8 >= 0 : r.right + w + 8 <= root.clientWidth;
        card.classList.toggle('is-flip', !fits);
      }
      card.classList.toggle('is-open', open);
      head.setAttribute('aria-expanded', String(open));
    }

    head.addEventListener('click', () => setOpen(!card.classList.contains('is-open')));
    card.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && card.classList.contains('is-open')) {
        setOpen(false);
        head.focus();
      }
    });
  });
})();

// Project link lists: a card with several links opens a small list. Esc, a click outside, or focus leaving the list closes it.
(function () {
  const setOpen = (menu, open) => {
    menu.classList.toggle('is-open', open);
    menu.querySelector('.pcard-menu-btn').setAttribute('aria-expanded', String(open));
    const card = menu.closest('.pcard');
    if (card) card.classList.toggle('menu-open', open);
  };
  const closeAll = (except) => scope.querySelectorAll('.pcard-menu.is-open').forEach((m) => { if (m !== except) setOpen(m, false); });

  scope.querySelectorAll('.pcard-menu').forEach((menu) => {
    const btn = menu.querySelector('.pcard-menu-btn');
    btn.addEventListener('click', (ev) => {
      ev.stopPropagation();
      const open = !menu.classList.contains('is-open');
      closeAll(menu);
      setOpen(menu, open);
      if (open) menu.querySelector('.pcard-menu-list a').focus();
    });
    menu.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape') { setOpen(menu, false); btn.focus(); }
    });
    menu.addEventListener('focusout', (ev) => {
      if (!menu.contains(ev.relatedTarget)) setOpen(menu, false);
    });
  });
  document.addEventListener('click', (ev) => {
    scope.querySelectorAll('.pcard-menu.is-open').forEach((m) => { if (!m.contains(ev.target)) setOpen(m, false); });
  });
})();
}

document.querySelectorAll('.pane').forEach((pane) => initPane(pane, pane.dataset.pane === 'fa' ? '-fa' : ''));

// Language switch without a reload: both languages are on the page and one pane is shown. The reading position (the section in view and the offset inside it) is kept, and the swap cross-fades when the browser allows it. Reduced motion gets an instant swap.
(function () {
  const html = document.documentElement;
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const panes = () => Array.from(document.querySelectorAll('.pane'));
  const activePane = () => panes().find((p) => !p.hidden) || panes()[0];
  const suffixFor = (lang) => (lang === 'fa' ? '-fa' : '');

  const sectionInView = (pane) => {
    const list = Array.from(pane.querySelectorAll('section[id]'));
    const line = window.scrollY + 1;
    let found = list[0] || null;
    list.forEach((sec) => { if (sec.offsetTop <= line) found = sec; });
    return found;
  };

  const showLang = (lang) => {
    panes().forEach((p) => { p.hidden = p.dataset.pane !== lang; });
    html.lang = lang;
    html.dir = lang === 'fa' ? 'rtl' : 'ltr';
    const cap = lang === 'fa' ? 'Fa' : 'En';
    if (html.dataset['title' + cap]) document.title = html.dataset['title' + cap];
    const meta = document.querySelector('meta[name="description"]');
    if (meta && html.dataset['desc' + cap]) meta.setAttribute('content', html.dataset['desc' + cap]);
    try { document.cookie = 'lang=' + lang + '; path=/; max-age=31536000; samesite=lax'; } catch (e) { /* cookies blocked: the choice applies to this page */ }
    try { history.replaceState(null, '', '?lang=' + lang + location.hash); } catch (e) { /* history blocked: the URL keeps its old language */ }
  };

  document.addEventListener('click', (ev) => {
    const link = ev.target.closest('[data-lang-switch]');
    if (!link) return;
    ev.preventDefault();
    const current = activePane().dataset.pane;
    const next = current === 'fa' ? 'en' : 'fa';
    const from = activePane();
    const sec = sectionInView(from);
    const base = sec ? sec.id.replace(/-fa$/, '') : null;
    const offset = sec ? Math.max(0, window.scrollY - sec.offsetTop) : 0;
    const apply = () => {
      showLang(next);
      const target = base ? document.querySelector('.pane[data-pane="' + next + '"] #' + CSS.escape(base + suffixFor(next))) : null;
      if (target) window.scrollTo({ top: target.offsetTop + offset, behavior: 'instant' });
    };
    if (reduce) { apply(); return; }
    if (document.startViewTransition) { document.startViewTransition(apply); return; }
    html.classList.add('lang-leaving');
    setTimeout(() => {
      apply();
      html.classList.remove('lang-leaving');
      html.classList.add('lang-arrive');
      setTimeout(() => html.classList.remove('lang-arrive'), 300);
    }, 160);
  });
})();

// Page scripts run per language pane (see initPane); the switch between panes is at the end of this file.

// Both language panes are live: each runs the same setup against its own elements, with its own id suffix.
function initPane(scope, sfx) {
  const byId = (name) => scope.querySelector('#' + name + sfx);
  // What each part of this pane needs to keep when the language changes: save() reads it, load() applies it in the other pane.
  const sync = { save: {}, load: {} };
  scope.paneSync = sync;
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

  // Language switch: the same folders, tabs, file and drawer state carry over to the other pane
  sync.save.ide = () => ({
    folders: Array.from(tree.querySelectorAll('details')).map((d) => d.open),
    files: Array.from(tabs.keys()).map((id) => id.replace(/-fa$/, '')),
    active: activeId ? activeId.replace(/-fa$/, '') : null,
    drawer: win.classList.contains('tool-open'),
  });
  sync.load.ide = (s) => {
    Array.from(tabs.keys()).forEach((id) => { tabs.get(id).tab.remove(); tabs.delete(id); });
    activeId = null;
    view.replaceChildren();
    tree.querySelectorAll('details').forEach((d, i) => { d.open = !!s.folders[i]; });
    s.files.forEach((base) => {
      const link = tree.querySelector(`a[data-src="${base + sfx}"]`);
      if (link) openFile(link);
    });
    if (s.active && tabs.has(s.active + sfx)) setActive(s.active + sfx);
    win.classList.toggle('tool-open', !!s.drawer);
    if (toggle) toggle.setAttribute('aria-expanded', String(!!s.drawer));
  };

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
    sync.save.view = () => section.dataset.view || 'normal';
    sync.load.view = (name) => show(name, false);
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

// Skill panels keep their open state across a language switch
sync.save.skills = () => Array.from(scope.querySelectorAll('.skill-parent')).map((c) => c.classList.contains('is-open'));
sync.load.skills = (s) => {
  Array.from(scope.querySelectorAll('.skill-parent')).forEach((c, i) => {
    if (c.classList.contains('is-open') !== !!s[i]) c.querySelector('.skill-head')?.click();
  });
};

// Contact button: once the page has scrolled to Contact, the tiles light up one after another in a fixed order (email, phone, GitHub, LinkedIn). Reduced motion gets a short static highlight.
(function () {
  const section = byId('contact');
  if (!section) return;
  const order = ['mailto:', 'tel:', 'github', 'linkedin'];
  const tiles = () => {
    const all = Array.from(section.querySelectorAll('.cc'));
    return order.map((key) => all.find((a) => (a.getAttribute('href') || '').includes(key))).filter(Boolean);
  };
  const run = () => {
    const list = tiles();
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    list.forEach((el, i) => {
      el.classList.remove('cc-glow', 'cc-static');
      void el.offsetWidth;
      if (reduced) {
        el.classList.add('cc-static');
        setTimeout(() => el.classList.remove('cc-static'), 1200);
        return;
      }
      el.style.setProperty('--glow-delay', (i * 450) + 'ms');
      el.classList.add('cc-glow');
      el.addEventListener('animationend', () => el.classList.remove('cc-glow'), { once: true });
    });
  };
  scope.querySelectorAll('a[href="#contact' + sfx + '"]').forEach((link) => {
    link.addEventListener('click', () => {
      let timer = null;
      const start = () => {
        window.removeEventListener('scrollend', start);
        clearTimeout(timer);
        run();
      };
      window.addEventListener('scrollend', start);
      timer = setTimeout(start, 1000);
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
    if (ev.target.closest('[data-lang-choice]')) return;
    scope.querySelectorAll('.pcard-menu.is-open').forEach((m) => { if (!m.contains(ev.target)) setOpen(m, false); });
  });
  sync.save.menus = () => Array.from(scope.querySelectorAll('.pcard-menu')).map((m) => m.classList.contains('is-open'));
  sync.load.menus = (s) => scope.querySelectorAll('.pcard-menu').forEach((m, i) => setOpen(m, !!s[i]));
})();
}

// A copy of the English pane before any pane script runs: the binary pane is built from it.
const pristineEn = document.querySelector('.pane[data-pane="en"]').cloneNode(true);
document.querySelectorAll('.pane').forEach((pane) => initPane(pane, pane.dataset.pane === 'fa' ? '-fa' : ''));

// Language switch without a reload: Persian, English and Binary are panes in one page; one is shown. The reading position (the section in view and the offset inside it) is kept, and the swap cross-fades when the browser allows it. Reduced motion gets an instant swap.
(function () {
  const html = document.documentElement;
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const panes = () => Array.from(document.querySelectorAll('.pane'));
  const activePane = () => panes().find((p) => !p.hidden) || panes()[0];
  const suffixFor = (lang) => (lang === 'fa' ? '-fa' : lang === 'bin' ? '-bin' : '');

  const sectionInView = (pane) => {
    const list = Array.from(pane.querySelectorAll('section[id]'));
    const line = window.scrollY + 1;
    let found = list[0] || null;
    list.forEach((sec) => { if (sec.offsetTop <= line) found = sec; });
    return found;
  };

  // Binary pane: a copy of the English pane. Each piece of text is written as the UTF-8 bytes of the words, 8 bits per byte, so it decodes back to the same text. The IDE window, the menus and the form controls keep their English text.
  const BINARY_SKIP = '.pt-window, .lang-menu, .tobank-link, .bin-scan, script, style, noscript, svg, textarea, input, select, option';
  const encodeBinary = (text) => Array.from(new TextEncoder().encode(text), (b) => b.toString(2).padStart(8, '0')).join(' ');
  const suffixIds = (root, sfx) => {
    root.querySelectorAll('[id]').forEach((el) => { el.id = el.id + sfx; });
    ['for', 'aria-controls', 'aria-labelledby', 'aria-describedby', 'data-src'].forEach((attr) => {
      root.querySelectorAll('[' + attr + ']').forEach((el) => el.setAttribute(attr, el.getAttribute(attr) + sfx));
    });
    root.querySelectorAll('[href^="#"]').forEach((el) => el.setAttribute('href', el.getAttribute('href') + sfx));
  };
  // The cloud of 0 and 1 over the name: random digits, shown only in binary mode
  const cloudText = (rows, cols) => Array.from({ length: rows }, () => Array.from({ length: cols }, () => (Math.random() < 0.5 ? '0' : '1')).join('')).join('\n');
  let binaryPane = null;
  const buildBinaryPane = () => {
    if (binaryPane) return binaryPane;
    const pane = pristineEn.cloneNode(true);
    pane.dataset.pane = 'bin';
    pane.lang = 'en';
    pane.dir = 'ltr';
    pane.hidden = true;
    suffixIds(pane, '-bin');
    const walker = document.createTreeWalker(pane, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach((node) => {
      if (!node.parentElement || node.parentElement.closest(BINARY_SKIP)) return;
      // Whitespace-only nodes are layout gaps between tags, not content; encoding them only adds stray 00100000 rows
      if (!node.nodeValue.trim()) return;
      node.nodeValue = encodeBinary(node.nodeValue);
    });
    const h1 = pane.querySelector('h1');
    if (h1) {
      const cloud = document.createElement('span');
      cloud.className = 'bin-cloud';
      cloud.setAttribute('aria-hidden', 'true');
      cloud.textContent = cloudText(5, 56);
      h1.appendChild(cloud);
    }
    document.querySelector('.pane[data-pane="en"]').after(pane);
    pane.querySelectorAll('.lang-label').forEach((el) => { el.textContent = 'Language'; });
    binaryPane = pane;
    initPane(pane, '-bin');
    return pane;
  };

  // Binary mode extras: a terminal error window now and then, short glitch bursts, and a heavier wheel.
  // Nothing blocks the page: the window closes with OK, the close button, Enter or Escape, and everything stops when another language is shown.
  const binaryFun = (() => {
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
    let timers = [];
    let running = false;
    let win = null;
    let target = null;
    let raf = 0;
    const later = (fn, ms) => {
      const id = setTimeout(() => { timers = timers.filter((t) => t !== id); fn(); }, ms);
      timers.push(id);
    };
    let staticEl = null;
    const glitch = () => {
      document.body.classList.add('bin-glitch');
      if (staticEl) staticEl.classList.add('on');
      later(() => {
        document.body.classList.remove('bin-glitch');
        if (staticEl) staticEl.classList.remove('on');
      }, 260);
    };
    const closeWin = () => { if (win) { win.remove(); win = null; } };
    let screen404 = null;
    const close404 = () => { if (screen404) { screen404.remove(); screen404 = null; } };
    // A full-screen "404" that looks like the site crashed. It shows once per visit to binary mode, for about a second, and can be skipped.
    const show404 = () => {
      if (!running || screen404) return;
      screen404 = document.createElement('div');
      screen404.className = 'bin-404';
      screen404.setAttribute('role', 'status');
      screen404.innerHTML = '<pre class="bin-404-text">SEGMENTATION FAULT\n0x7F3A  layout.bin corrupted\n\n404  PAGE NOT FOUND\n\nrebooting display...</pre>';
      document.body.appendChild(screen404);
      screen404.addEventListener('click', close404);
      later(close404, 1000);
    };
    const openWin = () => {
      if (win || !running) return;
      win = document.createElement('div');
      win.className = 'bin-error';
      win.setAttribute('role', 'alertdialog');
      win.setAttribute('aria-label', 'ERROR.EXE');
      win.innerHTML = '<div class="bin-error-bar"><span>ERROR.EXE</span><button type="button" class="bin-error-x" aria-label="Close">×</button></div>'
        + '<pre class="bin-error-body">FATAL: unexpected 0 and 1 in the layout buffer\n> scroll buffer overflow at 0x7F3A\n> reading the page again...\n\n[ OK ]  press Enter to continue</pre>'
        + '<button type="button" class="bin-error-ok">OK</button>';
      document.body.appendChild(win);
    };
    const onWheel = (ev) => {
      if (ev.ctrlKey || ev.metaKey || ev.defaultPrevented) return;
      if (ev.target.closest && ev.target.closest('.pt-window, .lang-list, .bin-game, textarea')) return;
      ev.preventDefault();
      const max = document.documentElement.scrollHeight - window.innerHeight;
      if (target === null) target = window.scrollY;
      target = Math.min(max, Math.max(0, target + ev.deltaY * 0.45));
      if (!raf) raf = requestAnimationFrame(step);
    };
    const step = () => {
      const cur = window.scrollY;
      const next = cur + (target - cur) * 0.16;
      if (Math.abs(target - next) < 0.5) {
        window.scrollTo({ top: target, behavior: 'instant' });
        raf = 0;
        target = null;
        return;
      }
      window.scrollTo({ top: next, behavior: 'instant' });
      raf = requestAnimationFrame(step);
    };
    const start = () => {
      stop();
      running = true;
      const burst = () => {
        if (!running) return;
        // While the runner game is open the static is skipped, so the game stays readable
        if (!reduce.matches && !document.querySelector('.bin-game')) glitch();
        later(burst, 1700 + Math.random() * 600);
      };
      if (!staticEl) {
        staticEl = document.createElement('div');
        staticEl.className = 'bin-static';
        staticEl.setAttribute('aria-hidden', 'true');
        document.body.appendChild(staticEl);
      }
      const popup = () => {
        if (!running) return;
        openWin();
        later(popup, 20000 + Math.random() * 15000);
      };
      later(burst, 2500);
      later(popup, 7000);
      later(show404, 6000);
      if (!reduce.matches) window.addEventListener('wheel', onWheel, { passive: false });
    };
    const stop = () => {
      running = false;
      timers.forEach((id) => clearTimeout(id));
      timers = [];
      closeWin();
      close404();
      document.body.classList.remove('bin-glitch');
      if (staticEl) { staticEl.remove(); staticEl = null; }
      window.removeEventListener('wheel', onWheel);
      if (raf) cancelAnimationFrame(raf);
      raf = 0;
      target = null;
    };
    document.addEventListener('click', (ev) => {
      if (win && ev.target.closest('.bin-error-ok, .bin-error-x')) closeWin();
    });
    document.addEventListener('keydown', (ev) => {
      if (win && (ev.key === 'Enter' || ev.key === 'Escape')) closeWin();
    });
    return { start, stop };
  })();

  // Binary mode mini game: a skeleton runs over blocks of 0 and 1, like the offline dinosaur game.
  // A floating play button opens it. Space, ArrowUp or a tap jumps; it closes with the × button or Escape.
  const binaryGame = (() => {
    const W = 600;
    const H = 180;
    const GROUND = 160;
    const STAND = GROUND - 40;
    const GRAV = 0.6;
    const JUMP = -11.5;
    const SKE_X = 60;
    let button = null;
    let panel = null;
    let canvas = null;
    let ctx = null;
    let raf = 0;
    let last = 0;
    let open = false;
    let best = 0;
    let state = 'ready';
    let y = STAND;
    let vy = 0;
    let obstacles = [];
    let frame = 0;
    let speed = 5;
    let nextSpawn = 60;
    let groundOffset = 0;
    const reset = () => {
      state = 'ready';
      y = STAND;
      vy = 0;
      obstacles = [];
      frame = 0;
      speed = 5;
      nextSpawn = 60;
      groundOffset = 0;
    };
    const score = () => Math.floor(frame / 6);
    const jump = () => {
      if (state === 'ready') { state = 'run'; return; }
      if (state === 'over') { reset(); state = 'run'; return; }
      if (y >= STAND) vy = JUMP;
    };
    const gameOver = () => {
      state = 'over';
      best = Math.max(best, score());
    };
    const spawn = () => {
      const cells = 1 + Math.floor(Math.random() * 3);
      const rows = Math.random() < 0.3 ? 2 : 1;
      const digits = Array.from({ length: cells * rows }, () => (Math.random() < 0.5 ? '0' : '1'));
      obstacles.push({ x: W + 10, cells, rows, digits, w: cells * 24, h: rows * 24 });
    };
    const hits = (o) => {
      const sx = SKE_X + 4;
      const sy = y + 4;
      return sx < o.x + o.w && sx + 14 > o.x && sy < GROUND && sy + 32 > GROUND - o.h;
    };
    const update = (k) => {
      frame += k;
      groundOffset = (groundOffset + speed * k) % 20;
      vy += GRAV * k;
      y += vy * k;
      if (y >= STAND) { y = STAND; vy = 0; }
      speed = Math.min(12, 5 + frame / 900);
      nextSpawn -= k;
      if (nextSpawn <= 0) {
        spawn();
        nextSpawn = Math.max(35, 55 + Math.random() * 60 - frame / 60);
      }
      obstacles.forEach((o) => { o.x -= speed * k; });
      obstacles = obstacles.filter((o) => o.x + o.w > -10);
      if (obstacles.some(hits)) gameOver();
    };
    const drawSkeleton = () => {
      const x = SKE_X;
      const cx = x + 11;
      const airborne = y < STAND;
      const swing = airborne ? 0 : Math.sin(frame * 0.35);
      ctx.strokeStyle = '#39ff7a';
      ctx.fillStyle = '#39ff7a';
      ctx.lineWidth = 2;
      ctx.beginPath();
      ctx.arc(cx, y + 8, 7, 0, Math.PI * 2);
      ctx.stroke();
      ctx.fillRect(cx - 4, y + 6, 3, 3);
      ctx.fillRect(cx + 1, y + 6, 3, 3);
      ctx.beginPath();
      ctx.moveTo(cx, y + 15);
      ctx.lineTo(cx, y + 28);
      ctx.moveTo(cx, y + 19);
      ctx.lineTo(cx + 8 + swing * 2, y + 26);
      ctx.moveTo(cx, y + 19);
      ctx.lineTo(cx - 8 - swing * 2, y + 26);
      ctx.moveTo(cx, y + 28);
      if (airborne) {
        ctx.lineTo(cx - 5, y + 36);
        ctx.moveTo(cx, y + 28);
        ctx.lineTo(cx + 5, y + 36);
      } else {
        ctx.lineTo(cx - 5 + swing * 4, y + 40);
        ctx.moveTo(cx, y + 28);
        ctx.lineTo(cx + 5 - swing * 4, y + 40);
      }
      ctx.stroke();
    };
    const draw = () => {
      ctx.fillStyle = '#02140a';
      ctx.fillRect(0, 0, W, H);
      ctx.strokeStyle = '#134d26';
      ctx.lineWidth = 1;
      ctx.setLineDash([6, 8]);
      ctx.lineDashOffset = -groundOffset;
      ctx.beginPath();
      ctx.moveTo(0, GROUND + 0.5);
      ctx.lineTo(W, GROUND + 0.5);
      ctx.stroke();
      ctx.setLineDash([]);
      ctx.font = '600 14px ui-monospace, Menlo, Consolas, monospace';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      obstacles.forEach((o) => {
        for (let r = 0; r < o.rows; r += 1) {
          for (let c = 0; c < o.cells; c += 1) {
            const cx = o.x + c * 24;
            const cy = GROUND - (r + 1) * 24;
            ctx.strokeStyle = '#39ff7a';
            ctx.strokeRect(cx + 0.5, cy + 0.5, 23, 23);
            ctx.fillStyle = '#39ff7a';
            ctx.fillText(o.digits[r * o.cells + c], cx + 12, cy + 13);
          }
        }
      });
      drawSkeleton();
      ctx.fillStyle = '#39ff7a';
      ctx.textAlign = 'right';
      ctx.textBaseline = 'top';
      ctx.font = '500 13px ui-monospace, Menlo, Consolas, monospace';
      const pad = (n) => String(n).padStart(5, '0');
      ctx.fillText(`HI ${pad(best)}  ${pad(score())}`, W - 12, 10);
      if (state !== 'run') {
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.font = '600 15px ui-monospace, Menlo, Consolas, monospace';
        const msg = state === 'over' ? 'GAME OVER  //  SPACE OR TAP TO RETRY' : 'SPACE OR TAP TO START';
        ctx.fillText(msg, W / 2, 60);
      }
    };
    const loop = (t) => {
      const k = Math.min(3, (t - last) / 16.67 || 1);
      last = t;
      if (state === 'run') update(k);
      draw();
      raf = requestAnimationFrame(loop);
    };
    const onKey = (ev) => {
      if (ev.key === 'Escape') { close(); return; }
      if (ev.key === ' ' || ev.key === 'ArrowUp') {
        ev.preventDefault();
        jump();
      }
    };
    const closePanel = () => {
      if (!open) return;
      open = false;
      cancelAnimationFrame(raf);
      raf = 0;
      document.removeEventListener('keydown', onKey);
      if (panel) { panel.remove(); panel = null; }
      canvas = null;
      ctx = null;
      if (button) button.hidden = false;
    };
    function close() { closePanel(); }
    const openPanel = () => {
      if (open) return;
      open = true;
      reset();
      panel = document.createElement('div');
      panel.className = 'bin-game';
      panel.setAttribute('role', 'dialog');
      panel.setAttribute('aria-label', 'SKELETON.RUN');
      panel.innerHTML = '<div class="bin-game-bar"><span>SKELETON.RUN</span><button type="button" class="bin-game-x" aria-label="Close">×</button></div>'
        + '<canvas class="bin-game-canvas" width="' + W + '" height="' + H + '" aria-label="Game canvas"></canvas>'
        + '<div class="bin-game-foot"><span>SPACE / ARROW UP / TAP  to jump</span><span>ESC  to close</span></div>';
      document.body.appendChild(panel);
      canvas = panel.querySelector('canvas');
      const dpr = Math.min(2, window.devicePixelRatio || 1);
      canvas.width = W * dpr;
      canvas.height = H * dpr;
      ctx = canvas.getContext('2d');
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      canvas.addEventListener('pointerdown', (ev) => { ev.preventDefault(); jump(); });
      panel.querySelector('.bin-game-x').addEventListener('click', closePanel);
      if (button) button.hidden = true;
      document.addEventListener('keydown', onKey);
      last = performance.now();
      raf = requestAnimationFrame(loop);
    };
    const start = () => {
      if (button) return;
      button = document.createElement('button');
      button.type = 'button';
      button.className = 'bin-play';
      button.setAttribute('aria-label', 'Play');
      button.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M7 4.5v15l12.5-7.5z" fill="currentColor"/></svg>';
      button.addEventListener('click', openPanel);
      document.body.appendChild(button);
    };
    const stop = () => {
      closePanel();
      if (button) { button.remove(); button = null; }
    };
    return { start, stop };
  })();

  const showLang = (lang) => {
    if (lang === 'bin') buildBinaryPane();
    if (lang === 'bin') { binaryFun.start(); binaryGame.start(); }
    else { binaryFun.stop(); binaryGame.stop(); }
    panes().forEach((p) => { p.hidden = p.dataset.pane !== lang; });
    html.lang = lang === 'fa' ? 'fa' : 'en';
    html.dir = lang === 'fa' ? 'rtl' : 'ltr';
    if (lang === 'bin') html.dataset.mode = 'binary';
    else delete html.dataset.mode;
    const cap = lang === 'fa' ? 'Fa' : 'En';
    if (html.dataset['title' + cap]) document.title = html.dataset['title' + cap];
    const meta = document.querySelector('meta[name="description"]');
    if (meta && html.dataset['desc' + cap]) meta.setAttribute('content', html.dataset['desc' + cap]);
    try { document.cookie = 'lang=' + lang + '; path=/; max-age=31536000; samesite=lax'; } catch (e) { /* cookies blocked: the choice applies to this page */ }
    try { history.replaceState(null, '', '?lang=' + lang + location.hash); } catch (e) { /* history blocked: the URL keeps its old language */ }
    document.querySelectorAll('[data-lang-choice]').forEach((a) => {
      if (a.dataset.langChoice === lang) a.setAttribute('aria-current', 'true');
      else a.removeAttribute('aria-current');
    });
  };

  // Reads the state the hidden pane saves now, and returns a function that applies it to the pane being shown
  const carry = (fromPane, lang) => {
    const saved = {};
    Object.entries(fromPane.paneSync ? fromPane.paneSync.save : {}).forEach(([key, read]) => { saved[key] = read(); });
    const toPane = panes().find((p) => p.dataset.pane === lang);
    return () => {
      if (!toPane || !toPane.paneSync) return;
      const keys = Object.keys(saved).sort((a) => (a === 'view' ? -1 : 0));
      keys.forEach((key) => { if (toPane.paneSync.load[key]) toPane.paneSync.load[key](saved[key]); });
    };
  };

  const switchTo = (lang) => {
    const from = activePane();
    if (from.dataset.pane === lang) return;
    if (lang === 'bin') buildBinaryPane();
    const sec = sectionInView(from);
    const base = sec ? sec.id.replace(/-(fa|bin)$/, '') : null;
    // The position is kept as a share of the section: the binary text is taller than the others
    const share = sec ? Math.min(1, Math.max(0, (window.scrollY - sec.offsetTop) / Math.max(1, sec.offsetHeight))) : 0;
    const carried = carry(from, lang);
    const apply = () => {
      showLang(lang);
      carried();
      const target = base ? document.querySelector('.pane[data-pane="' + lang + '"] #' + CSS.escape(base + suffixFor(lang))) : null;
      if (target) window.scrollTo({ top: target.offsetTop + share * target.offsetHeight, behavior: 'instant' });
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
  };

  // Language menu: a button that opens the list of three choices. Escape, a click outside, or a choice closes it.
  const closeMenus = (except) => document.querySelectorAll('.lang-menu').forEach((m) => {
    if (m === except) return;
    const list = m.querySelector('.lang-list');
    const btn = m.querySelector('.lang-btn');
    if (list) list.hidden = true;
    if (btn) btn.setAttribute('aria-expanded', 'false');
  });
  document.addEventListener('click', (ev) => {
    const btn = ev.target.closest('.lang-btn');
    if (btn) {
      const menu = btn.closest('.lang-menu');
      const list = menu.querySelector('.lang-list');
      const open = list.hidden;
      closeMenus(menu);
      list.hidden = !open;
      btn.setAttribute('aria-expanded', String(open));
      return;
    }
    const projectLink = ev.target.closest('[data-project-link]');
    if (projectLink) {
      // Jump to the projects section in the visible language pane and highlight that project's card
      ev.preventDefault();
      const pane = projectLink.closest('.pane') || document;
      const found = pane.querySelector(`.pcard[data-project="${projectLink.dataset.projectLink}"]`);
      const card = found && !found.hidden ? found : null;
      const target = card || pane.querySelector('#projects');
      if (!target) return;
      const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      target.scrollIntoView({ block: 'center', behavior: reduce ? 'auto' : 'smooth' });
      if (card) {
        card.classList.add('is-focus');
        setTimeout(() => card.classList.remove('is-focus'), 1800);
      }
      return;
    }
    const choice = ev.target.closest('[data-lang-choice]');
    if (choice) {
      ev.preventDefault();
      closeMenus();
      switchTo(choice.dataset.langChoice);
      return;
    }
    if (!ev.target.closest('.lang-menu')) closeMenus();
  });
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') closeMenus(); });

  // The choice is kept in the cookie: binary comes back on the next visit
  const saved = (document.cookie.match(/(?:^|; )lang=([^;]+)/) || [])[1];
  if (saved === 'bin') showLang('bin');
})();

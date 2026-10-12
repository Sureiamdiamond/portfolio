// Progressive enhancement: the site works without this file.
(function () {
  const root = document.documentElement;
  const systemDark = window.matchMedia('(prefers-color-scheme: dark)');

  // Theme toggle: remembers the choice, otherwise follows the system preference
  const toggle = document.getElementById('theme-toggle');
  const effectiveTheme = () => root.dataset.theme || (systemDark.matches ? 'dark' : 'light');
  if (toggle) {
    toggle.addEventListener('click', () => {
      const next = effectiveTheme() === 'dark' ? 'light' : 'dark';
      root.dataset.theme = next;
      try { localStorage.setItem('theme', next); } catch (e) { /* storage blocked: keep it for this page only */ }
    });
  }

  // Project filters
  const buttons = document.querySelectorAll('.filters button');
  buttons.forEach((btn) => {
    btn.addEventListener('click', () => {
      const kind = btn.dataset.filter;
      buttons.forEach((b) => b.classList.toggle('on', b === btn));
      document.querySelectorAll('#projects .card').forEach((card) => {
        card.hidden = kind !== 'all' && card.dataset.kind !== kind;
      });
    });
  });

  // Pointer-driven light sheen and slight tilt on glass cards
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduce) return;
  document.querySelectorAll('.card, .row').forEach((el) => {
    el.addEventListener('pointermove', (ev) => {
      const r = el.getBoundingClientRect();
      const x = ev.clientX - r.left;
      const y = ev.clientY - r.top;
      el.style.setProperty('--mx', x + 'px');
      el.style.setProperty('--my', y + 'px');
      if (el.classList.contains('card')) {
        const rx = ((y / r.height) - 0.5) * -6;
        const ry = ((x / r.width) - 0.5) * 6;
        el.style.transform = `perspective(800px) rotateX(${rx}deg) rotateY(${ry}deg)`;
      }
    });
    el.addEventListener('pointerleave', () => { el.style.transform = ''; });
  });
})();
